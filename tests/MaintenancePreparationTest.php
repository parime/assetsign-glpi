<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\MaintenanceFormController;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;

/**
 * Issue #223 : preparation de poste (masterisation) — points obligatoires, rattachement au
 * ticket du nouvel arrivant (suivi prive, statut inchange) et controle des photos jointes.
 */
class MaintenancePreparationTest extends AssetsignTestCase
{
   private function createItem(string $name, int $usage, bool $mandatory): int {
       return (int) (new MaintenanceChecklistItem())->add([
           'entities_id'     => 0,
           'is_recursive'    => 1,
           'name'            => $name,
           'is_active'       => 1,
           'type'            => MaintenanceChecklistItem::TYPE_CHECKBOX,
           'checklist_usage' => $usage,
           'is_mandatory'    => $mandatory ? 1 : 0,
       ]);
   }

    /** @return array<int, string> Tous les points de preparation actifs, coches. */
   private function allPreparationChecked(): array {
       return array_fill_keys(array_keys(Maintenance::getActiveChecklistItems(Maintenance::KIND_PREPARATION)), '1');
   }

   public function testChecklistItemsAreFilteredByUsage(): void {
       $maintenanceOnly = $this->createItem('PHPUnit maintenance seule', MaintenanceChecklistItem::USAGE_MAINTENANCE, false);
       $preparationOnly = $this->createItem('PHPUnit préparation seule', MaintenanceChecklistItem::USAGE_PREPARATION, true);
       $both = $this->createItem('PHPUnit les deux', MaintenanceChecklistItem::USAGE_BOTH, false);

       $maintenance = Maintenance::getActiveChecklistItems();
       $preparation = Maintenance::getActiveChecklistItems(Maintenance::KIND_PREPARATION);

       $this->assertArrayHasKey($maintenanceOnly, $maintenance);
       $this->assertArrayNotHasKey($preparationOnly, $maintenance);
       $this->assertArrayHasKey($both, $maintenance);
       $this->assertArrayHasKey($preparationOnly, $preparation);
       $this->assertArrayNotHasKey($maintenanceOnly, $preparation);
       $this->assertTrue($preparation[$preparationOnly]['mandatory']);
   }

   public function testMissingMandatoryPoints(): void {
       $items = [
           1 => ['name' => 'BIOS', 'type' => MaintenanceChecklistItem::TYPE_CHECKBOX, 'mandatory' => true],
           2 => ['name' => 'Numéro', 'type' => MaintenanceChecklistItem::TYPE_TEXT, 'mandatory' => true],
           3 => ['name' => 'Facultatif', 'type' => MaintenanceChecklistItem::TYPE_CHECKBOX, 'mandatory' => false],
       ];
       $this->assertSame(['BIOS', 'Numéro'], Maintenance::missingMandatory($items, []));
       $this->assertSame(['Numéro'], Maintenance::missingMandatory($items, [1 => '1', 2 => '  ']));
       $this->assertSame([], Maintenance::missingMandatory($items, ['1' => '1', '2' => 'PC-042']));
   }

   public function testPreparationIsRefusedWhileAMandatoryPointIsMissing(): void {
       $this->createItem('PHPUnit obligatoire', MaintenanceChecklistItem::USAGE_PREPARATION, true);
       $entityId = $this->createTestEntity(0, 'PHPUnit Préparation refus');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC préparation refus');

      try {
          Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '', [], null, [], Maintenance::KIND_PREPARATION);
          $this->fail('Une préparation incomplète ne doit pas être enregistrée.');
      } catch (\RuntimeException $e) {
          $this->assertStringContainsString('PHPUnit obligatoire', $e->getMessage());
      }
       $this->assertSame(0, countElementsInTable(Maintenance::getTable(), ['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
   }

   public function testPreparationIsReportedOnTheTicketWithoutChangingIt(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Préparation ticket');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC nouvel arrivant');
       $ticket = new \Ticket();
       $ticketsId = (int) $ticket->add([
           'name'        => 'PHPUnit arrivée de Camille Démo',
           'content'     => 'Préparer le poste',
           'entities_id' => $entityId,
       ]);
       (new \Item_Ticket())->add(['tickets_id' => $ticketsId, 'itemtype' => 'Computer', 'items_id' => $computer->getID()]);
       $ticket->getFromDB($ticketsId);
       $status = (int) $ticket->fields['status'];

       $this->assertArrayHasKey($ticketsId, Maintenance::getOpenTicketsFor($computer));

       $id = Maintenance::createWithChecklist(
           'Computer',
           $computer->getID(),
           $entityId,
           $this->allPreparationChecked(),
           'Poste prêt',
           [],
           null,
           [],
           Maintenance::KIND_PREPARATION,
           $ticketsId
       );
       $this->assertGreaterThan(0, $id);

       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);
       $this->assertSame(Maintenance::KIND_PREPARATION, (int) $maintenance->fields['kind']);
       $this->assertSame($ticketsId, (int) $maintenance->fields['tickets_id']);
       $this->assertStringContainsString(__('Préparation de poste', 'assetsign'), $maintenance->getDocumentTitle());

       $followups = (new \ITILFollowup())->find(['itemtype' => \Ticket::class, 'items_id' => $ticketsId]);
       $this->assertCount(1, $followups);
       $this->assertSame(1, (int) current($followups)['is_private']);
       $this->assertSame(1, countElementsInTable('glpi_documents_items', [
           'itemtype'     => \Ticket::class,
           'items_id'     => $ticketsId,
           'documents_id' => (int) $maintenance->fields['document_id'],
       ]), 'Le PDF de la fiche est joint au ticket.');

       $ticket->getFromDB($ticketsId);
       $this->assertSame($status, (int) $ticket->fields['status'], 'Statut du ticket inchangé.');

       // Onglet du ticket.
       $this->assertSame(1, countElementsInTable(Maintenance::getTable(), ['tickets_id' => $ticketsId]));
       ob_start();
       Maintenance::displayTabContentForItem($ticket);
       $html = (string) ob_get_clean();
       $this->assertStringContainsString('maintenance.form.php?id=' . $id, $html);
       $this->assertStringContainsString('PHPUnit PC nouvel arrivant', $html);
   }

   public function testOnlyPhotosAreAccepted(): void {
       $this->assertTrue(Maintenance::isAcceptablePhoto(['name' => 'poste.jpg', 'error' => UPLOAD_ERR_OK]));
       $this->assertTrue(Maintenance::isAcceptablePhoto(['name' => 'IMG_0042.PNG', 'error' => UPLOAD_ERR_OK]));
       $this->assertFalse(Maintenance::isAcceptablePhoto(['name' => 'script.php', 'error' => UPLOAD_ERR_OK]));
       $this->assertFalse(Maintenance::isAcceptablePhoto(['name' => 'notes.pdf', 'error' => UPLOAD_ERR_OK]));
       $this->assertFalse(Maintenance::isAcceptablePhoto(['name' => 'poste.png', 'error' => UPLOAD_ERR_PARTIAL]));

       $entityId = $this->createTestEntity(0, 'PHPUnit Préparation photo');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC photo');
       $this->expectException(\RuntimeException::class);
       Maintenance::createWithChecklist(
           'Computer',
           $computer->getID(),
           $entityId,
           $this->allPreparationChecked(),
           '',
           [],
           null,
           [],
           Maintenance::KIND_PREPARATION,
           0,
           [['name' => 'script.php', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK]]
       );
   }

   public function testMultiplePhotoFieldIsNormalized(): void {
       $photos = MaintenanceFormController::normalizePhotos([
           'name'     => ['a.jpg', ''],
           'type'     => ['image/jpeg', ''],
           'tmp_name' => ['/tmp/php1', ''],
           'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
           'size'     => [1200, 0],
       ]);
       $this->assertCount(1, $photos);
       $this->assertSame('a.jpg', $photos[0]['name']);
       $this->assertSame([], MaintenanceFormController::normalizePhotos(null));
   }
}
