<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\ArrivalLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #221 : règles pures du dossier d'arrivée (aucune base de données).
 */
final class ArrivalLogicTest extends TestCase
{
   public function testReactivationIsOnlyTheZeroToOneTransition(): void {
       $this->assertTrue(ArrivalLogic::isReactivation(['is_active' => 0], ['is_active' => 1]));
       $this->assertFalse(ArrivalLogic::isReactivation(['is_active' => 1], ['is_active' => 0]), 'désactivation');
       $this->assertFalse(ArrivalLogic::isReactivation([], ['is_active' => 1]), 'champ non modifié par cette mise à jour');
       $this->assertFalse(ArrivalLogic::isReactivation(['is_active' => 1], ['is_active' => 1]));
   }

   public function testNewcomerAccount(): void {
       $now = strtotime('2026-10-10 12:00:00');
       $this->assertTrue(ArrivalLogic::isNewcomerAccount(['is_active' => 1], $now), 'sans date de début');
       $this->assertTrue(ArrivalLogic::isNewcomerAccount(['is_active' => 1, 'begin_date' => '2026-10-20 00:00:00'], $now), 'arrivée future');
       $this->assertTrue(ArrivalLogic::isNewcomerAccount(['is_active' => 1, 'begin_date' => '2026-09-25 00:00:00'], $now), 'arrivée récente');
       $this->assertFalse(ArrivalLogic::isNewcomerAccount(['is_active' => 1, 'begin_date' => '2024-01-01 00:00:00'], $now), 'salarié déjà présent (import de l\'annuaire)');
       $this->assertFalse(ArrivalLogic::isNewcomerAccount(['is_active' => 0], $now), 'compte inactif');
       $this->assertFalse(ArrivalLogic::isNewcomerAccount(['is_active' => 1, 'is_deleted' => 1], $now), 'compte supprimé');
   }

   public function testBeginDateWindow(): void {
       $now = strtotime('2026-10-10 12:00:00');
       $this->assertTrue(ArrivalLogic::beginDateWithinWindow('2026-10-20 00:00:00', 14, $now));
       $this->assertTrue(ArrivalLogic::beginDateWithinWindow('2026-10-10 06:00:00', 14, $now), 'le jour même');
       $this->assertFalse(ArrivalLogic::beginDateWithinWindow('2026-11-30 00:00:00', 14, $now), 'trop loin');
       $this->assertFalse(ArrivalLogic::beginDateWithinWindow('2026-10-01 00:00:00', 14, $now), 'déjà passée');
       $this->assertFalse(ArrivalLogic::beginDateWithinWindow(null, 14, $now));
       $this->assertFalse(ArrivalLogic::beginDateWithinWindow('pas une date', 14, $now));
   }

   public function testTasksDefaultDedupAndLimits(): void {
       $this->assertCount(4, ArrivalLogic::parseTasks(''), 'vide : liste par défaut');
       $this->assertSame(ArrivalLogic::parseTasks(null), ArrivalLogic::parseTasks("  \n "));
       $this->assertSame(['Badge', 'Poste'], ArrivalLogic::parseTasks("Badge\r\n\r\n  Poste \nBadge"));
       $this->assertCount(ArrivalLogic::MAX_TASKS, ArrivalLogic::parseTasks(implode("\n", array_map(static fn (int $i): string => "Tâche $i", range(1, 40)))));
       $this->assertSame(255, mb_strlen(ArrivalLogic::parseTasks(str_repeat('é', 300))[0]));
   }
}
