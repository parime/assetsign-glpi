<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\SignController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Signature;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * Signature sur place, en presence d'un technicien (issue #152) : pour un beneficiaire qui ne
 * peut pas se connecter a GLPI. Le jeton est lie au technicien temoin - lui seul peut l'ouvrir -
 * et la preuve de signature nomme le signataire attendu ET ce temoin.
 */
final class InPersonSignatureTest extends AssetsignTestCase
{
   private $originalGlpiId;

   protected function setUp(): void {
       parent::setUp();
       $this->originalGlpiId = $_SESSION['glpiID'] ?? null;
   }

   protected function tearDown(): void {
      if ($this->originalGlpiId === null) {
          unset($_SESSION['glpiID']);
      } else {
          $_SESSION['glpiID'] = $this->originalGlpiId;
      }
       parent::tearDown();
   }

    /** Meme trace que AssetsignCoSignatureTest : SignatureImageValidator exige un vrai trace. */
   private static function fakeSignaturePng(): string {
       $image = imagecreatetruecolor(200, 80);
       imagesavealpha($image, true);
       imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
       $ink = imagecolorallocatealpha($image, 0, 0, 0, 0);
       imageline($image, 20, 40, 180, 40, $ink);
       imageline($image, 66, 15, 100, 65, $ink);

       ob_start();
       imagepng($image);
       $binary = ob_get_clean();
       imagedestroy($image);

       return 'data:image/png;base64,' . base64_encode($binary);
   }

   private function enableInPerson(int $entityId, int $enabled = 1): void {
       global $DB;
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId, 'enable_in_person_signature' => $enabled]);
   }

   private function createSentHandover(int $entityId, int $beneficiaryId, array $extra = []): Assetsign {
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC sur place');
       $assetsign = new Assetsign();
       $id = (int) $assetsign->add($extra + [
           'entities_id' => $entityId,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'users_id'    => $beneficiaryId,
           'type'        => Assetsign::TYPE_HANDOVER,
           'status'      => Assetsign::STATUS_SENT,
       ]);
       $assetsign->getFromDB($id);
       return $assetsign;
   }

   public function testInPersonSignatureIsOffByDefault(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace Defaut');
       // Ligne de configuration propre a l'entite, avec les seules valeurs par defaut de la
       // colonne : le test ne depend pas de ce qu'une entite parente a pu activer.
       $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
       $assetsign = $this->createSentHandover($entityId, $this->createTestUser('Bea', 'Terrain'));

       $this->assertFalse($assetsign->canSignInPerson(), 'Change le modele de securite : desactive tant que l\'entite ne l\'a pas active.');

       $_SESSION['glpiID'] = $this->createTestUser('Tom', 'Technicien');
       $this->expectException(RuntimeException::class);
       $assetsign->startInPersonSignature((int) $_SESSION['glpiID']);
   }

   public function testTechnicianCollectsTheSignatureOnSiteAndTheProofNamesTheWitness(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace Flux');
       $this->enableInPerson($entityId);
       $beneficiary = $this->createTestUser('Paul', 'Sanscompte');
       $technician = $this->createTestUser('Tina', 'Temoin');
       $assetsign = $this->createSentHandover($entityId, $beneficiary);

       $_SESSION['glpiID'] = $technician;
       $raw = $assetsign->startInPersonSignature($technician);

       $data = (new SignController())->show($raw);
       $this->assertSame($technician, (int) $data['in_person_witness']['id']);
       $this->assertSame($beneficiary, (int) $data['in_person_signer']['id']);
       $this->assertFalse($data['is_delegate_signer']);

       (new SignController())->submit($raw, self::fakeSignaturePng(), ['ip' => '127.0.0.1', 'user_agent' => 'PHPUnit sur place']);

       $assetsign->getFromDB($assetsign->getID());
       $this->assertSame(Assetsign::STATUS_SIGNED, (int) $assetsign->fields['status']);

       $proof = Signature::getForAssetsign((int) $assetsign->getID());
       $this->assertStringContainsString('Sanscompte', (string) $proof['signer_name'], 'Le signataire reste le beneficiaire, pas le technicien connecte.');
       $this->assertStringContainsString('Temoin', (string) $proof['witness_name']);
   }

   public function testOnlyTheWitnessCanOpenAnInPersonToken(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace Acces');
       $this->enableInPerson($entityId);
       $beneficiary = $this->createTestUser('Rita', 'Beneficiaire');
       $technician = $this->createTestUser('Theo', 'Technicien');
       $assetsign = $this->createSentHandover($entityId, $beneficiary);

       $_SESSION['glpiID'] = $technician;
       $raw = $assetsign->startInPersonSignature($technician);

       foreach ([$beneficiary, $this->createTestUser('Ugo', 'Intrus')] as $other) {
           $_SESSION['glpiID'] = $other;
          try {
              (new SignController())->loadAuthorizedAssetsign($raw);
              $this->fail('Un jeton sur place ne doit s\'ouvrir que pour le technicien temoin.');
          } catch (RuntimeException $e) {
              $this->addToAssertionCount(1);
          }
       }
   }

   public function testInPersonTokenIsRefusedOnceTheSettingIsTurnedOff(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace Desactive');
       $this->enableInPerson($entityId);
       $technician = $this->createTestUser('Lou', 'Technicienne');
       $assetsign = $this->createSentHandover($entityId, $this->createTestUser('Max', 'Terrain'));

       $_SESSION['glpiID'] = $technician;
       $raw = $assetsign->startInPersonSignature($technician);
       $DB->update('glpi_plugin_assetsign_configs', ['enable_in_person_signature' => 0], ['entities_id' => $entityId]);

       $this->expectException(RuntimeException::class);
       (new SignController())->loadAuthorizedAssetsign($raw);
   }

   public function testDelegatedSignatureIsSignedOnSiteByTheDelegate(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace Delegation');
       $this->enableInPerson($entityId);
       $delegate = $this->createTestUser('Denis', 'Delegue');
       $technician = $this->createTestUser('Tess', 'Technicienne');
       $assetsign = $this->createSentHandover($entityId, $this->createTestUser('Bob', 'Absent'), ['delegated_users_id' => $delegate]);

       $_SESSION['glpiID'] = $technician;
       $raw = $assetsign->startInPersonSignature($technician);
       $this->assertSame($delegate, (int) (new SignController())->show($raw)['in_person_signer']['id']);

       (new SignController())->submit($raw, self::fakeSignaturePng(), []);
       $proof = Signature::getForAssetsign((int) $assetsign->getID());
       $this->assertStringContainsString('Delegue', (string) $proof['signer_name']);
   }

   public function testSelfDelegationIsRefusedFromAnInPersonPage(): void {
       global $DB;
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace AutoDelegation');
       $this->enableInPerson($entityId);
       $DB->update('glpi_plugin_assetsign_configs', ['enable_signature_delegation' => 1, 'enable_self_service_delegation' => 1], ['entities_id' => $entityId]);
       $technician = $this->createTestUser('Ana', 'Technicienne');
       $assetsign = $this->createSentHandover($entityId, $this->createTestUser('Leo', 'Terrain'));

       $_SESSION['glpiID'] = $technician;
       $raw = $assetsign->startInPersonSignature($technician);

       $this->expectException(RuntimeException::class);
       (new SignController())->delegateSelfService($raw, $this->createTestUser('Zoe', 'Autre'), 'motif');
   }

   public function testNotOfferedAtTheCosignatureStepNorForAnExternalBeneficiary(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace Exclus');
       $this->enableInPerson($entityId);

       $cosign = $this->createSentHandover($entityId, $this->createTestUser('Cam', 'Salarie'), ['status' => Assetsign::STATUS_AWAITING_COSIGNATURE]);
       $this->assertFalse($cosign->canSignInPerson());

       $external = $this->createSentHandover($entityId, 0, ['beneficiary_type' => Assetsign::BENEFICIARY_EXTERNAL, 'external_beneficiary_name' => 'Association X']);
       $this->assertFalse($external->canSignInPerson());

       $signed = $this->createSentHandover($entityId, $this->createTestUser('Sid', 'Deja'), ['status' => Assetsign::STATUS_SIGNED]);
       $this->assertFalse($signed->canSignInPerson());
   }

   public function testTheEmailLinkStaysValid(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SurPlace LienMail');
       $this->enableInPerson($entityId);
       $beneficiary = $this->createTestUser('Eva', 'Mail');
       $technician = $this->createTestUser('Ivan', 'Technicien');
       $assetsign = $this->createSentHandover($entityId, $beneficiary);
       $emailRaw = Token::createForAssetsign($assetsign, 30);

       $_SESSION['glpiID'] = $technician;
       $assetsign->startInPersonSignature($technician);

       $_SESSION['glpiID'] = $beneficiary;
       $this->assertSame($assetsign->getID(), (new SignController())->loadAuthorizedAssetsign($emailRaw)->getID());
   }
}
