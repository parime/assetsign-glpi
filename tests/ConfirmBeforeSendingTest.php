<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #170 : feuille d'attribution creee en brouillon, confirmee (et completee) dans une fenetre
 * avant tout envoi.
 */
final class ConfirmBeforeSendingTest extends AssetsignTestCase
{
    private function draftFor(\Computer $computer): Assetsign
    {
        return $this->createBareAssetsignFor($computer, Assetsign::STATUS_DRAFT);
    }

    private function createBareAssetsignFor(\Computer $computer, int $status): Assetsign
    {
        $assetsign = new Assetsign();
        $assetsign->getFromDB((int) $assetsign->add([
            'entities_id' => 0,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => 2,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => $status,
        ]));
        return $assetsign;
    }

    private function monitor(string $name): int
    {
        return (int) (new \Monitor())->add(['name' => $name, 'entities_id' => 0]);
    }

    public function testEnabledByDefault(): void
    {
        global $DB;
        $column = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_assetsign_configs` LIKE 'confirm_before_sending'")->fetch_assoc();
        $this->assertSame('1', (string) $column['Default']);
    }

    public function testSendingADraftKeepsOnlyTheCheckedItemsAndLaunchesTheWorkflow(): void
    {
        $draft = $this->draftFor($this->createTestComputer(0, 'PHPUNIT-PC'));
        AssetsignItem::attach($draft, 'Monitor', $this->monitor('PHPUNIT-GARDE'));
        AssetsignItem::attach($draft, 'Monitor', $this->monitor('PHPUNIT-RETIRE'));
        $keep = array_values(array_filter(
            AssetsignItem::getForAssetsign($draft->getID()),
            static fn (array $row): bool => $row['name'] === 'PHPUNIT-GARDE'
        ));

        $draft->sendDraft([(int) $keep[0]['id']]);

        $draft->getFromDB($draft->getID());
        $this->assertNotSame(Assetsign::STATUS_DRAFT, (int) $draft->fields['status']);
        $this->assertGreaterThan(0, (int) $draft->fields['document_id_unsigned'], 'PDF genere a l\'envoi');
        $this->assertSame(['PHPUNIT-GARDE'], array_column(AssetsignItem::getForAssetsign($draft->getID()), 'name'));
    }

    public function testAnAlreadySentSheetCannotBeSentAgain(): void
    {
        $sheet = $this->createBareAssetsignFor($this->createTestComputer(0, 'PHPUNIT-PC'), Assetsign::STATUS_SENT);

        $this->expectException(\RuntimeException::class);
        $sheet->sendDraft();
    }

    public function testTheConfirmationIsShownOnceAndOnlyOnItsOwnItem(): void
    {
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $other = $this->createTestComputer(0, 'PHPUNIT-PC-AUTRE');
        $draft = $this->draftFor($computer);
        $draft->reopenConfirmation();

        $this->assertNull(Assetsign::popQueuedConfirmationFor($other));
        $popped = Assetsign::popQueuedConfirmationFor($computer);
        $this->assertNotNull($popped);
        $this->assertSame($draft->getID(), $popped->getID());
        $this->assertNull(Assetsign::popQueuedConfirmationFor($computer), 'une seule fois');
    }

    public function testASentSheetIsNotQueuedAgain(): void
    {
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $sheet = $this->createBareAssetsignFor($computer, Assetsign::STATUS_SENT);
        $sheet->reopenConfirmation();

        $this->assertNull(Assetsign::popQueuedConfirmationFor($computer));
    }

    public function testAssignmentWithoutATechnicianInFrontOfTheFormIsSentImmediately(): void
    {
        // Les tests tournent en ligne de commande, comme l'inventaire automatique ou les taches planifiees.
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'confirm_before_sending' => 1, 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');

        $computer->update(['id' => $computer->getID(), 'users_id' => 2]);

        $sheet = new Assetsign();
        $this->assertTrue($sheet->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
        $this->assertNotSame(Assetsign::STATUS_DRAFT, (int) $sheet->fields['status']);
    }

    public function testOnlyPhysicalEquipmentCanBeHandedOverWithIt(): void
    {
        $types = AssetsignItem::getAllowedItemtypes();
        $this->assertContains('Monitor', $types);
        $this->assertContains('Peripheral', $types);
        $this->assertContains('Phone', $types);
        $this->assertNotContains('SoftwareLicense', $types);
        $this->assertNotContains('Certificate', $types);
    }
}
