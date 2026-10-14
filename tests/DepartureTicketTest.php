<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Departure;
use GlpiPlugin\Assetsign\DepartureLogic;
use GlpiPlugin\Assetsign\DepartureTicket;
use ITILFollowup;
use Item_Ticket;
use Ticket;

/**
 * Issue #195 : ticket « Récupération du matériel » ouvert pour l'équipe à la préparation d'un
 * départ, complété par des suivis. Jamais de changement de statut automatique.
 */
final class DepartureTicketTest extends AssetsignTestCase
{
   /**
    * @return array{0: int, 1: int, 2: list<int>}
    */
   private function personWithComputers(string $name, int $count, bool $ticket = true, ?string $endDate = null): array {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Depart ticket ' . $name);
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId, 'departure_ticket' => $ticket ? 1 : 0]);
       $usersId = $this->createTestUser($name, 'Partant', ['entities_id' => $entityId] + ($endDate !== null ? ['end_date' => $endDate] : []));
       $computerIds = [];
       for ($i = 1; $i <= $count; $i++) {
           $computer = $this->createTestComputer($entityId, "PHPUnit PC ticket $name $i");
           $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $computer->getID()]);
           $computerIds[] = $computer->getID();
       }
       return [$entityId, $usersId, $computerIds];
   }

   private function followupCount(int $ticketsId): int {
       return countElementsInTable(ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $ticketsId]);
   }

   public function testPreparingADepartureOpensOneTicketLinkedToEveryItem(): void {
       [, $usersId, $computerIds] = $this->personWithComputers('Denis', 2);

       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);

       $ticketsId = (int) $departure->fields['tickets_id'];
       $this->assertGreaterThan(0, $ticketsId);
       $ticket = new Ticket();
       $this->assertTrue($ticket->getFromDB($ticketsId));
       $this->assertStringContainsString('Denis', $ticket->fields['name']);
       $linked = array_map('intval', array_column(iterator_to_array((new Item_Ticket())->find(['tickets_id' => $ticketsId, 'itemtype' => 'Computer']), false), 'items_id'));
       $this->assertEqualsCanonicalizing($computerIds, $linked);
   }

   public function testPreparingAgainKeepsTheSameTicketAndLinksNewItems(): void {
       global $DB;
       [$entityId, $usersId] = $this->personWithComputers('Emma', 1);
       $first = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);
       $extra = $this->createTestComputer($entityId, 'PHPUnit PC ticket Emma ajout');
       $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $extra->getID()]);

       $second = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_DEACTIVATION);

       $ticketsId = (int) $first->fields['tickets_id'];
       $this->assertSame($ticketsId, (int) $second->fields['tickets_id']);
       $this->assertSame(1, countElementsInTable(Ticket::getTable(), ['id' => $ticketsId]));
       $this->assertSame(1, countElementsInTable(Item_Ticket::getTable(), ['tickets_id' => $ticketsId, 'itemtype' => 'Computer', 'items_id' => $extra->getID()]));
   }

   public function testNoTicketWhenTheOptionIsOff(): void {
       [, $usersId] = $this->personWithComputers('Fanny', 1, false);

       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);

       $this->assertSame(0, (int) $departure->fields['tickets_id']);
   }

   public function testCancellingAddsAFollowupWithoutTouchingTheTicketStatus(): void {
       [, $usersId] = $this->personWithComputers('Gaston', 1);
       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);
       $ticketsId = (int) $departure->fields['tickets_id'];
       $ticket = new Ticket();
       $ticket->getFromDB($ticketsId);
       $status = (int) $ticket->fields['status'];

       $departure->cancel();

       $this->assertSame(1, $this->followupCount($ticketsId));
       $ticket->getFromDB($ticketsId);
       $this->assertSame($status, (int) $ticket->fields['status']);
   }

   public function testDepartureDayListsTheItemsStillAssignedOnce(): void {
       [, $usersId, $computerIds] = $this->personWithComputers('Hugo', 2, true, date('Y-m-d H:i:s', strtotime('-1 day')));
       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);
       $ticketsId = (int) $departure->fields['tickets_id'];

       DepartureTicket::runDepartureDayFollowups();
       DepartureTicket::runDepartureDayFollowups();

       $this->assertSame(1, $this->followupCount($ticketsId), 'un seul suivi le jour du départ');
       $followup = current(iterator_to_array((new ITILFollowup())->find(['itemtype' => Ticket::class, 'items_id' => $ticketsId]), false));
       $this->assertStringContainsString('PHPUnit PC ticket Hugo 1', html_entity_decode((string) $followup['content']));
       $computer = new \Computer();
       $computer->getFromDB($computerIds[0]);
       $this->assertSame($usersId, (int) $computer->fields['users_id'], 'rien n\'est désaffecté');
   }

   public function testNoFollowupOnASolvedTicket(): void {
       [, $usersId] = $this->personWithComputers('Ines', 1);
       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);
       $ticketsId = (int) $departure->fields['tickets_id'];
       global $DB;
       $DB->update(Ticket::getTable(), ['status' => Ticket::SOLVED], ['id' => $ticketsId]);

       $departure->cancel();

       $this->assertSame(0, $this->followupCount($ticketsId), 'pas de suivi qui rouvrirait le ticket');
       $ticket = new Ticket();
       $ticket->getFromDB($ticketsId);
       $this->assertSame(Ticket::SOLVED, (int) $ticket->fields['status']);
   }
}
