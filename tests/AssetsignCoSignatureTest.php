<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\SignController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Signature;
use GlpiPlugin\Assetsign\Token;

/**
 * Signatures multiples (issue #143) : contre-signature du responsable
 * hierarchique du beneficiaire (User::users_id_supervisor), en plus de la
 * signature de ce dernier, pour une Attribution (TYPE_HANDOVER) uniquement.
 *
 * Couvre les deux moities du chantier : le SNAPSHOT du responsable au
 * lancement (Assetsign::launchWorkflow(), via un vrai declenchement
 * d'affectation comme AssetsignTest::testHandleItemAssignmentCreatesHandoverOnNewAssignment())
 * et le FLUX A DEUX SIGNATURES de bout en bout (SignController::submit(),
 * appele deux fois avec deux jetons distincts, comme le ferait reellement le
 * beneficiaire puis le responsable depuis front/sign.php).
 */
final class AssetsignCoSignatureTest extends AssetsignTestCase
{
   private $originalGlpiId;

    /**
     * Un vrai trace, pas un pixel isole : SignatureImageValidator::assertValid()
     * (appele par SignController::submit(), contrairement a SignatureStamperTest
     * qui appelle SignatureStamper directement et ne passe jamais par ce
     * controle) exige au moins 40x20px et un minimum de pixels d'encre reels
     * — memes dimensions/trace que SignatureImageValidatorTest. $offset permet
     * d'obtenir deux images visuellement distinctes (deux hash differents) pour
     * simuler deux signataires differents.
     */
   private static function fakeSignaturePng(int $offset = 0): string {
       $width = 200;
       $height = 80;
       $image = imagecreatetruecolor($width, $height);
       imagesavealpha($image, true);
       $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
       imagefill($image, 0, 0, $transparent);

       $ink = imagecolorallocatealpha($image, 0, 0, 0, 0);
       imageline($image, 20 + $offset, (int) ($height / 2), $width - 20, (int) ($height / 2) + $offset, $ink);
       imageline($image, (int) ($width / 3), 15, (int) ($width / 2) + $offset, $height - 15, $ink);

       ob_start();
       imagepng($image);
       $binary = ob_get_clean();
       imagedestroy($image);

       return 'data:image/png;base64,' . base64_encode($binary);
   }

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

   private function makeEmail(int $usersId, string $email): void {
       (new \UserEmail())->add(['users_id' => $usersId, 'email' => $email, 'is_default' => 1]);
   }

   private function findAssetsignFor(\Computer $computer): ?array {
       global $DB;
       $rows = iterator_to_array($DB->request([
           'FROM'  => Assetsign::getTable(),
           'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID()],
           'ORDER' => 'id DESC',
           'LIMIT' => 1,
       ]));
       return count($rows) ? reset($rows) : null;
   }

   public function testHandleItemAssignmentSnapshotsSupervisorAsCosignerWhenSettingEnabled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit CoSignature Snapshot Enabled');
       // sign_on_assignment => 1 explicite : upsertForEntity() n'herite PAS des
       // valeurs par defaut pour les champs absents de $input, il les remet a
       // 0/desactive (cf. son propre corps) - sans cette ligne, cet appel
       // desactiverait par megarde le declenchement automatique lui-meme.
       Config::upsertForEntity($entityId, ['enable_co_signature' => 1, 'sign_on_assignment' => 1]);
       $supervisorId = $this->createTestUser('Super', 'Visor');
       $beneficiaryId = $this->createTestUser('Bene', 'Ficiary', ['users_id_supervisor' => $supervisorId]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC CoSignature Snapshot Enabled');

       $computer->oldvalues = ['users_id' => 0];
       $computer->fields['users_id'] = $beneficiaryId;
       Assetsign::handleItemAssignment($computer);

       $created = $this->findAssetsignFor($computer);
       $this->assertNotNull($created, 'Une remise aurait du etre creee automatiquement lors de cette affectation.');
       $this->assertSame($supervisorId, (int) $created['cosigner_users_id'], 'Le responsable hierarchique doit etre snapshote comme contre-signataire.');
   }

   public function testHandleItemAssignmentLeavesCosignerEmptyWhenBeneficiaryHasNoSupervisor(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit CoSignature Snapshot NoSupervisor');
       Config::upsertForEntity($entityId, ['enable_co_signature' => 1, 'sign_on_assignment' => 1]);
       $beneficiaryId = $this->createTestUser('Bene', 'NoSupervisor');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC CoSignature Snapshot NoSupervisor');

       $computer->oldvalues = ['users_id' => 0];
       $computer->fields['users_id'] = $beneficiaryId;
       Assetsign::handleItemAssignment($computer);

       $created = $this->findAssetsignFor($computer);
       $this->assertNotNull($created);
       $this->assertSame(0, (int) $created['cosigner_users_id'], "Sans responsable renseigne, l'attribution doit rester mono-signataire (aucun blocage pour donnee organisationnelle manquante).");
   }

   public function testHandleItemAssignmentLeavesCosignerEmptyWhenSettingDisabled(): void {
       // enable_co_signature reste a 0 (defaut) : meme un beneficiaire avec un
       // vrai responsable renseigne ne doit rien declencher.
       $entityId = $this->createTestEntity(0, 'PHPUnit CoSignature Snapshot Disabled');
       $supervisorId = $this->createTestUser('Super', 'VisorDisabled');
       $beneficiaryId = $this->createTestUser('Bene', 'FiciaryDisabled', ['users_id_supervisor' => $supervisorId]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC CoSignature Snapshot Disabled');

       $computer->oldvalues = ['users_id' => 0];
       $computer->fields['users_id'] = $beneficiaryId;
       Assetsign::handleItemAssignment($computer);

       $created = $this->findAssetsignFor($computer);
       $this->assertNotNull($created);
       $this->assertSame(0, (int) $created['cosigner_users_id'], 'Reglage desactive (defaut) : aucune contre-signature ne doit etre requise, meme avec un responsable renseigne.');
   }

    /**
     * Flux complet, de bout en bout : le beneficiaire signe (statut ->
     * AWAITING_COSIGNATURE, jeton beneficiaire invalide, jeton responsable
     * cree), puis le responsable contre-signe avec CE nouveau jeton (statut
     * -> SIGNED, PDF final a deux signatures, cosigner_pending_signature
     * efface). Chaque jeton est cree directement (Token::createForAssetsign(),
     * meme pattern que SignControllerTest) plutot que via launchWorkflow()/un
     * e-mail reellement envoye — ce test porte sur la MACHINE A ETATS, pas
     * sur l'acheminement des notifications (deja couvert par
     * NotificationTargetAssetsignTest).
     */
   public function testFullCoSignatureFlowEndToEnd(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit CoSignature FullFlow');
       $supervisorId = $this->createTestUser('Super', 'VisorFlow');
       $this->makeEmail($supervisorId, 'supervisor.flow@example.test');
       $beneficiaryId = $this->createTestUser('Bene', 'FiciaryFlow', ['users_id_supervisor' => $supervisorId]);
       $this->makeEmail($beneficiaryId, 'beneficiary.flow@example.test');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC CoSignature FullFlow');

       $assetsign = new Assetsign();
       $assetsignId = (int) $assetsign->add([
           'entities_id'       => $entityId,
           'itemtype'          => 'Computer',
           'items_id'          => $computer->getID(),
           'users_id'          => $beneficiaryId,
           'cosigner_users_id' => $supervisorId,
           'type'              => Assetsign::TYPE_HANDOVER,
           'status'            => Assetsign::STATUS_SENT,
       ]);
       $assetsign->getFromDB($assetsignId);

       $beneficiaryRaw = Token::createForAssetsign($assetsign, 30);

       // --- Etape 1 : le beneficiaire signe --------------------------------------
       $_SESSION['glpiID'] = $beneficiaryId;
       $cosignerRaw = (new SignController())->submit($beneficiaryRaw, self::fakeSignaturePng(), ['ip' => '127.0.0.1', 'user_agent' => 'PHPUnit']);

       $this->assertNotNull($cosignerRaw, "La soumission du beneficiaire doit produire un jeton frais pour le responsable, puisque cette fiche requiert une contre-signature.");

       $assetsign->getFromDB($assetsignId);
       $this->assertSame(Assetsign::STATUS_AWAITING_COSIGNATURE, (int) $assetsign->fields['status'], "La fiche ne doit pas encore etre consideree SIGNEE : il manque la contre-signature.");
       $this->assertGreaterThan(0, (int) $assetsign->fields['document_id_signed'], 'Le PDF signe par le seul beneficiaire doit deja etre attache (tracabilite de cette etape).');
       $this->assertSame(0, (int) $assetsign->fields['document_id_cosigned'], 'Le PDF final (a deux signatures) ne doit pas encore exister.');
       $this->assertNotSame('', (string) $assetsign->fields['cosigner_pending_signature'], "La signature du beneficiaire doit etre conservee en base, le temps d'attendre la contre-signature.");

       $beneficiaryToken = new Token();
       $beneficiaryToken->getFromDBByCrit(['token_hash' => hash('sha256', $beneficiaryRaw)]);
       $this->assertSame(0, (int) $beneficiaryToken->fields['is_valid'], 'Le jeton du beneficiaire doit etre invalide une fois utilise.');

       $cosignerToken = new Token();
       $cosignerToken->getFromDBByCrit(['token_hash' => hash('sha256', $cosignerRaw)]);
       $this->assertSame(1, (int) $cosignerToken->fields['is_valid'], 'Le nouveau jeton du responsable doit etre valide.');
       $this->assertSame(1, (int) $cosignerToken->fields['for_cosigner'], 'Ce nouveau jeton doit porter le role "responsable".');

       // --- Un tiers (ni le beneficiaire, ni le responsable) ne doit jamais pouvoir utiliser ce jeton ---
       $outsiderId = $this->createTestUser('Out', 'SiderFlow');
       $_SESSION['glpiID'] = $outsiderId;
       try {
           (new SignController())->submit($cosignerRaw, self::fakeSignaturePng(10), []);
           $this->fail('Un tiers non autorise ne doit jamais pouvoir contre-signer.');
       } catch (\RuntimeException $e) {
           // Attendu.
       }
       // Le jeton du beneficiaire, lui, ne doit pas non plus rouvrir l'acces
       // (deja invalide/utilise) - le beneficiaire ne peut pas re-signer.
       $_SESSION['glpiID'] = $beneficiaryId;
       try {
           (new SignController())->submit($beneficiaryRaw, self::fakeSignaturePng(), []);
           $this->fail('Un jeton beneficiaire deja utilise ne doit jamais pouvoir resservir.');
       } catch (\RuntimeException $e) {
           // Attendu.
       }

       // --- Etape 2 : le responsable contre-signe --------------------------------
       $_SESSION['glpiID'] = $supervisorId;
       $result = (new SignController())->submit($cosignerRaw, self::fakeSignaturePng(10), ['ip' => '127.0.0.2', 'user_agent' => 'PHPUnit Cosigner']);

       $this->assertNull($result, 'Une fois la contre-signature soumise, la fiche est complete : aucun troisieme jeton ne doit etre cree.');

       $assetsign->getFromDB($assetsignId);
       $this->assertSame(Assetsign::STATUS_SIGNED, (int) $assetsign->fields['status'], 'La fiche doit desormais etre entierement signee.');
       $this->assertGreaterThan(0, (int) $assetsign->fields['document_id_cosigned'], 'Le PDF final (a deux signatures) doit avoir ete produit.');
       $this->assertSame('', (string) $assetsign->fields['cosigner_pending_signature'], 'La signature intermediaire du beneficiaire doit etre effacee une fois le PDF final produit.');

       $document = new \Document();
       $document->getFromDB((int) $assetsign->fields['document_id_cosigned']);
       $pdfBinary = file_get_contents(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
       $this->assertStringStartsWith('%PDF', $pdfBinary, 'Le document final doit etre un vrai PDF.');

       $proofs = Signature::getAllForAssetsign($assetsignId);
       $this->assertCount(2, $proofs, 'Deux preuves de signature doivent exister : celle du beneficiaire, puis celle du responsable.');

       $cosignerToken->getFromDB($cosignerToken->getID());
       $this->assertSame(0, (int) $cosignerToken->fields['is_valid'], 'Le jeton du responsable doit etre invalide une fois utilise.');
   }

    /**
     * Sans responsable snapshote (cosigner_users_id = 0, comportement par
     * defaut), le flux doit rester STRICTEMENT identique a avant cette
     * fonctionnalite : une seule signature suffit, aucune regression pour les
     * entites qui n'activent pas ce reglage.
     */
   public function testSingleSignerFlowIsUnaffectedWhenNoCosignerIsSet(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit CoSignature NoRegression');
       $beneficiaryId = $this->createTestUser('Bene', 'NoRegression');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC CoSignature NoRegression');

       $assetsign = new Assetsign();
       $assetsignId = (int) $assetsign->add([
           'entities_id' => $entityId,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'users_id'    => $beneficiaryId,
           'type'        => Assetsign::TYPE_HANDOVER,
           'status'      => Assetsign::STATUS_SENT,
       ]);
       $assetsign->getFromDB($assetsignId);

       $raw = Token::createForAssetsign($assetsign, 30);
       $_SESSION['glpiID'] = $beneficiaryId;

       $result = (new SignController())->submit($raw, self::fakeSignaturePng(), []);

       $this->assertNull($result, 'Sans contre-signature requise, submit() ne doit jamais produire de second jeton.');

       $assetsign->getFromDB($assetsignId);
       $this->assertSame(Assetsign::STATUS_SIGNED, (int) $assetsign->fields['status']);
       $this->assertGreaterThan(0, (int) $assetsign->fields['document_id_signed']);
       $this->assertSame(0, (int) $assetsign->fields['document_id_cosigned'], 'document_id_cosigned ne doit jamais etre utilise en dehors du flux de contre-signature.');
   }
}
