<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Pdf\SignatureStamper;

/**
 * `SignatureStamper::apply()` had zero direct test: `SignControllerTest` only covers the
 * authorization guard (`loadAuthorizedAssetsign()`), never the actual sign flow that calls
 * `apply()`, and `AssetsignTest`'s PDF-content assertions (placeholders, XSS sanitization,
 * delegation attribution) all go through `HandoverPdfBuilder::renderHtml()` directly — never
 * through `SignatureStamper` itself. This suite covers what's uniquely `SignatureStamper`'s own
 * responsibility: writing a real file to `GLPI_TMP_DIR`, hashing it correctly, stamping
 * `signed_at`, and honoring `protect_pdf`.
 */
final class SignatureStamperTest extends AssetsignTestCase
{
    /** 1x1 transparent PNG — real, valid image data, not a placeholder string. */
   private const SIGNATURE_PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @var string[] */
   private array $filesToCleanUp = [];

   protected function tearDown(): void {
      foreach ($this->filesToCleanUp as $path) {
         if (is_file($path)) {
            unlink($path);
         }
      }

       parent::tearDown();
   }

   public function testApplyWritesARealPdfFileWithAMatchingHashAndARecentTimestamp(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SignatureStamper');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC SignatureStamper');
       $assetsign = new Assetsign();
       $assetsignId = (int) $assetsign->add([
           'entities_id' => $entityId,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'users_id'    => 2,
           'type'        => Assetsign::TYPE_HANDOVER,
           'status'      => Assetsign::STATUS_SIGNED,
       ]);
       $assetsign->getFromDB($assetsignId);

       $result = (new SignatureStamper())->apply($assetsign, self::SIGNATURE_PNG_DATA_URL);
       $this->filesToCleanUp[] = $result['path'];

       $this->assertFileExists($result['path']);
       $this->assertStringStartsWith(GLPI_TMP_DIR, $result['path'], 'The signed PDF must be written under GLPI_TMP_DIR.');

       $binary = file_get_contents($result['path']);
       $this->assertStringStartsWith('%PDF', $binary, 'The written file must be a real PDF, not just any binary blob.');
       $this->assertSame(hash('sha256', $binary), $result['hash'], 'The returned hash must match the actual file content on disk.');

       $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['signed_at']);
       $this->assertLessThanOrEqual(5, abs(strtotime($result['signed_at']) - time()), 'signed_at must reflect the actual moment of signing.');
   }

   public function testApplyEncryptsTheOutputWhenProtectPdfIsEnabledForTheEntity(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SignatureStamper Protected');
       Config::upsertForEntity($entityId, ['protect_pdf' => 1]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC SignatureStamper Protected');
       $assetsign = new Assetsign();
       $assetsignId = (int) $assetsign->add([
           'entities_id' => $entityId,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'users_id'    => 2,
           'type'        => Assetsign::TYPE_HANDOVER,
           'status'      => Assetsign::STATUS_SIGNED,
       ]);
       $assetsign->getFromDB($assetsignId);

       $result = (new SignatureStamper())->apply($assetsign, self::SIGNATURE_PNG_DATA_URL);
       $this->filesToCleanUp[] = $result['path'];

       $binary = file_get_contents($result['path']);
       $this->assertStringContainsString('/Encrypt', $binary, 'protect_pdf=1 must produce an encrypted PDF (CPDF sets an /Encrypt trailer entry).');
   }

   public function testApplyDoesNotEncryptTheOutputWhenProtectPdfIsDisabled(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SignatureStamper Unprotected');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC SignatureStamper Unprotected');
       $assetsign = new Assetsign();
       $assetsignId = (int) $assetsign->add([
           'entities_id' => $entityId,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'users_id'    => 2,
           'type'        => Assetsign::TYPE_HANDOVER,
           'status'      => Assetsign::STATUS_SIGNED,
       ]);
       $assetsign->getFromDB($assetsignId);

       $result = (new SignatureStamper())->apply($assetsign, self::SIGNATURE_PNG_DATA_URL);
       $this->filesToCleanUp[] = $result['path'];

       $binary = file_get_contents($result['path']);
       $this->assertStringNotContainsString('/Encrypt', $binary, 'protect_pdf=0 (the default) must not encrypt the output.');
   }

   public function testApplyProducesADifferentHashForADifferentSignatureImage(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit SignatureStamper Distinct');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC SignatureStamper Distinct');
       $assetsign = new Assetsign();
       $assetsignId = (int) $assetsign->add([
           'entities_id' => $entityId,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
           'users_id'    => 2,
           'type'        => Assetsign::TYPE_HANDOVER,
           'status'      => Assetsign::STATUS_SIGNED,
       ]);
       $assetsign->getFromDB($assetsignId);

       $stamper = new SignatureStamper();
       $first = $stamper->apply($assetsign, self::SIGNATURE_PNG_DATA_URL);
       $this->filesToCleanUp[] = $first['path'];

       // A different (still 1x1, but red instead of transparent) PNG.
       $second = $stamper->apply($assetsign, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
       $this->filesToCleanUp[] = $second['path'];

       $this->assertNotSame($first['hash'], $second['hash'], 'A different signature image must produce a genuinely different signed PDF.');
   }
}
