<?php

namespace GlpiPlugin\Assetsign\Tests;

use CommonITILObject;
use CommonITILActor;
use GlpiPlugin\Assetsign\Arrival;
use GlpiPlugin\Assetsign\ArrivalLogic;
use GlpiPlugin\Assetsign\Config;
use Ticket;
use TicketTask;
use Ticket_User;
use User;

/**
 * Issue #221 : dossier d'arrivée d'un salarié — un ticket « Arrivée de … » avec une tâche par
 * étape, la personne demandeuse (pour lui remettre du matériel depuis le ticket). Déclencheurs :
 * manuel, création du compte, réactivation, date de début. Jamais de doublon tant qu'un dossier
 * est en cours.
 */
final class ArrivalTest extends AssetsignTestCase
{
   /**
    * @param array<string, mixed> $config
    */
   private function entityWithConfig(string $name, array $config = []): int {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Arrivee ' . $name);
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId] + $config);
       return $entityId;
   }

   private function taskCount(int $ticketsId): int {
       return countElementsInTable(TicketTask::getTable(), ['tickets_id' => $ticketsId]);
   }

   public function testManualPreparationOpensATicketWithOneTaskPerStepAndTheNewcomerAsRequester(): void {
       $entityId = $this->entityWithConfig('Manuel', ['arrival_tasks' => "Préparer le poste\nCréer le badge"]);
       $usersId = $this->createTestUser('Alice', 'Arrivante', ['entities_id' => $entityId, 'begin_date' => '2026-11-02 00:00:00']);
       $this->grantProfile($usersId, $entityId);

       $arrival = Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL);

       $ticketsId = (int) $arrival->fields['tickets_id'];
       $this->assertGreaterThan(0, $ticketsId);
       $ticket = new Ticket();
       $this->assertTrue($ticket->getFromDB($ticketsId));
       $this->assertStringContainsString('Alice', $ticket->fields['name']);
       $this->assertSame($entityId, (int) $ticket->fields['entities_id']);
       $this->assertSame(2, $this->taskCount($ticketsId));
       $this->assertSame('2026-11-02', $arrival->fields['date_arrival']);
       // Compte pas encore valide (date de début à venir) : GLPI refuse le demandeur, l'équipe est
       // prévenue, et la tâche planifiée l'ajoute le jour venu.
       $this->assertSame(0, $this->requesterCount($ticketsId, $usersId));
       $this->assertSame(1, $this->followupCount($ticketsId));
       Arrival::attachPendingRequesters();
       $this->assertSame(0, $this->requesterCount($ticketsId, $usersId), 'toujours pas valide');

       (new User())->update(['id' => $usersId, 'begin_date' => date('Y-m-d H:i:s', time() - 3600)]);
       $this->assertSame(1, Arrival::attachPendingRequesters());
       $this->assertSame(1, countElementsInTable(Ticket_User::getTable(), [
           'tickets_id' => $ticketsId, 'users_id' => $usersId, 'type' => CommonITILActor::REQUESTER, 'use_notification' => 0,
       ]), 'demandeuse sans notification, pour recevoir son matériel depuis le ticket');
       $this->assertSame(0, Arrival::attachPendingRequesters(), 'pas de doublon');
   }

   public function testAValidAccountIsRequesterRightAway(): void {
       $entityId = $this->entityWithConfig('Valide');
       $usersId = $this->createTestUser('Jules', 'Valide', ['entities_id' => $entityId]);
       $this->grantProfile($usersId, $entityId);

       $ticketsId = (int) Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL)->fields['tickets_id'];

       $this->assertSame(1, $this->requesterCount($ticketsId, $usersId));
       $this->assertSame(0, $this->followupCount($ticketsId));
   }

   private function requesterCount(int $ticketsId, int $usersId): int {
       return countElementsInTable(Ticket_User::getTable(), ['tickets_id' => $ticketsId, 'users_id' => $usersId, 'type' => CommonITILActor::REQUESTER]);
   }

   public function testWithoutAProfileInTheEntityTheTeamIsWarnedInsteadOfBypassingGlpi(): void {
       $entityId = $this->entityWithConfig('Sans profil');
       $usersId = $this->createTestUser('Ines', 'Sansprofil', ['entities_id' => $entityId]);

       $ticketsId = (int) Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL)->fields['tickets_id'];

       $this->assertGreaterThan(0, $ticketsId);
       $this->assertSame(0, countElementsInTable(Ticket_User::getTable(), ['tickets_id' => $ticketsId, 'users_id' => $usersId]));
       $this->assertSame(1, $this->followupCount($ticketsId), 'note privée pour l\'équipe');
   }

   private function grantProfile(int $usersId, int $entityId): void {
       global $DB;
       $profile = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'helpdesk'], 'LIMIT' => 1])->current();
       (new \Profile_User())->add(['users_id' => $usersId, 'profiles_id' => (int) $profile['id'], 'entities_id' => $entityId, 'is_recursive' => 1]);
   }

   private function followupCount(int $ticketsId): int {
       return countElementsInTable(\ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $ticketsId]);
   }

   public function testNoDuplicateWhileTheArrivalIsInProgressButANewOneOnceSolved(): void {
       $entityId = $this->entityWithConfig('Doublon');
       $usersId = $this->createTestUser('Bruno', 'Arrivant', ['entities_id' => $entityId]);

       $first = Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL);
       $second = Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL);
       $this->assertSame($first->getID(), $second->getID());
       $this->assertSame(4, $this->taskCount((int) $first->fields['tickets_id']), 'liste par défaut, pas de tâches en double');

       (new Ticket())->update(['id' => (int) $first->fields['tickets_id'], 'status' => CommonITILObject::SOLVED]);
       $third = Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL);
       $this->assertNotSame($first->getID(), $third->getID());
   }

   public function testCreationTriggerRespectsTheOptionAndIgnoresLongStandingEmployees(): void {
       $off = $this->entityWithConfig('Creation off');
       $offUser = $this->createTestUser('Chloe', 'Off', ['entities_id' => $off]);
       $this->assertNull(Arrival::findOpenFor($offUser), 'option désactivée par défaut');

       $on = $this->entityWithConfig('Creation on', ['arrival_on_creation' => 1]);
       $newcomer = $this->createTestUser('David', 'Nouveau', ['entities_id' => $on]);
       $this->assertNotNull(Arrival::findOpenFor($newcomer));

       $veteran = $this->createTestUser('Eva', 'Ancienne', ['entities_id' => $on, 'begin_date' => '2020-01-06 00:00:00']);
       $this->assertNull(Arrival::findOpenFor($veteran), 'compte importé d\'un salarié déjà présent');
   }

   public function testReactivationTrigger(): void {
       $entityId = $this->entityWithConfig('Reactivation', ['arrival_on_reactivation' => 1]);
       $usersId = $this->createTestUser('Felix', 'Retour', ['entities_id' => $entityId, 'is_active' => 0]);
       $this->assertNull(Arrival::findOpenFor($usersId));

       (new User())->update(['id' => $usersId, 'is_active' => 1]);

       $arrival = Arrival::findOpenFor($usersId);
       $this->assertNotNull($arrival);
       $this->assertSame(ArrivalLogic::TRIGGER_REACTIVATION, (int) $arrival->fields['trigger_type']);
   }

   public function testBeginDateTriggerCreatesOnlyOnceWithinTheWindow(): void {
       $entityId = $this->entityWithConfig('Date debut', ['arrival_on_begin_date' => 1, 'arrival_begin_date_days' => 14]);
       $now = time();
       $soon = $this->createTestUser('Gina', 'Bientot', ['entities_id' => $entityId, 'begin_date' => date('Y-m-d H:i:s', $now + 5 * 86400)]);
       $later = $this->createTestUser('Hugo', 'Plustard', ['entities_id' => $entityId, 'begin_date' => date('Y-m-d H:i:s', $now + 40 * 86400)]);

       Arrival::runBeginDateTriggers($now);
       $arrival = Arrival::findOpenFor($soon);
       $this->assertNotNull($arrival);
       $this->assertSame(ArrivalLogic::TRIGGER_BEGIN_DATE, (int) $arrival->fields['trigger_type']);
       $this->assertNull(Arrival::findOpenFor($later), 'hors de la fenêtre');

       (new Ticket())->update(['id' => (int) $arrival->fields['tickets_id'], 'status' => CommonITILObject::SOLVED]);
       Arrival::runBeginDateTriggers($now);
       $this->assertSame(1, countElementsInTable(Arrival::getTable(), ['users_id' => $soon]), 'pas recréée le lendemain');
   }

   public function testDefaultTaskListIsNotStored(): void {
       $this->assertSame('', Config::normalizeArrivalTasks(ArrivalLogic::DEFAULT_TASKS));
       $this->assertSame("Badge\nPoste", Config::normalizeArrivalTasks("Badge\n\nPoste\nBadge"));
   }
}
