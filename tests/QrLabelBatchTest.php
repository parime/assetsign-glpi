<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\QrLabelBatch;
use GlpiPlugin\Assetsign\QrLabelLayout;

/**
 * Issue #278 : étiquettes QR en lot (action de masse). Chaque matériel du lot est revérifié au
 * moment d'imprimer ; un lot n'est lisible que dans la session qui l'a créé, par son jeton.
 */
class QrLabelBatchTest extends AssetsignTestCase
{
    public function testActionOfferedOnManagedItemtypesOnly(): void
    {
        $this->assertArrayHasKey(
            QrLabelBatch::class . \MassiveAction::CLASS_ACTION_SEPARATOR . QrLabelBatch::ACTION,
            QrLabelBatch::massiveActionsFor('Computer')
        );
        $this->assertSame([], QrLabelBatch::massiveActionsFor('Ticket'));
    }

    public function testBatchLabelsAreRecheckedAndSkippedItemsCounted(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit QrBatch');
        $first = $this->createTestComputer($entityId, 'PHPUnit PC QrBatch 1');
        $second = $this->createTestComputer($entityId, 'PHPUnit PC QrBatch 2');
        $disabledEntity = $this->createTestEntity(0, 'PHPUnit QrBatch Disabled');
        Config::upsertForEntity($disabledEntity, ['enable_qr_label' => 0]);
        $disabled = $this->createTestComputer($disabledEntity, 'PHPUnit PC QrBatch Disabled');

        $token = QrLabelBatch::store('Computer', [$first->getID(), $second->getID(), $disabled->getID(), 999999999]);
        $batch = QrLabelBatch::resolve($token);

        $this->assertNotNull($batch);
        $this->assertSame(['PHPUnit PC QrBatch 1', 'PHPUnit PC QrBatch 2'], array_column($batch['labels'], 'item_name'));
        $this->assertSame(2, $batch['skipped'], 'Étiquette désactivée et matériel introuvable : comptés, pas affichés.');
        $this->assertStringStartsWith('data:image/png;base64,', (string) $batch['labels'][0]['qr_data_uri']);
    }

    public function testSheetUsesEntityLayoutAndResolvesLabelText(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit QrSheet');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC QrSheet');
        $manufacturer = new \Manufacturer();
        $manufacturerId = (int) $manufacturer->add(['name' => 'PHPUnit Fabricant QrSheet']);
        $DB->update(
            'glpi_computers',
            ['otherserial' => 'INV-QRSHEET', 'manufacturers_id' => $manufacturerId, 'serial' => ''],
            ['id' => $computer->getID()]
        );
        $config = QrLabelLayout::normalize([
            'qr_label_preset'        => 'a4_4x10',
            'qr_label_text_position' => 'below',
            'qr_label_text'          => "{name}\nS/N {serial}\n{manufacturers_id} / {otherserial}\n{entity}",
        ]);

        $token = QrLabelBatch::store('Computer', [$computer->getID()]);
        $sheet = QrLabelBatch::sheet($token, [], $config);

        $this->assertNotNull($sheet);
        $this->assertFalse($sheet['customized']);
        $this->assertSame(40, $sheet['layout']['per_page']);
        $this->assertSame('below', $sheet['layout']['text_position']);
        $this->assertSame(
            ['PHPUnit PC QrSheet', 'PHPUnit Fabricant QrSheet / INV-QRSHEET', 'PHPUnit QrSheet'],
            $sheet['labels'][0]['lines'],
            'Numéro de série vide : ligne retirée ; fabricant résolu en nom.'
        );
        $this->assertSame(1, $sheet['pages']);
    }

    public function testSheetCanBeAdjustedForOnePrintWithinBounds(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit QrSheet Adjust');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC QrSheet Adjust');
        $token = QrLabelBatch::store('Computer', [$computer->getID()]);

        $sheet = QrLabelBatch::sheet($token, [
            'apply'                  => '1',
            'qr_label_preset'        => 'custom',
            'qr_label_width'         => '5000',
            'qr_label_height'        => '50',
            'qr_label_text_position' => ['none'],
            'qr_label_text'          => '{name}',
            'batch'                  => 'ignored',
        ], QrLabelLayout::normalize([]));

        $this->assertNotNull($sheet);
        $this->assertTrue($sheet['customized']);
        $this->assertSame(200, $sheet['layout']['label_width']);
        $this->assertSame(1, $sheet['layout']['columns']);
        $this->assertSame('beside', $sheet['layout']['text_position'], 'Valeur non scalaire : réglage de l\'entité.');
        $this->assertSame(['PHPUnit PC QrSheet Adjust'], $sheet['labels'][0]['lines']);
        $this->assertNull(QrLabelBatch::sheet(str_repeat('0', 32), [], []));
    }

    public function testUnknownOrMalformedTokenGivesNothing(): void
    {
        $this->assertNull(QrLabelBatch::resolve(str_repeat('0', 32)));
        $this->assertNull(QrLabelBatch::resolve('../../etc/passwd'));
        $this->assertNull(QrLabelBatch::resolve(''));
    }

    public function testBatchIsCappedAndOnlyRecentBatchesAreKept(): void
    {
        $token = QrLabelBatch::store('Computer', range(1, QrLabelBatch::MAX_ITEMS + 50));
        $this->assertCount(QrLabelBatch::MAX_ITEMS, $_SESSION['plugin_assetsign_qr_batches'][$token]['ids']);

        for ($i = 0; $i < 6; $i++) {
            QrLabelBatch::store('Computer', [1]);
        }
        $this->assertCount(5, $_SESSION['plugin_assetsign_qr_batches']);
        $this->assertArrayNotHasKey($token, $_SESSION['plugin_assetsign_qr_batches'], 'Lot le plus ancien oublié.');
    }
}
