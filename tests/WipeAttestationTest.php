<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\ConfigTransfer;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\WipeAttestation;
use GlpiPlugin\Assetsign\WipeRequirement;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #322 : attestation d'effacement des données (contenu, PDF signé et empreintes, validité,
 * exigence par entité en avertissement ou blocage, droits, rattrapage). Données fictives ; les
 * assertions portent sur les données saisies, jamais sur des libellés traduits.
 */
class WipeAttestationTest extends AssetsignTestCase
{
   use PdfTextExtraction;

   private const SERIAL = 'SN-FICTIF-WIPE-042';

   private function computerWithDrive(int $entityId, string $name): \Computer {
       $computer = $this->createTestComputer($entityId, $name);
       $drive = new \DeviceHardDrive();
       $driveId = (int) $drive->add(['designation' => 'Disque fictif 512', 'entities_id' => $entityId]);
       (new \Item_DeviceHardDrive())->add([
           'itemtype'            => 'Computer',
           'items_id'            => $computer->getID(),
           'deviceharddrives_id' => $driveId,
           'serial'              => self::SERIAL,
           'capacity'            => 512000,
           'entities_id'         => $entityId,
       ]);
       return $computer;
   }

   /** @return array<string, mixed> */
   private static function input(array $media, array $extra = []): array {
       return $extra + [
           'date_wipe'           => date('Y-m-d H:i:s', time() - 60),
           'method'              => WipeAttestation::METHOD_CRYPTO_ERASE,
           'nist_level'          => WipeAttestation::LEVEL_PURGE,
           'verification_result' => WipeAttestation::RESULT_VERIFIED,
           'tool'                => 'Outil-Fictif 3.1',
           'comment'             => '',
           'media'               => $media,
           'media_other'         => '',
       ];
   }

   private function createAttestation(\Computer $computer, array $extra = []): WipeAttestation {
       $media = array_column(WipeAttestation::getInventoryMedia($computer), 'key');
       return WipeAttestation::create($computer, self::input($media, $extra), self::signature());
   }

   public function testInventoryMediaListsTheDriveWithItsSerial(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement inventaire');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-1');

       $media = WipeAttestation::getInventoryMedia($computer);
       $this->assertCount(1, $media);
       $this->assertSame('drive', $media[0]['kind']);
       $this->assertSame(self::SERIAL, $media[0]['serial']);
       $this->assertSame('Disque fictif 512', $media[0]['label']);
   }

   public function testCreateStoresASignedPdfWithItsHashMediaAndPassportEvent(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement creation');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-2');

       $attestation = $this->createAttestation($computer, ['media_other' => "Cle USB fictive KEY-0001\n\n"]);

       $media = $attestation->getMedia();
       $this->assertCount(2, $media);
       $this->assertSame(self::SERIAL, $media[0]['serial']);
       $this->assertSame('Cle USB fictive KEY-0001', $media[1]['label']);
       $this->assertSame('manual', $media[1]['source']);

       $document = new \Document();
       $this->assertTrue($document->getFromDB((int) $attestation->fields['documents_id']));
       $binary = (string) file_get_contents(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
       $this->assertSame(hash('sha256', $binary), $attestation->fields['pdf_hash'], 'Empreinte SHA-256 du PDF enregistré.');
       $this->assertNotEmpty($attestation->fields['signed_at']);

       $text = implode(' ', $this->extractPageTexts($binary));
       $this->assertStringContainsString(self::SERIAL, $text);
       $this->assertStringContainsString('Outil-Fictif 3.1', $text);
       $this->assertStringContainsString('KEY-0001', $text);

       // PDF lié au matériel (onglet Documents).
       $this->assertSame(1, countElementsInTable('glpi_documents_items', [
           'documents_id' => $document->getID(), 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
       ]));
       $this->assertSame(1, countElementsInTable(PassportEvent::getTable(), [
           'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'event_type' => PassportEvent::TYPE_WIPE,
       ]));
   }

   public function testReportIsStoredWithItsHash(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement rapport');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-3');
       $report = tempnam(sys_get_temp_dir(), 'wipe');
       file_put_contents($report, "Rapport fictif : 1 disque, 0 erreur\n");

       $attestation = WipeAttestation::store($computer, [
           'date_wipe' => date('Y-m-d H:i:s'), 'method' => WipeAttestation::METHOD_OVERWRITE, 'nist_level' => '',
           'verification_result' => WipeAttestation::RESULT_VERIFIED, 'tool' => '', 'comment' => '',
           'media' => [['label' => 'Disque fictif', 'serial' => '', 'capacity' => '', 'encryption' => '', 'source' => 'manual']],
       ], self::signature(), ['path' => $report, 'name' => 'rapport.txt']);

       $this->assertSame(hash_file('sha256', $report), $attestation->fields['report_hash']);
       $this->assertGreaterThan(0, (int) $attestation->fields['report_documents_id']);
       unlink($report);
   }

   public function testReportContentMustMatchItsType(): void {
       $file = tempnam(sys_get_temp_dir(), 'wipe');
       file_put_contents($file, '<?php echo 1;');
       foreach (['rapport.pdf', 'rapport.txt', 'rapport.png', 'rapport.php'] as $name) {
          try {
              WipeAttestation::validateReport($file, $name);
              $this->fail("$name contenant du PHP aurait dû être refusé.");
          } catch (\RuntimeException $e) {
              $this->assertNotSame('', $e->getMessage());
          }
       }
       file_put_contents($file, "%PDF-1.4\nfictif");
       WipeAttestation::validateReport($file, 'rapport.pdf');
       unlink($file);
       $this->addToAssertionCount(1);
   }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
   public static function invalidInputs(): iterable {
       yield 'méthode inconnue' => [['method' => 'magie'], 'valid'];
       yield 'norme inconnue' => [['nist_level' => 'ultra'], 'valid'];
       yield 'date future' => [['date_wipe' => date('Y-m-d H:i:s', time() + 86400)], 'valid'];
       yield 'aucun support' => [['media' => []], 'valid'];
       yield 'signature vide' => [[], 'empty'];
   }

   #[DataProvider('invalidInputs')]
   public function testInvalidInputIsRejectedBeforeAnyWrite(array $override, string $signature): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement refus');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-4');
       $media = array_column(WipeAttestation::getInventoryMedia($computer), 'key');

       try {
           WipeAttestation::create($computer, $override + self::input($media), $signature === 'empty' ? '' : self::signature());
           $this->fail('Saisie invalide acceptée.');
       } catch (\RuntimeException $e) {
           $this->assertNotSame('', $e->getMessage());
       }
       $this->assertSame(0, countElementsInTable(WipeAttestation::getTable(), ['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
   }

   public function testValidityFollowsHandoversCancellationAndFailure(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement validite');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-5');
       $before = $this->createAttestation($computer, ['date_wipe' => date('Y-m-d H:i:s', time() - 7200)]);
       $this->assertSame($before->getID(), (int) WipeAttestation::getValidFor('Computer', $computer->getID())['id']);

       // Remis à quelqu'un après l'effacement : l'attestation ne vaut plus.
       $this->handover($computer, $entity, 2, date('Y-m-d H:i:s', time() - 3600));
       $this->assertNull(WipeAttestation::getValidFor('Computer', $computer->getID()));

       $failed = $this->createAttestation($computer, ['verification_result' => WipeAttestation::RESULT_FAILED]);
       $this->assertNull(WipeAttestation::getValidFor('Computer', $computer->getID()), 'Un échec de vérification ne compte pas.');

       $good = $this->createAttestation($computer);
       $this->assertSame($good->getID(), (int) WipeAttestation::getValidFor('Computer', $computer->getID())['id']);

       $good->cancel('Saisie sur le mauvais poste', 2);
       $this->assertSame(1, (int) $good->fields['is_cancelled']);
       $this->assertNull(WipeAttestation::getValidFor('Computer', $computer->getID()), 'Une attestation annulée ne compte plus.');
       $this->assertNotSame($failed->getID(), 0);

       $this->expectException(\RuntimeException::class);
       $good->cancel('Encore', 2);
   }

   public function testCancellationRequiresAReason(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement motif');
       $attestation = $this->createAttestation($this->computerWithDrive($entity, 'PC-EFFACEMENT-6'));
       $this->expectException(\RuntimeException::class);
       $attestation->cancel('   ', 2);
   }

   public function testRequirementIsDisabledByDefault(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement defaut');
       // Ligne propre a l'entite, aux valeurs par defaut (sans heriter de l'instance de test).
       Config::upsertForEntity($entity, []);
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-7');
       $this->assertSame(WipeRequirement::MODE_DISABLED, WipeRequirement::check('Computer', $computer->getID(), $entity, Assetsign::TYPE_DON));

       $sheet = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
       $this->assertGreaterThan(0, $sheet->getID());
   }

   public function testBlockingRequirementRefusesTheDonationUntilAnAttestationExists(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement blocage');
       Config::upsertForEntity($entity, ['wipe_requirement' => WipeRequirement::MODE_BLOCK, 'wipe_required_types' => [Assetsign::TYPE_DON]]);
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-8');

       try {
           Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
           $this->fail('Le don aurait dû être refusé.');
       } catch (\RuntimeException $e) {
           $this->assertSame(WipeRequirement::message(WipeRequirement::MODE_BLOCK, Assetsign::TYPE_DON), $e->getMessage());
       }
       $this->assertSame(0, countElementsInTable(Assetsign::getTable(), ['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
       $this->assertTrue(Assetsign::wipeNoticeFor($computer, [Assetsign::TYPE_DON])['blocking']);

       // Vente non concernée par le réglage.
       $this->assertSame(WipeRequirement::MODE_DISABLED, WipeRequirement::check('Computer', $computer->getID(), $entity, Assetsign::TYPE_VENTE));

       $attestation = $this->createAttestation($computer);
       $this->assertNull(Assetsign::wipeNoticeFor($computer, [Assetsign::TYPE_DON]));
       $sheet = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

       // Le PDF de la fiche de don mentionne l'attestation (empreinte de son PDF).
       $html = (new HandoverPdfBuilder())->renderHtml($sheet);
       $this->assertStringContainsString((string) $attestation->fields['pdf_hash'], $html);
   }

   public function testWarningRequirementCreatesTheSaleWithAWarning(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement avertissement');
       Config::upsertForEntity($entity, ['wipe_requirement' => WipeRequirement::MODE_WARN, 'wipe_required_types' => [Assetsign::TYPE_VENTE]]);
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-9');
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

       $sheet = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_VENTE, 2, ['price' => 10]);

       $this->assertGreaterThan(0, $sheet->getID());
       $this->assertContains(
           WipeRequirement::message(WipeRequirement::MODE_WARN, Assetsign::TYPE_VENTE),
           $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? []
       );
       // L'exigence étant active, le PDF signale l'absence d'attestation : la section existe.
       $this->assertNotNull((new \ReflectionMethod(HandoverPdfBuilder::class, 'getWipeSection'))->invoke(new HandoverPdfBuilder(), $sheet, Config::getForEntity($entity)));

       // Rattrapage : la vente apparaît parmi les sorties sans attestation.
       $this->assertContains($sheet->getID(), array_column(WipeAttestation::getExitsWithoutAttestation(), 'id'));
       $this->createAttestation($computer, ['date_wipe' => date('Y-m-d H:i:s', time() - 30)]);
       $this->assertNotContains($sheet->getID(), array_column(WipeAttestation::getExitsWithoutAttestation(), 'id'), 'Attestation enregistrée après coup : rattrapée.');
   }

   public function testReassignmentDraftCannotBeSentWhenBlocked(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement reattribution');
       Config::upsertForEntity($entity, ['wipe_requirement' => WipeRequirement::MODE_BLOCK, 'wipe_required_types' => [Assetsign::TYPE_HANDOVER]]);
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-10');
       $first = $this->createTestUser('Alice', 'Fictive');
       $second = $this->createTestUser('Bruno', 'Fictif');

       // Première remise : pas une réattribution.
       $this->assertSame(WipeRequirement::MODE_DISABLED, WipeRequirement::check('Computer', $computer->getID(), $entity, Assetsign::TYPE_HANDOVER, $first));
       $this->handover($computer, $entity, $first, date('Y-m-d H:i:s', time() - 3600));
       $this->assertSame(WipeRequirement::MODE_BLOCK, WipeRequirement::check('Computer', $computer->getID(), $entity, Assetsign::TYPE_HANDOVER, $second));

       $draft = $this->handover($computer, $entity, $second, date('Y-m-d H:i:s'), Assetsign::STATUS_DRAFT);
       try {
           $draft->sendDraft();
           $this->fail('L\'envoi aurait dû être refusé.');
       } catch (\RuntimeException $e) {
           $this->assertSame(WipeRequirement::message(WipeRequirement::MODE_BLOCK, Assetsign::TYPE_HANDOVER), $e->getMessage());
       }
       $draft->getFromDB($draft->getID());
       $this->assertSame(Assetsign::STATUS_DRAFT, (int) $draft->fields['status'], 'Le statut ne change jamais tout seul.');

       $this->createAttestation($computer);
       $this->assertSame(WipeRequirement::MODE_DISABLED, WipeRequirement::check('Computer', $computer->getID(), $entity, Assetsign::TYPE_HANDOVER, $second));
   }

   public function testSettingIsNormalizedAndTransferred(): void {
       $source = $this->createTestEntity(0, 'PHPUnit Effacement export');
       $target = $this->createTestEntity(0, 'PHPUnit Effacement import');
       Config::upsertForEntity($source, ['wipe_requirement' => 9, 'wipe_required_types' => ['-1', '5', '42']]);
       $config = Config::getForEntity($source);
       $this->assertSame(WipeRequirement::MODE_DISABLED, (int) $config->fields['wipe_requirement'], 'Mode inconnu : désactivé.');
       $this->assertSame([Assetsign::TYPE_DESTRUCTION], WipeRequirement::normalizeTypes($config->fields['wipe_required_types']));

       Config::upsertForEntity($source, ['wipe_requirement' => WipeRequirement::MODE_BLOCK, 'wipe_required_types' => [Assetsign::TYPE_VENTE, Assetsign::TYPE_HANDOVER]]);
       $export = ConfigTransfer::export($source, false, false);
       $this->assertSame(WipeRequirement::MODE_BLOCK, $export['config']['wipe_requirement']);
       $this->assertSame([Assetsign::TYPE_VENTE, Assetsign::TYPE_HANDOVER], $export['config']['wipe_required_types']);

       ConfigTransfer::apply(ConfigTransfer::parse((string) json_encode($export)), $target, ['templates' => false, 'logo' => false]);
       $imported = Config::getForEntity($target);
       $this->assertSame(WipeRequirement::MODE_BLOCK, (int) $imported->fields['wipe_requirement']);
       $this->assertSame([Assetsign::TYPE_VENTE, Assetsign::TYPE_HANDOVER], WipeRequirement::normalizeTypes($imported->fields['wipe_required_types']));
   }

   public function testTabRequiresTheAssetsignReadRight(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement droits');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-11');
       $this->assertNotSame('', (new WipeAttestation())->getTabNameForItem($computer));

       $saved = $_SESSION['glpiactiveprofile']['plugin_assetsign_assetsign'];
       $_SESSION['glpiactiveprofile']['plugin_assetsign_assetsign'] = 0;
       try {
           $this->assertSame('', (new WipeAttestation())->getTabNameForItem($computer));
           $this->assertFalse(WipeAttestation::displayTabContentForItem($computer));
           $this->assertFalse(WipeAttestation::canCreate());
       } finally {
           $_SESSION['glpiactiveprofile']['plugin_assetsign_assetsign'] = $saved;
       }
   }

   public function testPdfCarriesTheDocumentControlHeaderWhenEnabled(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement en-tete');
       Config::upsertForEntity($entity, ['doc_header_enabled' => 1, 'doc_header_doc_type' => 'Enregistrement fictif']);
       $attestation = $this->createAttestation($this->computerWithDrive($entity, 'PC-EFFACEMENT-13'));

       $html = (new \GlpiPlugin\Assetsign\Pdf\WipeAttestationPdfBuilder())->renderHtml($attestation);
       $this->assertStringContainsString('doc-header', $html);
       $this->assertStringContainsString('Enregistrement fictif', $html);
   }

   public function testFormPageRendersWithoutErrors(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit Effacement affichage');
       $computer = $this->computerWithDrive($entity, 'PC-EFFACEMENT-12');
       $attestation = $this->createAttestation($computer);

       ob_start();
       $attestation->showForm($attestation->getID());
       WipeAttestation::showForItem($computer);
       $html = (string) ob_get_clean();
       $this->assertStringContainsString(self::SERIAL, $html);
       $this->assertStringContainsString((string) $attestation->fields['pdf_hash'], $html);
       $this->assertStringContainsString('name="media[]"', $html);
   }

   private function handover(\Computer $computer, int $entity, int $usersId, string $date, int $status = Assetsign::STATUS_SIGNED): Assetsign {
       $sheet = new Assetsign();
       $id = (int) $sheet->add([
           'entities_id' => $entity, 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
           'users_id' => $usersId, 'type' => Assetsign::TYPE_HANDOVER, 'status' => $status,
       ]);
       global $DB;
       $DB->update(Assetsign::getTable(), ['date_creation' => $date, 'date_sent' => $status === Assetsign::STATUS_DRAFT ? null : $date], ['id' => $id]);
       $sheet->getFromDB($id);
       return $sheet;
   }

   private static function signature(): string {
       $image = imagecreatetruecolor(300, 100);
       imagefilledrectangle($image, 0, 0, 300, 100, imagecolorallocate($image, 255, 255, 255));
       imageline($image, 10, 10, 290, 90, imagecolorallocate($image, 0, 0, 0));
       imageline($image, 10, 90, 290, 10, imagecolorallocate($image, 0, 0, 0));
       ob_start();
       imagepng($image);
       $binary = (string) ob_get_clean();
       return 'data:image/png;base64,' . base64_encode($binary);
   }
}
