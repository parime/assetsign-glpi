<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Attestation;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\CampaignLogic;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Template;

/**
 * Issue #204 : signature périodique de la charte informatique, sur le mécanisme des campagnes
 * d'attestation (#158).
 */
final class CharterCampaignTest extends AssetsignTestCase
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

   private function member(int $entityId, string $name): int {
       global $DB;
       $usersId = $this->createTestUser($name, 'Charte', ['entities_id' => $entityId]);
      if (countElementsInTable('glpi_profiles_users', ['users_id' => $usersId, 'entities_id' => $entityId]) === 0) {
          $DB->insert('glpi_profiles_users', ['users_id' => $usersId, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 0]);
      }
       return $usersId;
   }

   private function charterEntity(string $name, string $charter = '<p>Article 1 : verrouiller sa session.</p>'): int {
       $entityId = $this->createTestEntity(0, $name);
       (new Template())->add([
           'entities_id'     => $entityId,
           'type'            => Assetsign::TYPE_HANDOVER,
           'name'            => 'PHPUnit charte',
           'content'         => '<p>Contrat</p>',
           'charter_content' => $charter,
           'include_charter' => 1,
           'is_default'      => 1,
           'is_active'       => 1,
       ]);
       return $entityId;
   }

   private function launchCharter(int $entityId, string $deadline = '+30 days'): Campaign {
       return Campaign::launch([
           'name'          => '',
           'type'          => CampaignLogic::TYPE_CHARTER,
           'entities_id'   => $entityId,
           'is_recursive'  => false,
           'groups_id'     => 0,
           'profiles_id'   => 0,
           'date_deadline' => date('Y-m-d', strtotime($deadline)),
       ]);
   }

   private function attestationFor(Campaign $campaign, int $usersId): Attestation {
       $attestation = new Attestation();
       $attestation->getFromDBByCrit(['plugin_assetsign_campaigns_id' => $campaign->getID(), 'users_id' => $usersId]);
       return $attestation;
   }

   public function testEveryoneInScopeIsInvitedEvenWithoutEquipmentAndTheCharterIsFrozen(): void {
       $entityId = $this->charterEntity('PHPUnit Charte Perimetre');
       $withoutEquipment = $this->member($entityId, 'Sansmateriel');

       $campaign = $this->launchCharter($entityId);

       $this->assertTrue($campaign->isCharter());
       $this->assertSame(sprintf(__('Charte informatique %s', 'assetsign'), date('Y')), (string) $campaign->fields['name']);
       $this->assertGreaterThan(0, $this->attestationFor($campaign, $withoutEquipment)->getID(), 'La charte concerne tout le monde, matériel ou pas.');
       $this->assertStringContainsString('verrouiller sa session', (string) $campaign->fields['charter_content']);

       // Modifier la charte ensuite ne change pas ce que cette campagne fait signer.
       $template = new Template();
       $template->getFromDBByCrit(['entities_id' => $entityId, 'type' => Assetsign::TYPE_HANDOVER]);
       $template->update(['id' => $template->getID(), 'charter_content' => '<p>Nouvelle version</p>']);
       $campaign->getFromDB($campaign->getID());
       $this->assertStringContainsString('verrouiller sa session', (string) $campaign->fields['charter_content']);
   }

   public function testCharterIsSanitizedAndIncludesTheConfiguredLink(): void {
       $entityId = $this->charterEntity('PHPUnit Charte Assainie', '<p>Charte</p><script>alert(1)</script>');
       Config::upsertForEntity($entityId, ['charter_url' => 'https://intranet.example.org/charte.pdf']);

       $html = Campaign::getCharterContent($entityId);

       $this->assertStringNotContainsString('<script', $html);
       $this->assertStringContainsString('https://intranet.example.org/charte.pdf', $html);
   }

   public function testLaunchIsRefusedWithoutACharter(): void {
       global $DB;
       // Sans charte propre, une entité hérite de celle du gabarit par défaut de la racine : la
       // vider aussi (transaction annulée en fin de test).
       $DB->update(Template::getTable(), ['charter_content' => ''], ['entities_id' => 0]);
       $entityId = $this->createTestEntity(0, 'PHPUnit Charte Absente');
       $this->assertSame('', Campaign::getCharterContent($entityId));
       $this->expectException(\RuntimeException::class);
       $this->launchCharter($entityId);
   }

   public function testASecondCharterCampaignIsRefusedWhileOneIsRunning(): void {
       global $DB;
       $entityId = $this->charterEntity('PHPUnit Charte En Cours');
       $this->member($entityId, 'Doublon');
       $first = $this->launchCharter($entityId);
       try {
           $this->launchCharter($entityId);
           $this->fail('Une seconde campagne de charte en cours doit être refusée.');
       } catch (\RuntimeException $e) {
           $this->assertStringContainsString((string) $first->fields['name'], $e->getMessage());
       }

       // Échue (encore ouverte pour les signatures tardives) : la suivante peut être lancée.
       $DB->update(Campaign::getTable(), ['date_deadline' => date('Y-m-d', strtotime('-1 day'))], ['id' => $first->getID()]);
       $this->assertInstanceOf(Campaign::class, $this->launchCharter($entityId));
   }

   public function testSigningProducesAPdfAndNoDiscrepancyCanBeReported(): void {
       $entityId = $this->charterEntity('PHPUnit Charte Signature');
       $usersId = $this->member($entityId, 'Signataire');
       $campaign = $this->launchCharter($entityId);
       $attestation = $this->attestationFor($campaign, $usersId);

      try {
          $attestation->reportDiscrepancy(['missing'], 'non');
          $this->fail('Une charte ne permet pas de signaler un écart.');
      } catch (\RuntimeException $e) {
          $this->assertStringContainsString('charte', $e->getMessage());
      }

       $attestation->confirm(self::fakeSignaturePng(), ['ip' => '192.0.2.1']);
       $attestation->getFromDB($attestation->getID());

       $this->assertSame(CampaignLogic::ATTESTATION_CONFIRMED, (int) $attestation->fields['status']);
       $this->assertGreaterThan(0, (int) $attestation->fields['documents_id']);
       $this->assertSame(64, strlen((string) $attestation->fields['signature_hash']));
       $document = new \Document();
       $document->getFromDB((int) $attestation->fields['documents_id']);
       $this->assertStringStartsWith(sprintf(__('Charte informatique signée - %s', 'assetsign'), ''), (string) $document->fields['name']);
   }

   public function testNonSignersGetOneTicketOnceTheDeadlineHasPassed(): void {
       global $DB;
       $entityId = $this->charterEntity('PHPUnit Charte Ticket');
       $signer = $this->member($entityId, 'Signe');
       $late = $this->member($entityId, 'Retardataire');
       $campaign = $this->launchCharter($entityId);
       $this->attestationFor($campaign, $signer)->confirm(self::fakeSignaturePng(), []);

       $this->assertSame(0, Campaign::runCharterFollowUp(), 'Pas de ticket avant l\'échéance.');

       $DB->update(Campaign::getTable(), ['date_deadline' => date('Y-m-d', strtotime('-1 day'))], ['id' => $campaign->getID()]);
       $this->assertSame(1, Campaign::runCharterFollowUp());
       $this->assertSame(0, Campaign::runCharterFollowUp(), 'Un seul ticket par campagne.');

       $campaign->getFromDB($campaign->getID());
       $ticket = new \Ticket();
       $this->assertTrue($ticket->getFromDB((int) $campaign->fields['tickets_id']));
       $this->assertStringContainsString(getUserName($late), (string) $ticket->fields['content']);
       $this->assertStringNotContainsString(getUserName($signer), (string) $ticket->fields['content']);
   }

   public function testRenewalLaunchesOneCampaignPerPeriodForEnabledEntities(): void {
       global $DB;
       $enabled = $this->charterEntity('PHPUnit Charte Renouvelee');
       $this->member($enabled, 'Annuel');
       Config::upsertForEntity($enabled, ['charter_campaign_enabled' => 1, 'charter_renewal_months' => 12, 'charter_deadline_days' => 15]);
       $disabled = $this->charterEntity('PHPUnit Charte Non Renouvelee');
       $this->member($disabled, 'Jamais');

       $countFor = static fn (int $entityId): int => countElementsInTable(Campaign::getTable(), ['entities_id' => $entityId, 'type' => CampaignLogic::TYPE_CHARTER]);

       Campaign::runCharterRenewals();
       $this->assertSame(1, $countFor($enabled));
       $this->assertSame(0, $countFor($disabled));

       Campaign::runCharterRenewals();
       $this->assertSame(1, $countFor($enabled), 'Pas de nouvelle campagne avant la fin de la période.');

       $campaign = new Campaign();
       $campaign->getFromDBByCrit(['entities_id' => $enabled, 'type' => CampaignLogic::TYPE_CHARTER]);
       $this->assertSame(date('Y-m-d', strtotime('+15 days')), (string) $campaign->fields['date_deadline']);
       $DB->update(Campaign::getTable(), ['date_creation' => date('Y-m-d H:i:s', strtotime('-13 months')), 'date_deadline' => date('Y-m-d', strtotime('-12 months'))], ['id' => $campaign->getID()]);

       Campaign::runCharterRenewals();
       $this->assertSame(2, $countFor($enabled), 'Nouvelle campagne une fois la période écoulée.');
   }
}
