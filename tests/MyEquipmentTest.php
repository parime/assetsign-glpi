<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\MyEquipment;
use Item_Ticket;
use Ticket;

/**
 * Issue #194 : « Mon matériel » dans le portail self-service — matériel affecté, feuilles signées,
 * signalement d'un problème par ticket (sans modifier le matériel).
 */
final class MyEquipmentTest extends AssetsignTestCase
{
   private int $entityId;

   private int $usersId;

   protected function setUp(): void {
       parent::setUp();
       global $DB;
       $this->entityId = $this->createTestEntity(0, 'PHPUnit Mon materiel ' . mt_rand());
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $this->entityId]);
       $this->usersId = $this->createTestUser('Lea', 'Detentrice', ['entities_id' => $this->entityId]);
   }

   private function assignedComputer(int $usersId, string $name): \Computer {
       global $DB;
       $computer = $this->createTestComputer($this->entityId, $name);
       $DB->update('glpi_computers', ['users_id' => $usersId, 'serial' => 'SN-' . $name], ['id' => $computer->getID()]);
       $computer->getFromDB($computer->getID());
       return $computer;
   }

   /** Feuille signée avec un document GLPI (ligne directe, sans générer de PDF). */
   private function signedSheet(\Computer $computer, int $usersId, int $status = Assetsign::STATUS_SIGNED): Assetsign {
       global $DB;
       $DB->insert('glpi_documents', ['name' => 'PHPUnit signe', 'filename' => 'signe.pdf', 'filepath' => 'PDF/xx/signe.pdf', 'entities_id' => $this->entityId]);
       $documentsId = (int) $DB->insertId();
       $sheet = new Assetsign();
       $id = (int) $sheet->add([
           'entities_id' => $this->entityId, 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
           'users_id' => $usersId, 'type' => Assetsign::TYPE_HANDOVER, 'status' => $status,
       ]);
       $DB->update(Assetsign::getTable(), ['document_id_signed' => $documentsId, 'date_signed' => date('Y-m-d H:i:s')], ['id' => $id]);
       $sheet->getFromDB($id);
       return $sheet;
   }

   public function testListsMyEquipmentWithMySignedDocumentsOnly(): void {
       $mine = $this->assignedComputer($this->usersId, 'PHPUNIT-MIEN');
       $sheet = $this->signedSheet($mine, $this->usersId);
       $other = $this->createTestUser('Max', 'Autre', ['entities_id' => $this->entityId]);
       $this->assignedComputer($other, 'PHPUNIT-PAS-A-MOI');

       $rows = array_column(MyEquipment::forUser($this->usersId), null, 'name');

       $this->assertArrayHasKey('PHPUNIT-MIEN', $rows);
       $this->assertArrayNotHasKey('PHPUNIT-PAS-A-MOI', $rows);
       $this->assertSame([$sheet->getID()], array_column($rows['PHPUNIT-MIEN']['documents'], 'id'));
   }

   public function testAnItemHandedWithAnotherShowsThatSignedSheet(): void {
       $pc = $this->assignedComputer($this->usersId, 'PHPUNIT-PC');
       $monitor = $this->assignedComputer($this->usersId, 'PHPUNIT-ECRAN');
       $sheet = $this->signedSheet($pc, $this->usersId);
       global $DB;
       $DB->insert(AssetsignItem::getTable(), ['plugin_assetsign_assetsigns_id' => $sheet->getID(), 'itemtype' => 'Computer', 'items_id' => $monitor->getID()]);

       $rows = array_column(MyEquipment::forUser($this->usersId), null, 'name');

       $this->assertSame([$sheet->getID()], array_column($rows['PHPUNIT-ECRAN']['documents'], 'id'));
   }

   public function testTheSignedPdfIsOnlyServedToItsSigner(): void {
       $computer = $this->assignedComputer($this->usersId, 'PHPUNIT-PDF');
       $sheet = $this->signedSheet($computer, $this->usersId);
       $pending = $this->signedSheet($computer, $this->usersId, Assetsign::STATUS_SENT);

       $this->assertNotNull(MyEquipment::signedDocumentFor($sheet->getID(), $this->usersId));
       $this->assertNull(MyEquipment::signedDocumentFor($sheet->getID(), $this->createTestUser('Eve', 'Curieuse')));
       $this->assertNull(MyEquipment::signedDocumentFor($pending->getID(), $this->usersId), 'pas encore signée');
   }

   public function testReportingAProblemOpensATicketLinkedToTheItemWithoutChangingIt(): void {
       $computer = $this->assignedComputer($this->usersId, 'PHPUNIT-PANNE');

       $ticketsId = MyEquipment::reportProblem($this->usersId, 'Computer:' . $computer->getID(), MyEquipment::PROBLEM_BREAKDOWN, "L'écran reste noir.");

       $ticket = new Ticket();
       $this->assertTrue($ticket->getFromDB($ticketsId));
       $this->assertSame(Ticket::INCIDENT_TYPE, (int) $ticket->fields['type']);
       $this->assertStringContainsString('PHPUNIT-PANNE', $ticket->fields['name']);
       $this->assertStringContainsString('reste noir', html_entity_decode((string) $ticket->fields['content'], ENT_QUOTES));
       $this->assertSame(1, countElementsInTable(Item_Ticket::getTable(), ['tickets_id' => $ticketsId, 'itemtype' => 'Computer', 'items_id' => $computer->getID()]));
       $requesters = (new \Ticket_User())->find(['tickets_id' => $ticketsId, 'type' => \CommonITILActor::REQUESTER]);
       $this->assertSame([$this->usersId], array_map('intval', array_column(iterator_to_array($requesters, false), 'users_id')));
       $computer->getFromDB($computer->getID());
       $this->assertSame($this->usersId, (int) $computer->fields['users_id'], 'le matériel n\'est pas modifié');
   }

   public function testReportingIsRefusedForAnItemThatIsNotMine(): void {
       $other = $this->assignedComputer($this->createTestUser('Zoe', 'Autre', ['entities_id' => $this->entityId]), 'PHPUNIT-AUTRUI');

       $this->expectException(\RuntimeException::class);
       MyEquipment::reportProblem($this->usersId, 'Computer:' . $other->getID(), MyEquipment::PROBLEM_THEFT, '');
   }

   public function testReportingNeedsAProblemType(): void {
       $computer = $this->assignedComputer($this->usersId, 'PHPUNIT-SANS-TYPE');

       $this->expectException(\RuntimeException::class);
       MyEquipment::reportProblem($this->usersId, 'Computer:' . $computer->getID(), 'autre', '');
   }
}
