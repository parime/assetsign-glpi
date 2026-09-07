<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;
use GlpiPlugin\Assetsign\Pdf\MaintenancePdfBuilder;

/**
 * `MaintenancePdfBuilder::renderHtml()` had no direct content assertion of its own —
 * `MaintenanceTest`'s PDF-related tests all go through the full `Maintenance::createWithChecklist()`
 * workflow and only check that a `Document` got attached (real PDF produced), never what the
 * rendered HTML actually contains. This suite mirrors the granularity `AssetsignTest` already
 * applies to the sibling `HandoverPdfBuilder::renderHtml()` (placeholder/content wiring), using the
 * real markers from `templates/pdf/maintenance.html.twig` (`class="checklist"`, `class="qr-block"`,
 * "État des lieux visuel").
 */
final class MaintenancePdfBuilderTest extends AssetsignTestCase
{
   private function createChecklistItem(string $name, int $type, string $options = ''): int {
       $item = new MaintenanceChecklistItem();

       return (int) $item->add([
           'entities_id' => 0,
           'name'        => $name,
           'is_active'   => 1,
           'type'        => $type,
           'options'     => $options,
       ]);
   }

   public function testRenderHtmlIncludesItemCommentAndChecklistResults(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder Content');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder Content');
       $textId = $this->createChecklistItem('PHPUnit Etat general', MaintenanceChecklistItem::TYPE_TEXT);

       $id = Maintenance::createWithChecklist(
           'Computer',
           $computer->getID(),
           $entityId,
           [$textId => 'Bon etat'],
           'Un commentaire tres particulier'
       );
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringContainsString('PHPUnit PC MaintenancePdfBuilder Content', $html, 'Le nom GLPI du materiel doit apparaitre.');
       $this->assertStringContainsString('Un commentaire tres particulier', $html);
       $this->assertStringContainsString('class="checklist"', $html, 'Au moins un point de controle rempli doit produire la liste, pas le message "aucun".');
       $this->assertStringContainsString('PHPUnit Etat general', $html);
       $this->assertStringContainsString('Bon etat', $html);
       $this->assertStringNotContainsString('Aucun point de contrôle validé.', $html);
   }

   public function testRenderHtmlShowsTheNoChecklistMessageWhenNothingWasFilled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder Empty');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder Empty');

       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringContainsString('Aucun point de contrôle validé.', $html);
       $this->assertStringNotContainsString('class="checklist"', $html);
   }

   public function testRenderHtmlIncludesTheQrBlockOnlyWhenShowQrCodeIsEnabled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder QrOn');
       Config::upsertForEntity($entityId, ['show_qr_code' => '1']);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder QrOn');
       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringContainsString('class="qr-block"', $html);
   }

   public function testRenderHtmlOmitsTheQrBlockWhenShowQrCodeIsDisabled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder QrOff');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder QrOff');
       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringNotContainsString('class="qr-block"', $html, 'show_qr_code=0 (le defaut) ne doit jamais inclure le QR code.');
   }

    /**
     * Les 3 vues de reference sont toujours incluses des que le reglage est actif, meme sans aucun
     * repere encore depose (cf. PdfRenderingHelpers::getDamageViewsForPdf() sa propre docblock) —
     * pas besoin de creer un vrai DamageMarker pour verifier que la section apparait.
     */
   public function testRenderHtmlIncludesDamageViewsSectionOnlyWhenAnnotationIsEnabled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder DamageOn');
       Config::upsertForEntity($entityId, ['enable_damage_annotation' => '1']);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder DamageOn');
       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringContainsString('État des lieux visuel', $html);
   }

   public function testRenderHtmlOmitsDamageViewsSectionWhenAnnotationIsDisabled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder DamageOff');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder DamageOff');
       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringNotContainsString('État des lieux visuel', $html, 'enable_damage_annotation=0 (le defaut) ne doit jamais inclure la section.');
   }

   public function testRenderHtmlIncludesTheSignatureBlockOnlyWhenSignatureIsRequired(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder SigOn');
       Config::upsertForEntity($entityId, ['enable_maintenance_signature' => '1']);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder SigOn');
       $signature = self::signatureStrokeDataUri();

       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '', [], $signature);
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance, $signature, date('Y-m-d H:i:s'));

       $this->assertStringContainsString('class="signature-zone"', $html);
       $this->assertStringContainsString('Document signé électroniquement', $html);
   }

   public function testRenderHtmlOmitsTheSignatureBlockWhenSignatureIsNotRequiredForTheEntity(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit MaintenancePdfBuilder SigOff');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenancePdfBuilder SigOff');
       $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
       $maintenance = new Maintenance();
       $maintenance->getFromDB($id);

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringNotContainsString('class="signature-zone"', $html, 'enable_maintenance_signature=0 (le defaut) ne doit jamais inclure la zone de signature.');
   }

    /** Meme generateur que MaintenanceTest/SignatureImageValidatorTest (canevas GD isole). */
   private static function signatureStrokeDataUri(int $width = 300, int $height = 100): string {
       $image = imagecreatetruecolor($width, $height);
       $white = imagecolorallocate($image, 255, 255, 255);
       $black = imagecolorallocate($image, 0, 0, 0);
       imagefilledrectangle($image, 0, 0, $width, $height, $white);
       imageline($image, 10, 10, $width - 10, $height - 10, $black);

       ob_start();
       imagepng($image);
       $binary = ob_get_clean();
       imagedestroy($image);

       return 'data:image/png;base64,' . base64_encode($binary);
   }
}
