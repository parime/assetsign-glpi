<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\ConfigTransfer;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\Pdf\PdfMetadata;
use GlpiPlugin\Assetsign\Pdf\SignatureStamper;

/**
 * Issue #280 : titre GLPI sous la signature, métadonnées du PDF, protection, technicien de la
 * fiche. Assertions indépendantes de la langue de l'instance.
 */
class PdfSignerAndMetadataTest extends AssetsignTestCase
{
    use PdfTextExtraction;

    private const SIGNATURE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $files = [];

    private mixed $savedGlpiId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedGlpiId = $_SESSION['glpiID'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if ($this->savedGlpiId === null) {
            unset($_SESSION['glpiID']);
        } else {
            $_SESSION['glpiID'] = $this->savedGlpiId;
        }
        parent::tearDown();
    }

    public function testSignerTitleComesFromTheGlpiTitleField(): void
    {
        $titleId = (int) (new \UserTitle())->add(['name' => 'Responsable parc fictif']);
        $this->assertSame('Responsable parc fictif', PdfMetadata::signerTitle(['usertitles_id' => $titleId]));
        $this->assertSame('', PdfMetadata::signerTitle(['usertitles_id' => 0]));
        $this->assertSame('', PdfMetadata::signerTitle(null));
    }

    public function testMetadataValues(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Metadonnees');
        $meta = PdfMetadata::build("Titre\nfictif", $entityId, 'Camille Fictif');

        $this->assertSame('Titre fictif', $meta['Title'], 'Control characters must not reach the PDF info dictionary.');
        $this->assertSame('PHPUnit Metadonnees', $meta['Author'], 'Without a company name, the issuer is the entity.');
        $this->assertSame('Camille Fictif', $meta['Subject']);
        $this->assertMatchesRegularExpression('/^GLPI \S+ - AssetSign \S+$/', $meta['Creator']);
        $this->assertStringContainsString(PLUGIN_ASSETSIGN_VERSION, $meta['Creator']);

        Config::upsertForEntity($entityId, ['company_name' => 'Societe Fictive']);
        $this->assertSame('Societe Fictive', PdfMetadata::build('x', $entityId)['Author']);
    }

    public function testSignedPdfCarriesSignerTitleAndMetadata(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Signataire');
        Config::upsertForEntity($entityId, ['company_name' => 'Societe Fictive']);
        $titleId = (int) (new \UserTitle())->add(['name' => 'Responsable parc fictif']);
        $userId = $this->createTestUser('Camille', 'Fictif', ['usertitles_id' => $titleId]);
        $assetsign = $this->createHandover($entityId, $userId);

        $result = (new SignatureStamper())->apply($assetsign, self::SIGNATURE_PNG);
        $this->files[] = $result['path'];
        $pdf = (string) file_get_contents($result['path']);

        $this->assertStringContainsString('Responsable parc fictif', implode(' ', $this->extractPageTexts($pdf)));
        $this->assertSame($assetsign->getDocumentTitle(), $this->extractPdfInfo($pdf, 'Title'));
        $this->assertSame('Societe Fictive', $this->extractPdfInfo($pdf, 'Author'));
        $this->assertSame(PdfMetadata::personName($assetsign->getBeneficiary()), $this->extractPdfInfo($pdf, 'Subject'));
        $this->assertStringContainsString('Camille', (string) $this->extractPdfInfo($pdf, 'Subject'));
        $this->assertSame(PdfMetadata::creator(), $this->extractPdfInfo($pdf, 'Creator'));
    }

    public function testSignatureWithoutGlpiTitleFallsBackToAGenericLabel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Signataire sans titre');
        $userId = $this->createTestUser('Alex', 'Fictif');
        $assetsign = $this->createHandover($entityId, $userId);

        $html = (new HandoverPdfBuilder())->renderHtml($assetsign, [
            'signature_image' => self::SIGNATURE_PNG,
            'signed_at'       => date('Y-m-d H:i:s'),
            'signer_name'     => 'Alex Fictif',
            'signer_title'    => '',
        ]);
        $this->assertMatchesRegularExpression('/<p class="signer-title">[^<\s][^<]*<\/p>/', $html, 'A generic label must replace a missing title.');

        $html = (new HandoverPdfBuilder())->renderHtml($assetsign, [
            'signature_image' => self::SIGNATURE_PNG,
            'signed_at'       => date('Y-m-d H:i:s'),
            'signer_name'     => 'Alex Fictif',
            'signer_title'    => '<b>Titre</b>',
        ]);
        $this->assertStringContainsString('<p class="signer-title">&lt;b&gt;Titre&lt;/b&gt;</p>', $html);

        $unsigned = (new HandoverPdfBuilder())->renderHtml($assetsign);
        $this->assertStringNotContainsString('signer-title">', $unsigned, 'No label without a signature.');
    }

    public function testProtectedPdfKeepsMetadataAndForbidsCopyAndEdit(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Protection');
        Config::upsertForEntity($entityId, ['protect_pdf' => 1]);
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Sam', 'Fictif'));

        $result = (new SignatureStamper())->apply($assetsign, self::SIGNATURE_PNG);
        $this->files[] = $result['path'];
        $pdf = (string) file_get_contents($result['path']);

        $this->assertStringContainsString('/Encrypt', $pdf);
        $this->assertMatchesRegularExpression('/\/P (-?\d+)/', $pdf);
        preg_match('/\/P (-?\d+)/', $pdf, $match);
        $permissions = (int) $match[1];
        $this->assertNotSame(0, $permissions & 4, 'Printing stays allowed.');
        $this->assertSame(0, $permissions & 8, 'Editing is forbidden.');
        $this->assertSame(0, $permissions & 16, 'Copying is forbidden.');
        $this->assertDoesNotMatchRegularExpression('/\/U \(\)/', $pdf);
        $this->assertSame(hash('sha256', $pdf), $result['hash'], 'The SHA-256 fingerprint remains the integrity proof.');
    }

    public function testTechnicianOfTheFormFollowsTheEntitySetting(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Technicien');
        $creatorId = $this->createTestUser('Claude', 'Createur');
        $itemTechId = $this->createTestUser('Dominique', 'Responsable');
        $_SESSION['glpiID'] = $creatorId;

        $computer = $this->createTestComputer($entityId, 'PC-FICTIF-TECH');
        $computer->update(['id' => $computer->getID(), 'users_id_tech' => $itemTechId]);
        $computer->getFromDB($computer->getID());
        $bare = $this->createTestComputer($entityId, 'PC-FICTIF-SANS-TECH');

        $this->assertSame($creatorId, Assetsign::resolveTechnicianId($computer), 'Disabled by default: the creator stays the technician.');

        Config::upsertForEntity($entityId, ['technician_from_item' => 1]);
        $this->assertSame($itemTechId, Assetsign::resolveTechnicianId($computer));
        $this->assertSame($creatorId, Assetsign::resolveTechnicianId($bare), 'Without a technician in charge, the creator.');

        $this->assertSame(1, ConfigTransfer::export($entityId, false, false)['config']['technician_from_item']);
    }

    private function createHandover(int $entityId, int $userId): Assetsign
    {
        $computer = $this->createTestComputer($entityId, 'PC-FICTIF-280');
        $assetsign = new Assetsign();
        $id = (int) $assetsign->add([
            'entities_id' => $entityId,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => $userId,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => Assetsign::STATUS_SIGNED,
        ]);
        $assetsign->getFromDB($id);
        return $assetsign;
    }
}
