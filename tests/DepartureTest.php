<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Departure;
use GlpiPlugin\Assetsign\DepartureLogic;
use GlpiPlugin\Assetsign\Signature;
use GlpiPlugin\Assetsign\Token;

/**
 * Issue #157 : dossier de départ — une restitution groupée pour tout le matériel d'une personne.
 */
final class DepartureTest extends AssetsignTestCase
{
   private static function fakeSignaturePng(): string {
       $image = imagecreatetruecolor(200, 80);
       imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
       $ink = imagecolorallocate($image, 0, 0, 0);
       imageline($image, 20, 40, 180, 40, $ink);
       imageline($image, 66, 15, 100, 65, $ink);
       ob_start();
       imagepng($image);
       $binary = ob_get_clean();
       imagedestroy($image);

       return 'data:image/png;base64,' . base64_encode($binary);
   }

   /**
    * Personne + matériels affectés par écriture directe (sans passer par les hooks
    * d'affectation, qui créeraient des remises hors sujet ici).
    *
    * @return array{0: int, 1: int, 2: list<int>}
    */
   private function personWithComputers(string $name, int $count): array {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Depart ' . $name);
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $usersId = $this->createTestUser($name, 'Partant', ['entities_id' => $entityId]);
       $computerIds = [];
       for ($i = 1; $i <= $count; $i++) {
           $computer = $this->createTestComputer($entityId, "PHPUnit PC depart $name $i");
           $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $computer->getID()]);
           $computerIds[] = $computer->getID();
       }
       return [$entityId, $usersId, $computerIds];
   }

   public function testPrepareGroupsEveryAssignedItemWithoutIndividualInvitations(): void {
       [, $usersId, $computerIds] = $this->personWithComputers('Alice', 2);

       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);

       $this->assertNotNull($departure);
       $children = $departure->getChildren();
       $this->assertCount(2, $children);
       $this->assertEqualsCanonicalizing($computerIds, array_map('intval', array_column($children, 'items_id')));
       foreach ($children as $child) {
           $this->assertSame(Assetsign::TYPE_RETURN, (int) $child['type']);
           $this->assertSame(Assetsign::STATUS_SENT, (int) $child['status']);
           $this->assertGreaterThan(0, (int) $child['document_id_unsigned'], 'PDF de restitution de chaque fiche');
           $this->assertSame(0, countElementsInTable(Token::getTable(), ['plugin_assetsign_assetsigns_id' => $child['id']]), 'aucun lien de signature individuel');
       }
   }

   public function testPreparingTwiceNeverDuplicates(): void {
       [, $usersId] = $this->personWithComputers('Bruno', 2);

       $first = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);
       $second = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_DEACTIVATION);

       $this->assertSame($first->getID(), $second->getID());
       $this->assertCount(2, $second->getChildren());
       $this->assertSame(1, countElementsInTable(Departure::getTable(), ['users_id' => $usersId]));
   }

   public function testNoEquipmentMeansNoDeparture(): void {
       [, $usersId] = $this->personWithComputers('Chloe', 0);

       $this->assertNull(Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL));
   }

   public function testAPendingReturnIsAttachedInsteadOfDuplicated(): void {
       [$entityId, $usersId, $computerIds] = $this->personWithComputers('David', 1);
       $existing = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT, $usersId);
       global $DB;
       $DB->update(Assetsign::getTable(), ['itemtype' => 'Computer', 'items_id' => $computerIds[0]], ['id' => $existing->getID()]);

       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);

       $this->assertSame([$existing->getID()], array_map('intval', array_column($departure->getChildren(), 'id')));
   }

   public function testOneSignatureSignsEveryItemAndProducesTheSummary(): void {
       [, $usersId] = $this->personWithComputers('Emma', 2);
       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);

       $departure->sign(self::fakeSignaturePng(), ['ip' => '192.0.2.1', 'user_agent' => 'PHPUnit']);

       $departure->getFromDB($departure->getID());
       $this->assertSame(DepartureLogic::STATUS_SIGNED, (int) $departure->fields['status']);
       $this->assertGreaterThan(0, (int) $departure->fields['document_id_summary']);
       foreach ($departure->getChildren() as $child) {
           $this->assertSame(Assetsign::STATUS_SIGNED, (int) $child['status']);
           $this->assertGreaterThan(0, (int) $child['document_id_signed']);
           $this->assertSame(1, countElementsInTable(Signature::getTable(), ['plugin_assetsign_assetsigns_id' => $child['id']]));
       }

       // Le matériel déjà rendu n'est jamais redemandé (ex. compte désactivé le dernier jour).
       $this->assertNull(Departure::prepareFor($usersId, DepartureLogic::TRIGGER_DEACTIVATION));
   }

   public function testAnIndividualReturnIsSkippedWhileTheDepartureIsOpen(): void {
       [, $usersId, $computerIds] = $this->personWithComputers('Farid', 1);
       $computer = new \Computer();
       $computer->getFromDB($computerIds[0]);

       $this->assertFalse(Assetsign::isInOpenDeparture($computer));
       $departure = Departure::prepareFor($usersId, DepartureLogic::TRIGGER_MANUAL);
       $this->assertTrue(Assetsign::isInOpenDeparture($computer));

       $departure->cancel();
       $departure->getFromDB($departure->getID());
       $this->assertSame(DepartureLogic::STATUS_CANCELLED, (int) $departure->fields['status']);
       $this->assertSame([Assetsign::STATUS_CANCELLED], array_values(array_unique(array_map('intval', array_column($departure->getChildren(), 'status')))));
       $this->assertFalse(Assetsign::isInOpenDeparture($computer));
   }

   public function testDeactivationTriggerRespectsTheConfiguration(): void {
       global $DB;
       [$entityId, $usersId] = $this->personWithComputers('Gaelle', 1);
       $user = new \User();

       $user->update(['id' => $usersId, 'is_active' => 0]);
       $this->assertNull(Departure::findOpenFor($usersId), 'déclencheur désactivé par défaut');

       $user->update(['id' => $usersId, 'is_active' => 1]);
       $DB->update('glpi_plugin_assetsign_configs', ['departure_on_deactivation' => 1], ['entities_id' => $entityId]);
       $user->update(['id' => $usersId, 'is_active' => 0]);
       $departure = Departure::findOpenFor($usersId);
       $this->assertNotNull($departure);
       $this->assertSame(DepartureLogic::TRIGGER_DEACTIVATION, (int) $departure->fields['trigger_type']);
   }
}
