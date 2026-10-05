<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Attestation;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\CampaignLogic;

/**
 * Issue #158 : campagne d'attestation annuelle de détention du matériel.
 */
final class CampaignTest extends AssetsignTestCase
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
    * Personne habilitée sur l'entité, avec $count ordinateurs affectés par écriture directe
    * (sans passer par les hooks d'affectation).
    */
   private function holder(int $entityId, string $name, int $count): int {
       global $DB;
       $usersId = $this->createTestUser($name, 'Detenteur', ['entities_id' => $entityId]);
      if (countElementsInTable('glpi_profiles_users', ['users_id' => $usersId, 'entities_id' => $entityId]) === 0) {
          $DB->insert('glpi_profiles_users', ['users_id' => $usersId, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 0]);
      }
       for ($i = 1; $i <= $count; $i++) {
           $computer = $this->createTestComputer($entityId, "PHPUnit PC attestation $name $i");
           $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $computer->getID()]);
       }
       return $usersId;
   }

   private function launch(int $entityId, int $groupsId = 0): Campaign {
       return Campaign::launch([
           'name'          => 'PHPUnit campagne',
           'entities_id'   => $entityId,
           'is_recursive'  => false,
           'groups_id'     => $groupsId,
           'profiles_id'   => 0,
           'date_deadline' => date('Y-m-d', strtotime('+30 days')),
       ]);
   }

   private function attestationFor(Campaign $campaign, int $usersId): Attestation {
       $attestation = new Attestation();
       $attestation->getFromDBByCrit(['plugin_assetsign_campaigns_id' => $campaign->getID(), 'users_id' => $usersId]);
       return $attestation;
   }

   public function testOnlyHoldersInScopeReceiveAFrozenList(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Attestation Perimetre');
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $alice = $this->holder($entityId, 'Alice', 2);
       $this->holder($entityId, 'Bob', 0);

       $campaign = $this->launch($entityId);

       $this->assertSame(1, $campaign->getStats()['total'], 'une personne sans matériel n\'a rien à attester');
       $attestation = $this->attestationFor($campaign, $alice);
       $this->assertCount(2, $attestation->getItems());
       $this->assertSame(CampaignLogic::ATTESTATION_PENDING, (int) $attestation->fields['status']);
   }

   public function testGroupFilter(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Attestation Groupe');
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $inGroup = $this->holder($entityId, 'Chloe', 1);
       $this->holder($entityId, 'David', 1);
       $group = new \Group();
       $groupsId = (int) $group->add(['name' => 'PHPUnit groupe attestation', 'entities_id' => $entityId]);
       $DB->insert('glpi_groups_users', ['groups_id' => $groupsId, 'users_id' => $inGroup]);

       $campaign = $this->launch($entityId, $groupsId);

       $this->assertSame([$inGroup], array_map('intval', array_column($campaign->getAttestations(), 'users_id')));
   }

   public function testConfirmSignsAndStoresThePdf(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Attestation Signature');
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $emma = $this->holder($entityId, 'Emma', 1);
       $campaign = $this->launch($entityId);
       $attestation = $this->attestationFor($campaign, $emma);

       $attestation->confirm(self::fakeSignaturePng(), ['ip' => '192.0.2.1']);

       $attestation->getFromDB($attestation->getID());
       $this->assertSame(CampaignLogic::ATTESTATION_CONFIRMED, (int) $attestation->fields['status']);
       $this->assertGreaterThan(0, (int) $attestation->fields['documents_id']);
       $this->assertSame(64, strlen((string) $attestation->fields['signature_hash']));
       $this->assertSame(100, $campaign->getStats()['response_rate']);
   }

   public function testDiscrepancyOpensATicketForTheHolder(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Attestation Ecart');
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $farid = $this->holder($entityId, 'Farid', 2);
       $campaign = $this->launch($entityId);
       $attestation = $this->attestationFor($campaign, $farid);

       try {
           $attestation->reportDiscrepancy([CampaignLogic::GAP_MISSING], '   ');
           $this->fail('Un écart sans commentaire doit être refusé.');
       } catch (\RuntimeException $e) {
           $this->assertSame(CampaignLogic::ATTESTATION_PENDING, (int) $attestation->fields['status']);
       }

       $attestation->reportDiscrepancy([CampaignLogic::GAP_MISSING, 'bogus'], 'Un des deux PC a été rendu.');

       $attestation->getFromDB($attestation->getID());
       $this->assertSame(CampaignLogic::ATTESTATION_DISCREPANCY, (int) $attestation->fields['status']);
       $this->assertSame('["missing"]', $attestation->fields['gaps']);
       $ticketsId = (int) $attestation->fields['tickets_id'];
       $this->assertGreaterThan(0, $ticketsId);
       $this->assertSame(1, countElementsInTable('glpi_tickets_users', ['tickets_id' => $ticketsId, 'users_id' => $farid, 'type' => 1]));
       $this->assertSame(2, countElementsInTable('glpi_items_tickets', ['tickets_id' => $ticketsId]));
   }

   public function testAClosedCampaignNoLongerAcceptsAnswers(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit Attestation Cloture');
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $gaelle = $this->holder($entityId, 'Gaelle', 1);
       $campaign = $this->launch($entityId);
       $campaign->close();
       $attestation = $this->attestationFor($campaign, $gaelle);

       $this->assertFalse($attestation->isAnswerable());
       $this->expectException(\RuntimeException::class);
       $attestation->confirm(self::fakeSignaturePng(), []);
   }
}
