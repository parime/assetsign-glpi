<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #174 : le materiel remis avec (ecran, casque...) est aussi attribue dans GLPI au
 * beneficiaire de la feuille - champ Utilisateur et statut.
 */
final class AssignHandedItemsTest extends AssetsignTestCase
{
    private int $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = $this->createTestState('PHPUnit en service');
        $config = Config::getForEntity(0);
        $config->update([
            'id'                  => $config->getID(),
            'assign_handed_items' => 1,
            'handed_items_state'  => Config::HANDED_STATE_SAME_AS_MAIN,
            'sign_on_assignment'  => 1,
            'managed_itemtypes'   => json_encode(['Computer', 'Monitor', 'Peripheral', 'Phone']),
        ]);
    }

    private function draftWithMonitor(int $beneficiary, array $monitorFields = []): array
    {
        $computer = new \Computer();
        $computer->getFromDB((int) $computer->add(['name' => 'PHPUNIT-PC', 'entities_id' => 0, 'states_id' => $this->state]));
        $monitor = new \Monitor();
        $monitor->getFromDB((int) $monitor->add($monitorFields + ['name' => 'PHPUNIT-ECRAN', 'entities_id' => 0]));

        $sheet = new Assetsign();
        $sheet->getFromDB((int) $sheet->add([
            'entities_id' => 0,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => $beneficiary,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => Assetsign::STATUS_DRAFT,
        ]));
        AssetsignItem::attach($sheet, 'Monitor', $monitor->getID());

        return [$sheet, $monitor];
    }

    private function sheetsForMonitor(\Monitor $monitor): int
    {
        return count((new Assetsign())->find(['itemtype' => 'Monitor', 'items_id' => $monitor->getID()]));
    }

    public function testSendingAssignsTheMonitorWithTheStateOfTheMainEquipment(): void
    {
        $beneficiary = $this->createTestUser('Remise', 'Ecran');
        [$sheet, $monitor] = $this->draftWithMonitor($beneficiary);

        $monitor->getFromDB($monitor->getID());
        $this->assertSame(0, (int) $monitor->fields['users_id'], 'brouillon : rien ne change encore');

        $sheet->sendDraft();

        $monitor->getFromDB($monitor->getID());
        $this->assertSame($beneficiary, (int) $monitor->fields['users_id']);
        $this->assertSame($this->state, (int) $monitor->fields['states_id']);
        $this->assertSame(0, $this->sheetsForMonitor($monitor), 'pas de feuille separee pour l\'ecran');
    }

    public function testGlpiCopyingTheUserToConnectedItemsCreatesNoExtraSheet(): void
    {
        global $DB;
        // Option d'entite GLPI : recopie de l'utilisateur du poste vers ses elements connectes.
        $DB->update('glpi_entities', ['is_user_autoupdate' => 1], ['id' => 0]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $monitor = new \Monitor();
        $monitor->getFromDB((int) $monitor->add(['name' => 'PHPUNIT-ECRAN', 'entities_id' => 0]));
        (new \Glpi\Asset\Asset_PeripheralAsset())->add([
            'itemtype_asset' => 'Computer', 'items_id_asset' => $computer->getID(),
            'itemtype_peripheral' => 'Monitor', 'items_id_peripheral' => $monitor->getID(),
        ]);
        $beneficiary = $this->createTestUser('Poste', 'Complet');

        $computer->update(['id' => $computer->getID(), 'users_id' => $beneficiary]);

        $monitor->getFromDB($monitor->getID());
        $this->assertSame($beneficiary, (int) $monitor->fields['users_id'], 'recopie faite par GLPI');
        $this->assertSame(0, $this->sheetsForMonitor($monitor), 'couvert par la feuille du poste');
        $this->assertCount(1, (new Assetsign())->find(['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
    }

    public function testAnItemOfSomeoneElseIsReassignedToTheBeneficiary(): void
    {
        $previous = $this->createTestUser('Ancien', 'Detenteur');
        $beneficiary = $this->createTestUser('Nouveau', 'Detenteur');
        [$sheet, $monitor] = $this->draftWithMonitor($beneficiary, ['users_id' => $previous]);

        $sheet->sendDraft();

        $monitor->getFromDB($monitor->getID());
        $this->assertSame($beneficiary, (int) $monitor->fields['users_id']);
        $this->assertSame(0, $this->sheetsForMonitor($monitor));
    }

    public function testStateCanBeLeftUnchangedOrForced(): void
    {
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'handed_items_state' => Config::HANDED_STATE_UNCHANGED]);
        [$sheet, $monitor] = $this->draftWithMonitor($this->createTestUser('Statut', 'Inchange'));
        $sheet->sendDraft();
        $monitor->getFromDB($monitor->getID());
        $this->assertSame(0, (int) $monitor->fields['states_id']);

        $forced = $this->createTestState('PHPUnit remis');
        $config->update(['id' => $config->getID(), 'handed_items_state' => $forced]);
        [$sheet2, $monitor2] = $this->draftWithMonitor($this->createTestUser('Statut', 'Force'));
        $sheet2->sendDraft();
        $monitor2->getFromDB($monitor2->getID());
        $this->assertSame($forced, (int) $monitor2->fields['states_id']);
    }

    public function testDisabledSettingLeavesGlpiUntouched(): void
    {
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'assign_handed_items' => 0]);
        [$sheet, $monitor] = $this->draftWithMonitor($this->createTestUser('Sans', 'Attribution'));

        $sheet->sendDraft();

        $monitor->getFromDB($monitor->getID());
        $this->assertSame(0, (int) $monitor->fields['users_id']);
    }

    public function testAddingToAnAlreadySentSheetAssignsRightAway(): void
    {
        $beneficiary = $this->createTestUser('Ajout', 'Tardif');
        [$sheet] = $this->draftWithMonitor($beneficiary);
        $sheet->sendDraft();
        $sheet->getFromDB($sheet->getID());

        $headset = new \Peripheral();
        $headset->getFromDB((int) $headset->add(['name' => 'PHPUNIT-CASQUE', 'entities_id' => 0]));
        $this->assertNull($sheet->addInventoriedItem('Peripheral', $headset->getID()));

        $headset->getFromDB($headset->getID());
        $this->assertSame($beneficiary, (int) $headset->fields['users_id']);
    }
}
