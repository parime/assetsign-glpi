<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\CampaignLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #158 : règles pures des campagnes d'attestation (aucune base de données).
 */
final class CampaignLogicTest extends TestCase
{
   public function testStats(): void {
       $this->assertSame(
           ['total' => 4, 'confirmed' => 2, 'discrepancy' => 1, 'pending' => 1, 'responded' => 3, 'response_rate' => 75],
           CampaignLogic::stats([1, 1, 2, 0])
       );
       $this->assertSame(0, CampaignLogic::stats([])['response_rate']);
   }

   public function testGapsAreWhitelisted(): void {
       $this->assertSame(['missing', 'unknown'], CampaignLogic::sanitizeGaps(['unknown', 'bogus', 'missing']));
       $this->assertSame([], CampaignLogic::sanitizeGaps('missing'));
   }

   public function testDeadlineDayIsStillOpen(): void {
       $this->assertFalse(CampaignLogic::isPastDeadline('2026-10-10', strtotime('2026-10-10 18:00:00')));
       $this->assertTrue(CampaignLogic::isPastDeadline('2026-10-10', strtotime('2026-10-11 00:00:01')));
       $this->assertFalse(CampaignLogic::isPastDeadline(null, time()));
   }
}
