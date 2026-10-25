<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Renewal;

/**
 * Issue #222 : materiels a renouveler d'une entite de test, ticket de changement groupe, et
 * changement mensuel une seule fois par mois.
 */
final class RenewalTest extends AssetsignTestCase
{
   private function computerWithInfocom(int $entityId, string $name, ?string $useDate, float $sinkTime, float $value): int {
       $computer = $this->createTestComputer($entityId, $name);
       (new \Infocom())->add([
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'entities_id' => $entityId,
           'use_date'    => $useDate,
           'sink_time'   => $sinkTime,
           'value'       => $value,
       ]);
       return (int) $computer->getID();
   }

   public function testDueAssetsBudgetAndGroupedChange(): void {
       global $DB;

       $entityId = $this->createTestEntity(0, 'PHPUnit Renouvellement');
       Config::upsertForEntity($entityId, ['renewal_default_years' => 4, 'renewal_monthly_change' => 1]);
       $old = $this->computerWithInfocom($entityId, 'PC-AMORTI', date('Y-m-d', strtotime('-5 years')), 4, 900);
       $soon = $this->computerWithInfocom($entityId, 'PC-BIENTOT', date('Y-m-d', strtotime('-4 years +6 months')), 0, 1100);
       $this->computerWithInfocom($entityId, 'PC-RECENT', date('Y-m-d', strtotime('-1 year')), 4, 1300);
       $this->createTestComputer($entityId, 'PC-SANS-FICHE');

       $rows = Renewal::collect(12, $entityId);
       $this->assertSame(['PC-AMORTI', 'PC-BIENTOT'], array_column($rows, 'name'), 'Durée par défaut pour PC-BIENTOT.');
       $this->assertSame(['PC-AMORTI'], array_column(Renewal::collect(3, $entityId), 'name'));

       $csv = Renewal::toCsv($rows);
       $this->assertStringContainsString('PC-BIENTOT', $csv);
       $this->assertStringContainsString('1100,00', $csv);

       $changeId = Renewal::createChange($rows, 12, $entityId);
       $this->assertGreaterThan(0, $changeId);
       $linked = array_map('intval', array_column(iterator_to_array($DB->request([
           'FROM' => 'glpi_changes_items', 'WHERE' => ['changes_id' => $changeId, 'itemtype' => 'Computer'],
       ]), false), 'items_id'));
       sort($linked);
       $this->assertSame([$old, $soon], $linked);
       $this->assertSame(0, Renewal::createChange([], 12, $entityId), 'Rien à renouveler : pas de changement.');

       $task = new \CronTask();
       $countFor = static fn (): int => countElementsInTable('glpi_changes', ['entities_id' => $entityId]);
       $before = $countFor();
       Renewal::cronAssetsignRenewal($task);
       $this->assertSame($before + 1, $countFor());
       Renewal::cronAssetsignRenewal($task);
       $this->assertSame($before + 1, $countFor(), 'Un seul changement par mois.');
   }
}
