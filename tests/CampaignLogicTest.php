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

   public function testCharterRenewalIsDueAfterThePeriod(): void {
       $this->assertTrue(CampaignLogic::charterRenewalDue(null, 12, time()), 'Aucune campagne : la première est due.');
       $this->assertFalse(CampaignLogic::charterRenewalDue('2025-10-23 08:00:00', 12, strtotime('2026-10-23 07:59:59')));
       $this->assertTrue(CampaignLogic::charterRenewalDue('2025-10-23 08:00:00', 12, strtotime('2026-10-23 08:00:00')));
       $this->assertTrue(CampaignLogic::charterRenewalDue('2026-04-01 00:00:00', 6, strtotime('2026-10-01 00:00:00')));
       $this->assertFalse(CampaignLogic::charterRenewalDue('2026-10-01 00:00:00', 0, strtotime('2026-10-15 00:00:00')), 'Période ramenée à 1 mois au minimum.');
   }

   public function testCharterTicketOnlyOnceAfterDeadlineWithNonSigners(): void {
       $after = strtotime('2026-10-11 09:00:00');
       $this->assertTrue(CampaignLogic::charterTicketDue('2026-10-10', 0, 3, $after));
       $this->assertFalse(CampaignLogic::charterTicketDue('2026-10-10', 42, 3, $after), 'Ticket déjà créé.');
       $this->assertFalse(CampaignLogic::charterTicketDue('2026-10-10', 0, 0, $after), 'Tout le monde a signé.');
       $this->assertFalse(CampaignLogic::charterTicketDue('2026-10-10', 0, 3, strtotime('2026-10-10 18:00:00')), 'Échéance pas encore passée.');
   }
}
