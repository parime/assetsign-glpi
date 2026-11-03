<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\QrLabelBatch;

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
