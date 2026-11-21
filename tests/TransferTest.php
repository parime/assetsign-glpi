<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Transfer;

/**
 * Issue #370 : passation directe d'un detenteur a un autre. Deux fiches jumelles (restitution du
 * sortant, remise a l'entrant) ; l'attribution GLPI ne change qu'apres les deux signatures.
 */
final class TransferTest extends AssetsignTestCase
{
    /** @var array<string, mixed> */
   private array $savedProfile = [];

   private int $entity;
   private int $outgoing;
   private int $incoming;

   protected function setUp(): void {
       parent::setUp();
       $this->savedProfile = $_SESSION['glpiactiveprofile'];
       $this->entity = $this->createTestEntity(0, 'PHPUnit Passation');
       Config::upsertForEntity($this->entity, [
           'sign_on_assignment'   => 1,
           'sign_on_return'       => 1,
           'sign_on_reassignment' => 1,
           'assign_handed_items'  => 1,
           'handed_items_state'   => Config::HANDED_STATE_UNCHANGED,
           'managed_itemtypes'    => json_encode(['Computer', 'Monitor']),
       ]);
       $this->outgoing = $this->createTestUser('Alex', 'Sortant');
       $this->incoming = $this->createTestUser('Sam', 'Entrant');
   }

   protected function tearDown(): void {
       $_SESSION['glpiactiveprofile'] = $this->savedProfile;
       parent::tearDown();
   }

   private function computerHeldBy(int $usersId, string $name): \Computer {
       global $DB;
       $computer = $this->createTestComputer($this->entity, $name);
       $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $computer->getID()]);
       $computer->getFromDB($computer->getID());
       return $computer;
   }

   private function holderOf(\CommonDBTM $item): int {
       $item->getFromDB($item->getID());
       return (int) $item->fields['users_id'];
   }

   private function setStatus(Assetsign $sheet, int $status): void {
       $sheet->update(['id' => $sheet->getID(), 'status' => $status]);
       $sheet->getFromDB($sheet->getID());
   }

   public function testIsCompleteOnlyWhenBothSheetsAreSigned(): void {
       $this->assertTrue(Transfer::isComplete(Assetsign::STATUS_SIGNED, Assetsign::STATUS_SIGNED));
       $this->assertTrue(Transfer::isComplete(Assetsign::STATUS_SIGNED_ON_PAPER, Assetsign::STATUS_SIGNED));
       $this->assertFalse(Transfer::isComplete(Assetsign::STATUS_SIGNED, Assetsign::STATUS_SENT));
       $this->assertFalse(Transfer::isComplete(Assetsign::STATUS_VIEWED, Assetsign::STATUS_SIGNED));
       $this->assertFalse(Transfer::isComplete(Assetsign::STATUS_SIGNED, Assetsign::STATUS_AWAITING_COSIGNATURE));
       $this->assertFalse(Transfer::isComplete(Assetsign::STATUS_SIGNED, Assetsign::STATUS_CANCELLED));
   }

   public function testCreatesTwoLinkedSheetsWithoutChangingTheGlpiAssignment(): void {
       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-1');

       ['return' => $return, 'handover' => $handover] = Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);

       $this->assertSame(Assetsign::TYPE_RETURN, (int) $return->fields['type']);
       $this->assertSame($this->outgoing, (int) $return->fields['users_id']);
       $this->assertSame(Assetsign::TYPE_HANDOVER, (int) $handover->fields['type']);
       $this->assertSame($this->incoming, (int) $handover->fields['users_id']);
       $return->getFromDB($return->getID());
       $this->assertSame($handover->getID(), (int) $return->fields['transfer_peer_id']);
       $this->assertSame($return->getID(), (int) $handover->fields['transfer_peer_id']);
       $this->assertSame(Assetsign::STATUS_SENT, (int) $return->fields['status']);
       $this->assertSame(Assetsign::STATUS_SENT, (int) $handover->fields['status']);
       $this->assertGreaterThan(0, (int) $return->fields['document_id_unsigned'], 'PDF de restitution genere');
       $this->assertGreaterThan(0, (int) $handover->fields['document_id_unsigned'], 'PDF de remise genere');
       $this->assertSame($this->outgoing, $this->holderOf($computer), 'Rien ne change dans GLPI avant les signatures.');
   }

   public function testGlpiAssignmentFollowsOnlyAfterBothSignaturesInAnyOrder(): void {
      foreach ([[true, 'PHPUNIT-PASSATION-2'], [false, 'PHPUNIT-PASSATION-3']] as [$returnFirst, $name]) {
          $computer = $this->computerHeldBy($this->outgoing, $name);
          ['return' => $return, 'handover' => $handover] = Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);
          $sheetsBefore = count((new Assetsign())->find(['itemtype' => 'Computer', 'items_id' => $computer->getID()]));

          $this->setStatus($returnFirst ? $return : $handover, Assetsign::STATUS_SIGNED);
          $this->assertSame($this->outgoing, $this->holderOf($computer), 'Une seule signature : toujours au sortant.');

          $this->setStatus($returnFirst ? $handover : $return, Assetsign::STATUS_SIGNED_ON_PAPER);
          $this->assertSame($this->incoming, $this->holderOf($computer), 'Deux signatures : attribue a l\'entrant.');
          $this->assertSame(
              $sheetsBefore,
              count((new Assetsign())->find(['itemtype' => 'Computer', 'items_id' => $computer->getID()])),
              'Le changement d\'utilisateur fait par la passation ne cree pas de nouvelle fiche.'
          );
          $data = Transfer::getDisplayData($handover);
          $this->assertNotNull($data);
          $this->assertTrue($data['complete']);
      }
   }

   public function testHandedItemGoesWithTheMainItemAfterBothSignatures(): void {
       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-4');
       $monitor = new \Monitor();
       $monitor->getFromDB((int) $monitor->add(['name' => 'PHPUNIT-PASSATION-ECRAN', 'entities_id' => $this->entity, 'users_id' => $this->outgoing]));
       $previous = new Assetsign();
       $previous->getFromDB((int) $previous->add([
           'entities_id' => $this->entity, 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
           'users_id' => $this->outgoing, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
       ]));
       AssetsignItem::attach($previous, 'Monitor', $monitor->getID());
       global $DB;
       $DB->update(Assetsign::getTable(), ['status' => Assetsign::STATUS_SIGNED], ['id' => $previous->getID()]);

       ['return' => $return, 'handover' => $handover] = Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);

       $this->assertSame(['Monitor'], array_column(AssetsignItem::getForAssetsign($return->getID()), 'itemtype'));
       $this->assertSame(['Monitor'], array_column(AssetsignItem::getForAssetsign($handover->getID()), 'itemtype'));
       $this->assertSame($this->outgoing, $this->holderOf($monitor), 'Envoi : l\'ecran reste au sortant.');

       $this->setStatus($return, Assetsign::STATUS_SIGNED);
       $this->setStatus($handover, Assetsign::STATUS_SIGNED);

       $this->assertSame($this->incoming, $this->holderOf($monitor));
   }

   public function testCancellingOneSheetCancelsTheOtherAndKeepsTheHolder(): void {
       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-5');
       ['return' => $return, 'handover' => $handover] = Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);

       $handover->cancelRequest();

       $return->getFromDB($return->getID());
       $this->assertSame(Assetsign::STATUS_CANCELLED, (int) $return->fields['status']);
       $this->setStatus($return, Assetsign::STATUS_SIGNED);
       $this->assertSame($this->outgoing, $this->holderOf($computer), 'Passation annulee : aucune attribution.');
       $data = Transfer::getDisplayData($return);
       $this->assertNotNull($data);
       $this->assertTrue($data['aborted']);
   }

   public function testRefusedWithoutHolderForTheSamePersonOrWhenDisabled(): void {
       $free = $this->createTestComputer($this->entity, 'PHPUNIT-PASSATION-6');
      try {
          Assetsign::createTransfer('Computer', $free->getID(), $this->incoming);
          $this->fail('Sans detenteur, pas de passation.');
      } catch (\RuntimeException $e) {
          $this->assertSame(0, count((new Assetsign())->find(['itemtype' => 'Computer', 'items_id' => $free->getID()])));
      }

       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-7');
      try {
          Assetsign::createTransfer('Computer', $computer->getID(), $this->outgoing);
          $this->fail('Le detenteur actuel ne peut pas etre l\'entrant.');
      } catch (\InvalidArgumentException $e) {
          $this->assertSame(0, count((new Assetsign())->find(['itemtype' => 'Computer', 'items_id' => $computer->getID()])));
      }

       Config::upsertForEntity($this->entity, ['sign_on_reassignment' => 0]);
       $this->expectException(\RuntimeException::class);
       Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);
   }

   public function testRefusedWithoutTheRightToUpdateTheItem(): void {
       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-8');
       $_SESSION['glpiactiveprofile']['computer'] = READ;

       $cards = array_column(Assetsign::actionCards($computer)['cards'], null, 'type');
       $this->assertNotSame('', $cards['transfer']['reason']);

       $this->expectException(\RuntimeException::class);
       Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);
   }

   public function testTransferCardIsOfferedWhenTheItemHasAHolder(): void {
       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-9');

       $cards = array_column(Assetsign::actionCards($computer)['cards'], null, 'type');

       $this->assertSame('transfer', $cards['transfer']['action']);
       $this->assertSame('', $cards['transfer']['reason']);
       $this->assertSame(Transfer::getIcon(), $cards['transfer']['icon']);
   }

   public function testSheetPageShowsTheTransferBoxWithBothSheets(): void {
       $computer = $this->computerHeldBy($this->outgoing, 'PHPUNIT-PASSATION-10');
       ['return' => $return, 'handover' => $handover] = Assetsign::createTransfer('Computer', $computer->getID(), $this->incoming);

       ob_start();
       $handover->showForm($handover->getID());
       $html = (string) ob_get_clean();

       $this->assertStringContainsString('id="assetsign-transfer"', $html);
       $this->assertStringContainsString($return->getLinkURL(), $html);
       $this->assertNoStrayNumericTextNode($html, 'assetsign_form.html.twig (encadre Passation)');
   }
}
