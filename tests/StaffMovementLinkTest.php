<?php

namespace GlpiPlugin\Assetsign\Tests;

use CommonITILActor;
use Config as CoreConfig;
use GlpiPlugin\Assetsign\Arrival;
use GlpiPlugin\Assetsign\ArrivalLogic;
use GlpiPlugin\Assetsign\Departure;
use GlpiPlugin\Assetsign\DepartureLogic;
use GlpiPlugin\Assetsign\StaffMovementLink;
use ITILCategory;
use ITILFollowup;
use TaskTemplate;
use Ticket;
use TicketTask;

/**
 * Issue grcmanager #362 : arrivées et départs réutilisent la catégorie et le gabarit de tâche de
 * Configuration GLPI Auto quand ce plugin est actif, et ne créent pas de second ticket si un
 * ticket ouvert existe déjà pour la même personne et le même mouvement. Sans lui : inchangé.
 */
final class StaffMovementLinkTest extends AssetsignTestCase
{
   protected function tearDown(): void {
       StaffMovementLink::$forceCgaActive = null;
       parent::tearDown();
   }

   /**
    * @return array{0: int, 1: int}
    */
   private function newcomer(string $name, array $config = []): array {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Mouvement ' . $name);
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId, 'departure_ticket' => 1] + $config);
       $usersId = $this->createTestUser($name, 'Mouvement', ['entities_id' => $entityId]);
       $profile = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'helpdesk'], 'LIMIT' => 1])->current();
       (new \Profile_User())->add(['users_id' => $usersId, 'profiles_id' => (int) $profile['id'], 'entities_id' => $entityId, 'is_recursive' => 1]);
       return [$entityId, $usersId];
   }

   /**
    * Simule les objets installés par Configuration GLPI Auto et leurs identifiants enregistrés.
    *
    * @return array{category: int, template: int}
    */
   private function cgaObjects(string $event): array {
       $category = (int) (new ITILCategory())->add(['name' => 'PHPUnit catégorie ' . $event, 'entities_id' => 0, 'is_recursive' => 1]);
       $template = (int) (new TaskTemplate())->add([
           'name' => 'PHPUnit gabarit ' . $event, 'entities_id' => 0, 'is_recursive' => 1,
           'content' => "CHECKLIST PHPUNIT\n\n- Étape une\n- Étape deux",
       ]);
       CoreConfig::setConfigurationValues(StaffMovementLink::CGA_CONTEXT, [
           $event . '_itilcategories_id' => $category,
           $event . '_tasktemplates_id'  => $template,
       ]);
       StaffMovementLink::$forceCgaActive = true;
       return ['category' => $category, 'template' => $template];
   }

   public function testWithoutConfigurationGlpiAutoNothingChanges(): void {
       StaffMovementLink::$forceCgaActive = false;
       $this->cgaObjects('arrival');
       StaffMovementLink::$forceCgaActive = false;
       [, $usersId] = $this->newcomer('Sans');

       $this->assertSame(['itilcategories_id' => 0, 'tasktemplates_id' => 0], StaffMovementLink::forEvent('arrival'));
       $ticketsId = (int) Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL)->fields['tickets_id'];
       $ticket = new Ticket();
       $ticket->getFromDB($ticketsId);
       $this->assertSame(0, (int) $ticket->fields['itilcategories_id']);
       $this->assertSame(4, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $ticketsId]), 'tâches par défaut');
   }

   public function testArrivalTicketUsesTheCategoryAndTheTaskTemplate(): void {
       $cga = $this->cgaObjects('arrival');
       [, $usersId] = $this->newcomer('Avec');

       $ticketsId = (int) Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL)->fields['tickets_id'];

       $ticket = new Ticket();
       $ticket->getFromDB($ticketsId);
       $this->assertSame($cga['category'], (int) $ticket->fields['itilcategories_id']);
       $tasks = iterator_to_array((new TicketTask())->find(['tickets_id' => $ticketsId]), false);
       $this->assertCount(1, $tasks, 'une tâche : la liste de contrôle du gabarit');
       $this->assertSame($cga['template'], (int) $tasks[0]['tasktemplates_id']);
       $this->assertStringContainsString('Étape deux', (string) $tasks[0]['content']);
   }

   public function testAdministratorTaskListIsKept(): void {
       $this->cgaObjects('arrival');
       [, $usersId] = $this->newcomer('Liste', ['arrival_tasks' => "Préparer le poste\nCréer le badge"]);

       $ticketsId = (int) Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_MANUAL)->fields['tickets_id'];

       $this->assertSame(2, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $ticketsId]));
   }

   public function testOpenArrivalTicketIsReusedWithAFollowup(): void {
       $cga = $this->cgaObjects('arrival');
       [$entityId, $usersId] = $this->newcomer('Existant');
       $existing = (int) (new Ticket())->add([
           'name' => 'Création du compte (formulaire)', 'content' => 'Demande RH', 'entities_id' => $entityId,
           'itilcategories_id' => $cga['category'],
           '_actors' => ['requester' => [['itemtype' => \User::class, 'items_id' => $usersId, 'use_notification' => 0, 'alternative_email' => '']]],
       ]);
       $this->assertGreaterThan(0, $existing);
       $before = countElementsInTable(Ticket::getTable());

       $arrival = Arrival::prepareFor($usersId, ArrivalLogic::TRIGGER_CREATION);

       $this->assertSame($existing, (int) $arrival->fields['tickets_id']);
       $this->assertSame($before, countElementsInTable(Ticket::getTable()), 'aucun second ticket');
       $this->assertSame(1, countElementsInTable(ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $existing]));
   }

   public function testOpenDepartureTicketIsReusedWithAFollowup(): void {
       global $DB;
       $cga = $this->cgaObjects('departure');
       [$entityId, $usersId] = $this->newcomer('Partant');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC mouvement');
       $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $computer->getID()]);
       $existing = (int) (new Ticket())->add([
           'name' => 'Désactivation du compte', 'content' => 'Départ', 'entities_id' => $entityId,
           'itilcategories_id' => $cga['category'], '_users_id_observer' => $usersId,
       ]);
       $this->assertSame(1, countElementsInTable('glpi_tickets_users', [
           'tickets_id' => $existing, 'users_id' => $usersId, 'type' => CommonITILActor::OBSERVER,
       ]));

       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);

       $this->assertSame($existing, (int) $departure->fields['tickets_id']);
       $this->assertSame(1, countElementsInTable(ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $existing]));
       $this->assertSame(1, countElementsInTable('glpi_items_tickets', ['tickets_id' => $existing, 'itemtype' => 'Computer']));
   }
}
