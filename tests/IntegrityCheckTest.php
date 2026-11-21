<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Tests;

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\IntegrityCheck;

/**
 * Issue #371 : controle d'integrite des preuves signees.
 */
final class IntegrityCheckTest extends AssetsignTestCase
{
   private function signedSheet(int $entityId): Assetsign {
       $computer = $this->createTestComputer($entityId, 'PHPUNIT-PC-PREUVE');
       $sheet = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);
       $sheet->update(['id' => $sheet->getID(), 'items_id' => $computer->getID()]);
       $sheet->getFromDB($sheet->getID());
       $binary = "%PDF-1.4\n% phpunit " . $sheet->getID() . "\n%%EOF\n";
       $tmp = 'phpunit-preuve-' . $sheet->getID() . '.pdf';
       file_put_contents(GLPI_TMP_DIR . '/' . $tmp, $binary);
       $sheet->markSigned($tmp, ['document_hash' => hash('sha256', $binary)], false);
       $sheet->getFromDB($sheet->getID());
       return $sheet;
   }

   private function codes(int $entityId): array {
       return array_column(IntegrityCheck::check([$entityId])['anomalies'], 'code');
   }

   public function testIntactProofHasNoAnomaly(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Integrite intacte');
       $sheet = $this->signedSheet($entityId);
       $this->assertGreaterThan(0, (int) $sheet->fields['document_id_signed']);

       $result = IntegrityCheck::check([$entityId]);
       $this->assertSame([$entityId => 1], $result['checked']);
       $this->assertSame([], $result['anomalies']);
   }

   public function testDetectsAlteredDetachedAndMissingFiles(): void {
       global $DB;

       $entityId = $this->createTestEntity(0, 'PHPUnit Integrite anomalies');
       $sheet = $this->signedSheet($entityId);
       $document = new \Document();
       $document->getFromDB((int) $sheet->fields['document_id_signed']);
       $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];

       file_put_contents($path, "%PDF-1.4\n% modifie\n%%EOF\n");
       $this->assertSame([IntegrityCheck::ANOMALY_HASH_MISMATCH], $this->codes($entityId));

       $DB->delete('glpi_documents_items', ['documents_id' => $document->getID(), 'itemtype' => 'Computer']);
       $this->assertSame([IntegrityCheck::ANOMALY_HASH_MISMATCH, IntegrityCheck::ANOMALY_NOT_ATTACHED], $this->codes($entityId));

       unlink($path);
       $this->assertSame([IntegrityCheck::ANOMALY_FILE_MISSING, IntegrityCheck::ANOMALY_NOT_ATTACHED], $this->codes($entityId));

       $DB->update('glpi_documents', ['is_deleted' => 1], ['id' => $document->getID()]);
       $this->assertSame([IntegrityCheck::ANOMALY_DOCUMENT_GONE], $this->codes($entityId));
   }

   public function testSignedWithoutPdfNorProofAndRunsFilteredByEntity(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Integrite sans preuve');
       $otherId = $this->createTestEntity(0, 'PHPUnit Integrite autre');
       $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);
       // Non signee : ignoree.
       $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

       $this->assertSame([IntegrityCheck::ANOMALY_NO_PROOF, IntegrityCheck::ANOMALY_NO_DOCUMENT], $this->codes($entityId));

       $run = IntegrityCheck::run([$entityId, $otherId], 2);
       $this->assertSame(['checked' => 1, 'anomalies' => 2], ['checked' => $run['checked'], 'anomalies' => $run['anomalies']]);

       $mine = IntegrityCheck::getRuns([$entityId], 1)[0];
       $this->assertSame($run['id'], $mine['id']);
       $this->assertSame(1, $mine['checked']);
       $this->assertCount(2, $mine['anomalies']);
       $theirs = IntegrityCheck::getRuns([$otherId], 1)[0];
       $this->assertSame(0, $theirs['checked']);
       $this->assertSame([], $theirs['anomalies']);

       $csv = IntegrityCheck::toCsv(IntegrityCheck::describe($mine['anomalies']));
       $labels = IntegrityCheck::getAnomalyLabels();
       $this->assertStringContainsString($labels[IntegrityCheck::ANOMALY_NO_DOCUMENT], $csv);
   }

   public function testCronRecordsARun(): void {
       global $DB;

       $before = countElementsInTable(IntegrityCheck::RUNS_TABLE);
       $task = new \CronTask();
       $this->assertTrue($task->getFromDBbyName(IntegrityCheck::class, 'assetsignIntegrity'), 'tache enregistree');
       $this->assertSame(1, IntegrityCheck::cronAssetsignIntegrity($task));
       $this->assertSame(min($before + 1, IntegrityCheck::KEPT_RUNS), countElementsInTable(IntegrityCheck::RUNS_TABLE));
       $this->assertTrue($DB->tableExists(IntegrityCheck::RUNS_TABLE));
       $this->assertNotSame([], IntegrityCheck::cronInfo('assetsignIntegrity'));
   }
}
