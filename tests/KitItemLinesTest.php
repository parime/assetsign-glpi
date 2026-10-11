<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Kit;

/**
 * Issue #181 : kits composes de types d'elements GLPI (ex. 1 ecran + 2 peripheriques), pourvus avec
 * les objets reels a l'attribution.
 */
final class KitItemLinesTest extends AssetsignTestCase
{
    private function kit(array $lines): Kit
    {
        $kit = new Kit();
        $kit->getFromDB((int) $kit->add(['name' => 'PHPUnit pack ' . mt_rand(), 'entities_id' => 0, 'is_recursive' => 1, 'item_lines' => $lines]));
        return $kit;
    }

    public function testLinesAreStoredFromTheFormAndInvalidOnesIgnored(): void
    {
        $kit = $this->kit(['Monitor' => '1', 'Peripheral' => '2', 'Phone' => '0', 'User' => '3']);

        $this->assertSame(['Monitor' => 1, 'Peripheral' => 2], $kit->getExpectedItemLines());
    }

    public function testTheSheetShowsWhatIsProvidedAndWhatIsAvailable(): void
    {
        $kit = $this->kit(['Monitor' => '1', 'Peripheral' => '2']);
        $sheet = new Assetsign();
        $sheet->getFromDB((int) $sheet->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $this->createTestComputer(0, 'PHPUNIT-PC')->getID(),
            'users_id' => 2, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
            'plugin_assetsign_kits_id' => $kit->getID(),
        ]));
        $monitor = (int) (new \Monitor())->add(['name' => 'PHPUNIT-ECRAN', 'entities_id' => 0]);
        $headset = (int) (new \Peripheral())->add(['name' => 'PHPUNIT-CASQUE-LIBRE', 'entities_id' => 0]);
        AssetsignItem::attach($sheet, 'Monitor', $monitor);

        $lines = [];
        foreach ($sheet->getKitItemLines() as $line) {
            $lines[$line['itemtype']] = $line;
        }

        $this->assertSame(1, $lines['Monitor']['provided']);
        $this->assertSame(1, $lines['Monitor']['expected']);
        $this->assertSame(0, $lines['Peripheral']['provided']);
        $this->assertSame(2, $lines['Peripheral']['expected']);
        $this->assertContains('Peripheral:' . $headset, array_column($lines['Peripheral']['available'], 'value'));
    }

    public function testNoKitMeansNoLines(): void
    {
        $sheet = new Assetsign();
        $sheet->getFromDB((int) $sheet->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $this->createTestComputer(0, 'PHPUNIT-PC')->getID(),
            'users_id' => 2, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
        ]));

        $this->assertSame([], $sheet->getKitItemLines());
    }
}
