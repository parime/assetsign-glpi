<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\RenewalLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #222 : fin d'amortissement et budget de renouvellement.
 */
final class RenewalLogicTest extends TestCase
{
   public function testEndOfLifeUsesServiceDateThenPurchaseDateAndDefaultDuration(): void {
       $this->assertSame('2027-03-15', RenewalLogic::endOfLife('2023-03-15', '2023-01-10', 4, 5));
       $this->assertSame('2027-01-10', RenewalLogic::endOfLife(null, '2023-01-10', 4, 5), 'À défaut, la date d\'achat.');
       $this->assertSame('2028-01-10', RenewalLogic::endOfLife('', '2023-01-10', 0, 5), 'Durée par défaut.');
       $this->assertSame('2024-07-10', RenewalLogic::endOfLife(null, '2023-01-10', 1.5, 5), 'Durée en années décimales.');
       $this->assertNull(RenewalLogic::endOfLife(null, null, 4, 5));
   }

   public function testIsDueWithinHorizonIncludingPastEnds(): void {
       $this->assertTrue(RenewalLogic::isDue('2026-01-01', '2026-10-25', 12), 'Déjà amorti.');
       $this->assertTrue(RenewalLogic::isDue('2027-10-25', '2026-10-25', 12));
       $this->assertFalse(RenewalLogic::isDue('2027-10-26', '2026-10-25', 12));
       $this->assertFalse(RenewalLogic::isDue(null, '2026-10-25', 12));
   }

   public function testBudgetByYear(): void {
       $this->assertSame(
           ['2026' => ['count' => 1, 'budget' => 900.0], '2027' => ['count' => 2, 'budget' => 1200.0]],
           RenewalLogic::budgetByYear([
               ['end_of_life' => '2027-05-01', 'value' => 1200.0],
               ['end_of_life' => '2026-11-01', 'value' => 900.0],
               ['end_of_life' => '2027-08-01', 'value' => 0.0],
           ])
       );
       $this->assertSame(12, RenewalLogic::normalizeHorizon(7));
       $this->assertSame(24, RenewalLogic::normalizeHorizon(24));
   }
}
