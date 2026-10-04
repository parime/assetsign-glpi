<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\DepartureLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #157 : règles pures du dossier de départ (aucune base de données).
 */
final class DepartureLogicTest extends TestCase
{
   public function testDeactivationIsOnlyTheOneToZeroTransition(): void {
       $this->assertTrue(DepartureLogic::isDeactivation(['is_active' => 1], ['is_active' => 0]));
       $this->assertFalse(DepartureLogic::isDeactivation(['is_active' => 0], ['is_active' => 1]), 'réactivation');
       $this->assertFalse(DepartureLogic::isDeactivation([], ['is_active' => 0]), 'champ non modifié par cette mise à jour');
       $this->assertFalse(DepartureLogic::isDeactivation(['is_active' => 1], ['is_active' => 1]));
   }

   public function testEndDateWindow(): void {
       $now = strtotime('2026-10-10 12:00:00');
       $this->assertTrue(DepartureLogic::endDateWithinWindow('2026-10-15 00:00:00', 7, $now));
       $this->assertTrue(DepartureLogic::endDateWithinWindow('2026-10-10 06:00:00', 7, $now), 'le jour même');
       $this->assertFalse(DepartureLogic::endDateWithinWindow('2026-10-20 00:00:00', 7, $now), 'trop loin');
       $this->assertFalse(DepartureLogic::endDateWithinWindow('2026-10-01 00:00:00', 7, $now), 'déjà passée depuis longtemps');
       $this->assertFalse(DepartureLogic::endDateWithinWindow(null, 7, $now));
       $this->assertFalse(DepartureLogic::endDateWithinWindow('pas une date', 7, $now));
   }

   public function testStatusFromChildren(): void {
       $this->assertSame(DepartureLogic::STATUS_OPEN, DepartureLogic::statusFromChildren([]));
       $this->assertSame(
           DepartureLogic::STATUS_OPEN,
           DepartureLogic::statusFromChildren([Assetsign::STATUS_SIGNED, Assetsign::STATUS_SENT])
       );
       $this->assertSame(
           DepartureLogic::STATUS_SIGNED,
           DepartureLogic::statusFromChildren([Assetsign::STATUS_SIGNED, Assetsign::STATUS_CANCELLED])
       );
       $this->assertSame(
           DepartureLogic::STATUS_CANCELLED,
           DepartureLogic::statusFromChildren([Assetsign::STATUS_CANCELLED, Assetsign::STATUS_CANCELLED])
       );
   }

   public function testReminderScheduleRepeatsTheLastDelayAndNeverCatchesUpTwice(): void {
       $delays = [3, 7, 7];
       $this->assertFalse(DepartureLogic::reminderDue(2, null, 0, $delays, 0));
       $this->assertTrue(DepartureLogic::reminderDue(3, null, 0, $delays, 0));
       $this->assertFalse(DepartureLogic::reminderDue(9, 6, 1, $delays, 0), '3 + 7 jours pas encore atteints');
       $this->assertTrue(DepartureLogic::reminderDue(10, 7, 1, $delays, 0));
       $this->assertTrue(DepartureLogic::reminderDue(24, 7, 3, $delays, 0), 'le dernier délai se répète');
       $this->assertFalse(DepartureLogic::reminderDue(30, 0, 1, $delays, 0), 'relance déjà envoyée aujourd\'hui');
       $this->assertFalse(DepartureLogic::reminderDue(100, null, 2, $delays, 2), 'plafond max_reminders');
       $this->assertFalse(DepartureLogic::reminderDue(100, null, 0, [], 0));
   }
}
