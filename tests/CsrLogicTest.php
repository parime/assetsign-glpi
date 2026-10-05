<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\CsrLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #142 : calculs du tableau de bord RSE (aucune base de données).
 */
final class CsrLogicTest extends TestCase
{
   private const NOW = 1791158400; // 2026-10-05

   public function testAvoidedImpactOnlyBeyondThePlannedDuration(): void {
       $this->assertNull(CsrLogic::avoidedImpact(null, 3, '2020-01-01', null, self::NOW), 'pas d\'empreinte saisie');
       $this->assertNull(CsrLogic::avoidedImpact(300.0, 0, '2020-01-01', null, self::NOW), 'pas de durée prévue');
       $this->assertNull(CsrLogic::avoidedImpact(300.0, 5, '2024-01-01', null, self::NOW), 'pas encore prolongé');

       $benefit = CsrLogic::avoidedImpact(300.0, 4, '2020-10-05', '2026-10-05', self::NOW);
       $this->assertNotNull($benefit);
       $this->assertEqualsWithDelta(150.0, $benefit['avoided_impact'], 1.0, '6 ans au lieu de 4 : 300 × 2/4');
       $this->assertFalse($benefit['is_still_in_service']);
   }

   public function testSummaryNeverInventsMissingData(): void {
       $summary = CsrLogic::summarize([
           ['footprint' => 200.0, 'sink_time' => 3.0, 'use_date' => '2021-10-05', 'decommission_date' => null],
           ['footprint' => null, 'sink_time' => 3.0, 'use_date' => '2024-10-05', 'decommission_date' => null],
           ['footprint' => 100.0, 'sink_time' => 0.0, 'use_date' => '2019-10-05', 'decommission_date' => '2025-10-05'],
           ['footprint' => null, 'sink_time' => 0.0, 'use_date' => null, 'decommission_date' => null],
       ], ['don' => 3, 'vente' => 1, 'destruction' => 4], self::NOW);

       $this->assertSame(4, $summary['total_items']);
       $this->assertSame(2, $summary['with_footprint']);
       $this->assertSame(50, $summary['footprint_coverage']);
       $this->assertSame(300.0, $summary['footprint_total']);
       $this->assertSame(1, $summary['extended_items'], 'seul le premier dépasse sa durée prévue');
       $this->assertEqualsWithDelta(3.0, $summary['average_age_years'], 0.1, 'moyenne de 4 et 2 ans, matériel sans date ignoré');
       $this->assertEqualsWithDelta(6.0, $summary['average_lifetime_years'], 0.1);
       $this->assertSame(50, $summary['second_life_rate']);
   }

   public function testEmptyParkHasNoRates(): void {
       $summary = CsrLogic::summarize([], ['don' => 0, 'vente' => 0, 'destruction' => 0], self::NOW);

       $this->assertSame(0, $summary['footprint_coverage']);
       $this->assertNull($summary['average_age_years']);
       $this->assertNull($summary['second_life_rate'], 'aucune fin de vie : pas de taux affiché plutôt que 0 %');
   }
}
