<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\AssetsignFormController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #180 : materiel disponible propose en premier (sans utilisateur ou « en stock »).
 */
final class AvailableItemsTest extends AssetsignTestCase
{
    private function sheet(\Computer $computer): Assetsign
    {
        $sheet = new Assetsign();
        $sheet->getFromDB((int) $sheet->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
            'users_id' => 2, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
        ]));
        return $sheet;
    }

    /** @return list<string> valeurs « Itemtype:id » proposees */
    private function offered(Assetsign $sheet): array
    {
        $values = [];
        foreach (AssetsignItem::getAvailableItems($sheet, 5000) as $rows) {
            foreach ($rows as $row) {
                $values[] = $row['value'];
            }
        }
        return $values;
    }

    private function monitor(array $fields = []): int
    {
        return (int) (new \Monitor())->add($fields + ['name' => 'PHPUNIT-ECRAN-' . mt_rand(), 'entities_id' => 0]);
    }

    public function testFreeItemsAreOfferedAndAssignedOnesAreNot(): void
    {
        $sheet = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC'));
        $free = $this->monitor();
        $used = $this->monitor(['users_id' => $this->createTestUser('Deja', 'Equipe')]);

        $offered = $this->offered($sheet);

        $this->assertContains('Monitor:' . $free, $offered);
        $this->assertNotContains('Monitor:' . $used, $offered);
        $this->assertNotContains('Computer:' . $sheet->fields['items_id'], $offered, 'pas le materiel principal');
    }

    public function testItemsInAStockStateAreOfferedEvenWithAUser(): void
    {
        $stock = $this->createTestState('PHPUnit en stock');
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'stock_states' => json_encode([$stock])]);
        $sheet = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC'));
        $inStock = $this->monitor(['users_id' => $this->createTestUser('Ancien', 'Detenteur'), 'states_id' => $stock]);

        $this->assertContains('Monitor:' . $inStock, $this->offered($sheet));
    }

    /**
     * Issue #206 : avec des statuts « en stock » configures, un materiel sans utilisateur mais
     * reforme (ou sans statut) n'est plus propose : le statut fait foi a lui seul.
     */
    public function testWithStockStatesConfiguredOnlyTheStatusCounts(): void
    {
        $stock = $this->createTestState('PHPUnit en stock 206');
        $scrapped = $this->createTestState('PHPUnit reforme 206');
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'stock_states' => json_encode([$stock])]);
        $sheet = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC'));
        $inStock = $this->monitor(['states_id' => $stock]);
        $scrappedFree = $this->monitor(['states_id' => $scrapped]);
        $noStatusFree = $this->monitor();

        $offered = $this->offered($sheet);

        $this->assertContains('Monitor:' . $inStock, $offered);
        $this->assertNotContains('Monitor:' . $scrappedFree, $offered, 'un materiel reforme sans utilisateur n\'est pas disponible');
        $this->assertNotContains('Monitor:' . $noStatusFree, $offered, 'sans statut « en stock », le seul fait d\'etre sans utilisateur ne suffit plus');
    }

    public function testItemsAlreadyOnASheetAreNotOffered(): void
    {
        $sheet = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC'));
        $other = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC-2'));
        $mine = $this->monitor();
        $elsewhere = $this->monitor();
        AssetsignItem::attach($sheet, 'Monitor', $mine);
        AssetsignItem::attach($other, 'Monitor', $elsewhere);

        $offered = $this->offered($sheet);

        $this->assertNotContains('Monitor:' . $mine, $offered);
        $this->assertNotContains('Monitor:' . $elsewhere, $offered, 'deja sur une autre feuille en attente');
    }

    /** Cree et active un type d'actif personnalise, renvoie le nom de sa classe. */
    private function customAssetType(string $systemName): string
    {
        global $CFG_GLPI;
        $definition = new \Glpi\Asset\AssetDefinition();
        $definition->getFromDB((int) $definition->add([
            'system_name' => $systemName, 'label' => $systemName, 'is_active' => 1,
            'capacities' => [], 'profiles' => [],
        ]));
        $manager = \Glpi\Asset\AssetDefinitionManager::getInstance();
        $manager->registerDefinition($definition);
        $manager->bootstrapDefinition($definition);
        $class = $definition->getAssetClassName();
        if (!in_array($class, $CFG_GLPI['asset_types'], true)) {
            $CFG_GLPI['asset_types'][] = $class;
        }
        return $class;
    }

    public function testCustomAssetTypesAreNotMixedUp(): void
    {
        $headsets = $this->customAssetType('Phpunitheadset' . mt_rand(1000, 9999));
        $docks = $this->customAssetType('Phpunitdock' . mt_rand(1000, 9999));
        $headset = (int) (new $headsets())->add(['name' => 'PHPUNIT-CASQUE', 'entities_id' => 0]);
        $dock = (int) (new $docks())->add(['name' => 'PHPUNIT-HUB', 'entities_id' => 0]);
        $sheet = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC'));

        $offered = $this->offered($sheet);

        $this->assertContains($headsets . ':' . $headset, $offered);
        $this->assertContains($docks . ':' . $dock, $offered);
        $this->assertNotContains($headsets . ':' . $dock, $offered, 'un hub n\'est pas un casque');
        $this->assertNotContains($docks . ':' . $headset, $offered, 'un casque n\'est pas un hub');
    }

    public function testChoosingAnAvailableItemAddsIt(): void
    {
        $sheet = $this->sheet($this->createTestComputer(0, 'PHPUNIT-PC'));
        $free = $this->monitor();

        [, $isError] = (new AssetsignFormController())->addInventoriedItem($sheet, ['available_item' => 'Monitor:' . $free]);

        $this->assertFalse($isError);
        $this->assertSame([$free], array_map('intval', array_column(AssetsignItem::getForAssetsign($sheet->getID()), 'items_id')));
    }
}
