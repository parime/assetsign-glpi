<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;

/**
 * Issue #166 : objets du parc GLPI remis avec le materiel (n° de serie, modele, n° d'inventaire
 * repris sur le PDF), peripheriques connectes proposes ou ajoutes d'office.
 */
final class AssetsignItemTest extends AssetsignTestCase
{
    private function sheetFor(\CommonDBTM $item, int $status = Assetsign::STATUS_PENDING): Assetsign
    {
        $assetsign = new Assetsign();
        $assetsign->getFromDB((int) $assetsign->add([
            'entities_id' => 0,
            'itemtype'    => $item->getType(),
            'items_id'    => $item->getID(),
            'users_id'    => 2,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => $status,
        ]));
        return $assetsign;
    }

    private function monitor(string $name, array $extra = []): \Monitor
    {
        $monitor = new \Monitor();
        $monitor->getFromDB((int) $monitor->add($extra + ['name' => $name, 'entities_id' => 0]));
        return $monitor;
    }

    private function connect(\CommonDBTM $asset, \CommonDBTM $peripheral): void
    {
        (new \Glpi\Asset\Asset_PeripheralAsset())->add([
            'itemtype_asset'      => $asset->getType(),
            'items_id_asset'      => $asset->getID(),
            'itemtype_peripheral' => $peripheral->getType(),
            'items_id_peripheral' => $peripheral->getID(),
        ]);
    }

    public function testAttachStoresTheGlpiValuesShownOnThePdf(): void
    {
        $manufacturer = (int) (new \Manufacturer())->add(['name' => 'PHPUnit Dell']);
        $model = (int) (new \MonitorModel())->add(['name' => 'PHPUnit P2422H']);
        $monitor = $this->monitor('PHPUNIT-ECRAN', [
            'serial' => 'SN-PHPUNIT-1', 'otherserial' => 'INV-PHPUNIT-1',
            'manufacturers_id' => $manufacturer, 'monitormodels_id' => $model,
        ]);
        $sheet = $this->sheetFor($this->createTestComputer(0, 'PHPUNIT-PC'));

        $this->assertNull(AssetsignItem::attach($sheet, 'Monitor', $monitor->getID()));

        $rows = AssetsignItem::getForAssetsign($sheet->getID());
        $this->assertCount(1, $rows);
        $this->assertSame('SN-PHPUNIT-1', $rows[0]['serial']);
        $this->assertSame('INV-PHPUNIT-1', $rows[0]['otherserial']);
        $this->assertSame('PHPUnit Dell', $rows[0]['manufacturer']);
        $this->assertSame('PHPUnit P2422H', $rows[0]['model']);

        $html = (new HandoverPdfBuilder())->renderHtml($sheet);
        $this->assertStringContainsString('SN-PHPUNIT-1', $html);
        $this->assertStringContainsString('INV-PHPUNIT-1', $html);
        $this->assertStringContainsString('PHPUnit Dell PHPUnit P2422H', $html);
    }

    public function testRefusesTheMainItemADuplicateAndAnItemOnAnotherPendingSheet(): void
    {
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $monitor = $this->monitor('PHPUNIT-ECRAN');
        $sheet = $this->sheetFor($computer);

        $this->assertSame(AssetsignItem::ERROR_MAIN_ITEM, AssetsignItem::attach($sheet, 'Computer', $computer->getID()));
        $this->assertNull(AssetsignItem::attach($sheet, 'Monitor', $monitor->getID()));
        $this->assertSame(AssetsignItem::ERROR_ALREADY_LISTED, AssetsignItem::attach($sheet, 'Monitor', $monitor->getID()));

        $other = $this->sheetFor($this->createTestComputer(0, 'PHPUNIT-PC-2'));
        $this->assertSame(AssetsignItem::ERROR_ON_OTHER_SHEET, AssetsignItem::attach($other, 'Monitor', $monitor->getID()));

        // Une fois la premiere fiche signee, l'ecran peut etre remis ailleurs.
        $sheet->update(['id' => $sheet->getID(), 'status' => Assetsign::STATUS_SIGNED]);
        $this->assertNull(AssetsignItem::attach($other, 'Monitor', $monitor->getID()));
    }

    public function testRejectsUnknownTypesAndMissingItems(): void
    {
        $sheet = $this->sheetFor($this->createTestComputer(0, 'PHPUNIT-PC'));

        $this->assertSame(AssetsignItem::ERROR_UNKNOWN_TYPE, AssetsignItem::attach($sheet, 'User', 2));
        $this->assertSame(AssetsignItem::ERROR_NOT_FOUND, AssetsignItem::attach($sheet, 'Monitor', 999999999));
        $this->assertSame([], AssetsignItem::getForAssetsign($sheet->getID()));
    }

    public function testConnectedItemsAreSuggestedThenAddedInOneClick(): void
    {
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $monitor = $this->monitor('PHPUNIT-ECRAN', ['serial' => 'SN-CONNECTED']);
        $this->connect($computer, $monitor);
        $sheet = $this->sheetFor($computer);

        $suggested = $sheet->getSuggestedConnectedItems();
        $this->assertCount(1, $suggested);
        $this->assertSame('Monitor', $suggested[0]['itemtype']);
        $this->assertSame('SN-CONNECTED', $suggested[0]['serial']);

        $this->assertSame(1, $sheet->attachConnectedItems(false, false));
        $this->assertSame([], $sheet->getSuggestedConnectedItems(), 'plus rien a proposer');
    }

    public function testSignedSheetIsFrozen(): void
    {
        $monitor = $this->monitor('PHPUNIT-ECRAN', ['serial' => 'SN-AVANT']);
        $sheet = $this->sheetFor($this->createTestComputer(0, 'PHPUNIT-PC'));
        AssetsignItem::attach($sheet, 'Monitor', $monitor->getID());
        $sheet->update(['id' => $sheet->getID(), 'status' => Assetsign::STATUS_SIGNED]);
        $sheet->getFromDB($sheet->getID());

        $this->assertNotNull($sheet->addInventoriedItem('Monitor', $this->monitor('PHPUNIT-ECRAN-2')->getID()));
        $row = AssetsignItem::getForAssetsign($sheet->getID())[0];
        $sheet->removeInventoriedItem((int) $row['id']);
        $this->assertCount(1, AssetsignItem::getForAssetsign($sheet->getID()), 'retrait sans effet apres signature');

        // Le n° de serie corrige ensuite dans GLPI ne change pas le document signe.
        $monitor->update(['id' => $monitor->getID(), 'serial' => 'SN-APRES']);
        $this->assertSame('SN-AVANT', AssetsignItem::getForAssetsign($sheet->getID())[0]['serial']);
    }

    public function testFlagsAnItemAssignedToSomeoneElse(): void
    {
        $other = $this->createTestUser('Autre', 'Detenteur');
        $monitor = $this->monitor('PHPUNIT-ECRAN', ['users_id' => $other]);
        $sheet = $this->sheetFor($this->createTestComputer(0, 'PHPUNIT-PC'));
        AssetsignItem::attach($sheet, 'Monitor', $monitor->getID());

        $this->assertNotSame('', $sheet->getInventoriedItems()[0]['assigned_to_other']);
    }

    public function testAssignmentAttachesConnectedItemsWhenTheEntityAsksForIt(): void
    {
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'auto_attach_connected_items' => 1, 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $this->connect($computer, $this->monitor('PHPUNIT-ECRAN'));

        $computer->update(['id' => $computer->getID(), 'users_id' => 2]);

        $sheet = new Assetsign();
        $this->assertTrue($sheet->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
        $rows = AssetsignItem::getForAssetsign($sheet->getID());
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['is_automatic']);
    }
}
