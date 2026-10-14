<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Loan;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use Item_Ticket;
use Ticket;

/**
 * Issue #193 : prêt temporaire — attribution avec une date de retour prévue, rappel avant
 * l'échéance, ticket en cas de retard. Jamais de désaffectation ni de changement de statut.
 */
final class LoanTest extends AssetsignTestCase
{
   private int $entityId;

   private int $usersId;

   protected function setUp(): void {
       parent::setUp();
       global $DB;
       $this->entityId = $this->createTestEntity(0, 'PHPUnit Pret ' . mt_rand());
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $this->entityId, 'loan_reminder_days' => 3, 'loan_overdue_ticket' => 1]);
       $this->usersId = $this->createTestUser('Paul', 'Emprunteur', ['entities_id' => $this->entityId]);
   }

   /** Attribution signée d'un ordinateur affecté à l'emprunteur. */
   private function loan(?string $until, int $status = Assetsign::STATUS_SIGNED): Assetsign {
       global $DB;
       $computer = $this->createTestComputer($this->entityId, 'PHPUnit PC pret ' . mt_rand());
       $DB->update('glpi_computers', ['users_id' => $this->usersId], ['id' => $computer->getID()]);
       $sheet = new Assetsign();
       $id = (int) $sheet->add([
           'entities_id'   => $this->entityId,
           'itemtype'      => 'Computer',
           'items_id'      => $computer->getID(),
           'users_id'      => $this->usersId,
           'users_id_tech' => 2,
           'type'          => Assetsign::TYPE_HANDOVER,
           'status'        => $status,
       ]);
       $DB->update(Assetsign::getTable(), ['loan_until' => $until], ['id' => $id]);
       $sheet->getFromDB($id);
       return $sheet;
   }

   public function testSettingAReturnDateMakesALoanAndAnEmptyDateMakesItDefinitive(): void {
       $sheet = $this->loan(null);

       $sheet->setLoanUntil(date('Y-m-d', strtotime('+10 days')));
       $this->assertSame(date('Y-m-d', strtotime('+10 days')), $sheet->fields['loan_until']);

       $sheet->setLoanUntil('');
       $this->assertNull($sheet->fields['loan_until']);
   }

   public function testAPastDateOrAnotherSheetTypeIsRefused(): void {
       $sheet = $this->loan(null);
       try {
           $sheet->setLoanUntil(date('Y-m-d', strtotime('-1 day')));
           $this->fail('date passée acceptée');
       } catch (\RuntimeException) {
           $this->assertNull($sheet->fields['loan_until']);
       }

       $return = $this->createBareAssetsign($this->entityId, Assetsign::TYPE_RETURN);
       $this->expectException(\RuntimeException::class);
       $return->setLoanUntil(date('Y-m-d', strtotime('+5 days')));
   }

   public function testTheReminderIsSentOnceBeforeTheDueDateAndRearmedByANewDate(): void {
       $sheet = $this->loan(date('Y-m-d', strtotime('+2 days')));
       $far = $this->loan(date('Y-m-d', strtotime('+20 days')));

       Loan::runReminders();
       Loan::runReminders();

       $sheet->getFromDB($sheet->getID());
       $far->getFromDB($far->getID());
       $this->assertNotEmpty($sheet->fields['loan_reminder_sent_at']);
       $this->assertEmpty($far->fields['loan_reminder_sent_at'], 'échéance encore lointaine');

       $sheet->setLoanUntil(date('Y-m-d', strtotime('+15 days')));
       $this->assertEmpty($sheet->fields['loan_reminder_sent_at'], 'nouvelle échéance : nouveau rappel');
   }

   public function testAnOverdueLoanOpensOneTicketWithoutUnassigningAnything(): void {
       $sheet = $this->loan(date('Y-m-d', strtotime('-3 days')));

       Loan::runOverdueTickets();
       Loan::runOverdueTickets();

       $sheet->getFromDB($sheet->getID());
       $ticketsId = (int) $sheet->fields['loan_tickets_id'];
       $this->assertGreaterThan(0, $ticketsId);
       $this->assertSame(1, countElementsInTable(Ticket::getTable(), ['id' => $ticketsId]));
       $this->assertSame(1, countElementsInTable(Item_Ticket::getTable(), ['tickets_id' => $ticketsId, 'itemtype' => 'Computer', 'items_id' => (int) $sheet->fields['items_id']]));
       $this->assertSame(Assetsign::STATUS_SIGNED, (int) $sheet->fields['status']);
       $computer = new \Computer();
       $computer->getFromDB((int) $sheet->fields['items_id']);
       $this->assertSame($this->usersId, (int) $computer->fields['users_id'], 'rien n\'est désaffecté');
   }

   public function testAReturnedLoanIsNeitherRemindedNorTicketed(): void {
       global $DB;
       $sheet = $this->loan(date('Y-m-d', strtotime('-3 days')));
       $DB->update('glpi_computers', ['users_id' => 0], ['id' => (int) $sheet->fields['items_id']]);

       $this->assertTrue(Loan::isReturned($sheet->fields));
       Loan::runOverdueTickets();

       $sheet->getFromDB($sheet->getID());
       $this->assertSame(0, (int) $sheet->fields['loan_tickets_id']);
   }

   public function testNoTicketWhenTheOptionIsOff(): void {
       $config = Config::getForEntity($this->entityId);
       $config->update(['id' => $config->getID(), 'loan_overdue_ticket' => 0] + $config->fields);
       $sheet = $this->loan(date('Y-m-d', strtotime('-3 days')));

       Loan::runOverdueTickets();

       $sheet->getFromDB($sheet->getID());
       $this->assertSame(0, (int) $sheet->fields['loan_tickets_id']);
   }

   public function testActiveLoansListOverdueFirstAndSkipReturnedOnes(): void {
       global $DB;
       $late = $this->loan(date('Y-m-d', strtotime('-1 day')));
       $soon = $this->loan(date('Y-m-d', strtotime('+4 days')));
       $returned = $this->loan(date('Y-m-d', strtotime('+1 day')));
       $DB->update('glpi_computers', ['users_id' => 0], ['id' => (int) $returned->fields['items_id']]);
       $definitive = $this->loan(null);

       $loans = array_column(Loan::getActiveLoans(500), null, 'id');

       $this->assertTrue($loans[$late->getID()]['overdue']);
       $this->assertFalse($loans[$soon->getID()]['overdue']);
       $this->assertArrayNotHasKey($returned->getID(), $loans);
       $this->assertArrayNotHasKey($definitive->getID(), $loans);
       $ids = array_keys($loans);
       $this->assertLessThan(array_search($soon->getID(), $ids, true), array_search($late->getID(), $ids, true));
   }

   public function testTheDocumentShowsTheReturnDate(): void {
       $sheet = $this->loan(date('Y-m-d', strtotime('+10 days')), Assetsign::STATUS_PENDING);

       $html = (new HandoverPdfBuilder())->renderHtml($sheet);

       $this->assertStringContainsString('Prêt temporaire', $html);
       $this->assertStringContainsString(date('d/m/Y', strtotime('+10 days')), $html);
   }
}
