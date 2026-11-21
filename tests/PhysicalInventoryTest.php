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

use GlpiPlugin\Assetsign\PhysicalInventory;

/**
 * Issue #368 : inventaire physique par scan des etiquettes QR.
 */
final class PhysicalInventoryTest extends AssetsignTestCase
{
   private function computer(int $entityId, string $name, int $locationId, string $serial = ''): \Computer {
       $computer = $this->createTestComputer($entityId, $name);
       $computer->update(['id' => $computer->getID(), 'locations_id' => $locationId, 'serial' => $serial]);
       $computer->getFromDB($computer->getID());
       return $computer;
   }

   private function campaign(int $entityId, int $locationId): PhysicalInventory {
       $campaign = new PhysicalInventory();
       $id = (int) $campaign->add([
           'name'                 => 'PHPUnit inventaire',
           'entities_id'          => $entityId,
           'locations_id'         => $locationId,
           'include_sublocations' => 0,
           'users_id'             => 2,
       ]);
       $this->assertGreaterThan(0, $id);
       $campaign->getFromDB($id);
       return $campaign;
   }

   public function testReconcilesFoundMovedMissingAndExtra(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Inventaire physique');
       $other = $this->createTestEntity(0, 'PHPUnit Inventaire autre entite');
       $room = $this->createTestLocation('PHPUnit salle 101');
       $elsewhere = $this->createTestLocation('PHPUnit salle 202');

       $found = $this->computer($entityId, 'PHPUNIT-INV-TROUVE', $room, 'SN-INV-001');
       $moved = $this->computer($entityId, 'PHPUNIT-INV-AILLEURS', $elsewhere, 'SN-INV-002');
       $missing = $this->computer($entityId, 'PHPUNIT-INV-ABSENT', $room, 'SN-INV-003');
       $foreign = $this->computer($other, 'PHPUNIT-INV-AUTRE', $room, 'SN-INV-004');

       $campaign = $this->campaign($entityId, $room);
       $entities = [$entityId];

       // Etiquette QR du plugin : URL absolue du formulaire du materiel.
       $qr = 'https://glpi.example.org' . \Computer::getFormURLWithID($found->getID()) . '&forcetab=GlpiPlugin%5CAssetsign%5CPassportEvent%241';
       $result = $campaign->recordScan($qr, 2, $entities);
       $this->assertSame('recorded', $result['result']);
       $this->assertSame(PhysicalInventory::STATUS_FOUND, $result['status']);
       $this->assertSame('duplicate', $campaign->recordScan($qr, 2, $entities)['result']);

       $this->assertSame(PhysicalInventory::STATUS_MOVED, $campaign->recordScan('SN-INV-002', 2, $entities)['status']);
       // Materiel d'une autre entite : inconnu (en trop), rien n'en est revele.
       $this->assertSame(PhysicalInventory::STATUS_EXTRA, $campaign->recordScan('SN-INV-004', 2, $entities)['status']);
       $this->assertSame(PhysicalInventory::STATUS_EXTRA, $campaign->recordScan('=CODE-INCONNU', 2, $entities)['status']);
       $this->assertSame('empty', $campaign->recordScan('   ', 2, $entities)['result']);

       $report = $campaign->getReport($entities);
       $this->assertSame(
           [PhysicalInventory::STATUS_FOUND => 1, PhysicalInventory::STATUS_MOVED => 1, PhysicalInventory::STATUS_MISSING => 1, PhysicalInventory::STATUS_EXTRA => 2],
           $report['counts']
       );
       $names = array_column($report['rows'], 'name', 'status');
       $this->assertSame('PHPUNIT-INV-ABSENT', $names[PhysicalInventory::STATUS_MISSING]);
       $this->assertNotContains('PHPUNIT-INV-AUTRE', array_column($report['rows'], 'name'));
       $this->assertSame(PhysicalInventory::STATUS_MISSING, $report['rows'][0]['status']);

       // Lecture seule sur les actifs : ni lieu ni entite modifies.
       foreach ([[$found, $room], [$moved, $elsewhere], [$missing, $room], [$foreign, $room]] as [$computer, $location]) {
           $reloaded = new \Computer();
           $reloaded->getFromDB($computer->getID());
           $this->assertSame($location, (int) $reloaded->fields['locations_id']);
       }

       // Export CSV : en-tete + une ligne par resultat, formule neutralisee.
       $csv = PhysicalInventory::toCsv($report['rows']);
       $this->assertCount(6, array_filter(explode("\n", trim($csv))));
       $this->assertStringContainsString("'=CODE-INCONNU", $csv);
       $this->assertStringContainsString('SN-INV-003', $csv);
   }

   public function testClosedCampaignRefusesScansAndAmbiguousCodeIsNotRecorded(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Inventaire clos');
       $room = $this->createTestLocation('PHPUnit salle 303');
       $this->computer($entityId, 'PHPUNIT-INV-DOUBLE', $room, 'SN-INV-DOUBLE');
       $this->computer($entityId, 'PHPUNIT-INV-DOUBLE-2', $room, 'SN-INV-DOUBLE');

       $campaign = $this->campaign($entityId, $room);
       $this->assertSame('ambiguous', $campaign->recordScan('SN-INV-DOUBLE', 2, [$entityId])['result']);
       $this->assertSame(0, $campaign->countScans());

       $this->assertTrue($campaign->close());
       $campaign->getFromDB($campaign->getID());
       $this->assertFalse($campaign->isOpen());
       $this->assertSame('closed', $campaign->recordScan('PHPUNIT-INV-DOUBLE', 2, [$entityId])['result']);
       $this->assertSame(0, $campaign->countScans());

       $id = $campaign->getID();
       $campaign->delete(['id' => $id], true);
       global $DB;
       $this->assertSame(0, $DB->request(['FROM' => PhysicalInventory::SCANS_TABLE, 'WHERE' => ['physicalinventories_id' => $id]])->count());
   }

   public function testLaunchRequiresNameAndVisibleLocation(): void {
       $this->assertNull(PhysicalInventory::launch(['name' => '', 'locations_id' => 1], 2));
       $this->assertNull(PhysicalInventory::launch(['name' => 'PHPUnit', 'locations_id' => 0], 2));
       $this->assertSame([], PhysicalInventory::resolveCode('https://glpi.example.org/front/computer.form.php?id=0', ['Computer'], [0]));
   }

   public function testRendersCampaignPageWithEscapedValues(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Inventaire rendu');
       $room = $this->createTestLocation('PHPUnit salle 404');
       $campaign = $this->campaign($entityId, $room);
       $campaign->recordScan('<img src=x onerror=alert(1)>', 2, [$entityId]);

       $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render('@assetsign/physicalinventory_form.html.twig', [
           'campaign'   => ['id' => $campaign->getID(), 'name' => '<b>x</b>', 'location' => 'PHPUnit salle 404', 'include_sublocations' => false, 'comment' => '', 'date_creation' => '', 'date_closed' => '', 'is_open' => true],
           'report'     => $campaign->getReport([$entityId]),
           'labels'     => PhysicalInventory::getStatusLabels(),
           'colors'     => PhysicalInventory::getStatusColors(),
           'can_scan'   => true,
           'self_url'   => '/plugins/assetsign/front/physicalinventory.form.php?id=' . $campaign->getID(),
           'list_url'   => '/plugins/assetsign/front/physicalinventory.php',
           'scan_url'   => '/plugins/assetsign/front/physicalinventoryscan.php',
           'scan_token' => 'token',
       ]);
       $this->assertStringNotContainsString('<img src=x', $html);
       $this->assertStringNotContainsString('<b>x</b>', $html);
       $this->assertStringContainsString('assetsign-inventory-scanner', $html);
       $this->assertStringContainsString('lib/scan/jsQR.js', $html);
   }
}
