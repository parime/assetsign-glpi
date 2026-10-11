<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #178 : la restitution reprend le materiel remis avec le poste, et le controle du retour
 * (rendu / manquant / abime) met GLPI a jour.
 */
final class ReturnCheckTest extends AssetsignTestCase
{
    private int $stock;
    private int $defective;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = $this->createTestState('PHPUnit en stock');
        $this->defective = $this->createTestState('PHPUnit defectueux');
        $config = Config::getForEntity(0);
        $config->update([
            'id'                    => $config->getID(),
            'sign_on_return'        => 1,
            'confirm_before_sending' => Config::SENDING_IMMEDIATE,
            'return_returned_state' => $this->stock,
            'return_damaged_state'  => $this->defective,
            'return_missing_ticket' => 1,
            'assign_handed_items'   => 0,
        ]);
    }

    /** Poste attribue avec une feuille d'attribution au statut donne, portant un ecran et un casque. */
    private function handedOver(int $status): array
    {
        $beneficiary = $this->createTestUser('Retour', 'Materiel');
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $monitor = (int) (new \Monitor())->add(['name' => 'PHPUNIT-ECRAN', 'entities_id' => 0, 'users_id' => $beneficiary]);
        $headset = (int) (new \Peripheral())->add(['name' => 'PHPUNIT-CASQUE', 'entities_id' => 0, 'users_id' => $beneficiary]);
        global $DB;
        $DB->update(\Computer::getTable(), ['users_id' => $beneficiary], ['id' => $computer->getID()]);
        $computer->getFromDB($computer->getID());

        $handover = new Assetsign();
        $handover->getFromDB((int) $handover->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
            'users_id' => $beneficiary, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
        ]));
        AssetsignItem::attach($handover, 'Monitor', $monitor);
        AssetsignItem::attach($handover, 'Peripheral', $headset);
        $DB->update(Assetsign::getTable(), ['status' => $status], ['id' => $handover->getID()]);

        return [$computer, $monitor, $headset, $beneficiary];
    }

    private function returnSheet(\Computer $computer): Assetsign
    {
        $sheet = new Assetsign();
        $this->assertTrue($sheet->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Assetsign::TYPE_RETURN]));
        return $sheet;
    }

    public function testTheReturnTakesBackTheItemsOfTheSignedHandover(): void
    {
        [$computer] = $this->handedOver(Assetsign::STATUS_SIGNED);

        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);

        $names = array_column(AssetsignItem::getForAssetsign($this->returnSheet($computer)->getID()), 'name');
        sort($names);
        $this->assertSame(['PHPUNIT-CASQUE', 'PHPUNIT-ECRAN'], $names);
    }

    public function testItAlsoTakesBackASentButUnsignedHandover(): void
    {
        [$computer] = $this->handedOver(Assetsign::STATUS_SENT);

        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);

        $this->assertCount(2, AssetsignItem::getForAssetsign($this->returnSheet($computer)->getID()));
    }

    public function testADraftHandoverIsNotTakenBack(): void
    {
        [$computer] = $this->handedOver(Assetsign::STATUS_DRAFT);

        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);

        $this->assertSame([], AssetsignItem::getForAssetsign($this->returnSheet($computer)->getID()), 'jamais envoyee : rien remis');
    }

    public function testTheCheckUpdatesGlpiAndOpensATicketForAMissingItem(): void
    {
        [$computer, $monitor, $headset, $beneficiary] = $this->handedOver(Assetsign::STATUS_SIGNED);
        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);
        $sheet = $this->returnSheet($computer);
        $rows = [];
        foreach (AssetsignItem::getForAssetsign($sheet->getID()) as $row) {
            $rows[$row['name']] = (int) $row['id'];
        }

        $summary = $sheet->saveReturnCheck(
            [$rows['PHPUNIT-ECRAN'] => AssetsignItem::RETURN_RETURNED, $rows['PHPUNIT-CASQUE'] => AssetsignItem::RETURN_MISSING],
            [$rows['PHPUNIT-CASQUE'] => 'Oublié au domicile']
        );

        $this->assertSame(1, $summary['returned']);
        $this->assertSame(1, $summary['tickets']);
        $screen = new \Monitor();
        $screen->getFromDB($monitor);
        $this->assertSame(0, (int) $screen->fields['users_id']);
        $this->assertSame($this->stock, (int) $screen->fields['states_id']);
        $headsetItem = new \Peripheral();
        $headsetItem->getFromDB($headset);
        $this->assertSame($beneficiary, (int) $headsetItem->fields['users_id'], 'manquant : reste a la personne');
        $this->assertCount(1, (new \Item_Ticket())->find(['itemtype' => 'Peripheral', 'items_id' => $headset]));

        // Nouvel enregistrement sans changement : pas de second ticket.
        $sheet->saveReturnCheck([$rows['PHPUNIT-CASQUE'] => AssetsignItem::RETURN_MISSING]);
        $this->assertCount(1, (new \Item_Ticket())->find(['itemtype' => 'Peripheral', 'items_id' => $headset]));
    }

    public function testADamagedItemGetsTheDamagedState(): void
    {
        [$computer, $monitor] = $this->handedOver(Assetsign::STATUS_SIGNED);
        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);
        $sheet = $this->returnSheet($computer);
        $row = array_values(array_filter(AssetsignItem::getForAssetsign($sheet->getID()), static fn ($r) => $r['name'] === 'PHPUNIT-ECRAN'))[0];

        $sheet->saveReturnCheck([(int) $row['id'] => AssetsignItem::RETURN_DAMAGED]);

        $screen = new \Monitor();
        $screen->getFromDB($monitor);
        $this->assertSame($this->defective, (int) $screen->fields['states_id']);
        $this->assertSame(0, (int) $screen->fields['users_id']);
    }

    public function testASignedReturnCanNoLongerBeChanged(): void
    {
        [$computer] = $this->handedOver(Assetsign::STATUS_SIGNED);
        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);
        $sheet = $this->returnSheet($computer);
        $sheet->update(['id' => $sheet->getID(), 'status' => Assetsign::STATUS_SIGNED]);
        $sheet->getFromDB($sheet->getID());

        $this->expectException(\RuntimeException::class);
        $sheet->saveReturnCheck([]);
    }
}
