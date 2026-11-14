<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\AssetsignFormController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\MyEquipment;
use GlpiPlugin\Assetsign\PaperSignature;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\Profile;
use GlpiPlugin\Assetsign\Signature;
use GlpiPlugin\Assetsign\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Issue #276 : version papier. PDF pre-rempli a signer a la main (cadres vides, etat des lieux
 * imprimable), puis scan signe joint par un technicien : statut distinct, preuve SHA-256, lien
 * electronique invalide. Assertions independantes de la langue de l'instance (le PDF est rendu dans
 * la langue par defaut forcee en fr_FR par AssetsignTestCase ; aucun message d'erreur compare).
 */
final class PaperSignatureTest extends AssetsignTestCase
{
    use PdfTextExtraction;

    /** @var list<string> */
    private array $files = [];

    private mixed $savedGlpiId = null;

    private mixed $savedRight = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedGlpiId = $_SESSION['glpiID'] ?? null;
        $this->savedRight = $_SESSION['glpiactiveprofile'][Profile::RIGHT_ASSETSIGN] ?? null;
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
        if ($this->savedRight === null) {
            unset($_SESSION['glpiactiveprofile'][Profile::RIGHT_ASSETSIGN]);
        } else {
            $_SESSION['glpiactiveprofile'][Profile::RIGHT_ASSETSIGN] = $this->savedRight;
        }
        parent::tearDown();
    }

    public function testPaperPdfIsPrefilledWithEmptySignatureBoxesAndAConditionReport(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier PDF');
        $beneficiary = $this->createTestUser('Camille', 'Papierfictif');
        $technician = $this->createTestUser('Hugo', 'Technifictif');
        $assetsign = $this->createHandover($entityId, $beneficiary, Assetsign::STATUS_SENT, ['users_id_tech' => $technician], 'SN-PAPIER-276');

        $html = (new HandoverPdfBuilder())->renderPaperHtml($assetsign, PaperSignature::VARIANT_FORM);

        $this->assertStringContainsString('Papierfictif', $html, 'Beneficiaire pre-rempli.');
        $this->assertStringContainsString('PC-PAPIER-276', $html, 'Materiel pre-rempli.');
        $this->assertStringContainsString('SN-PAPIER-276', $html, 'Numero de serie pre-rempli.');
        $this->assertStringContainsString('Technifictif', $html, 'Cadre du technicien de la fiche.');
        $this->assertSame(2, substr_count($html, 'class="paper-sign-box"'), 'Un cadre vide par signataire (beneficiaire, technicien).');
        $this->assertStringNotContainsString('class="signature-img"', $html, 'Aucune signature incrustee.');
        $this->assertStringContainsString('paper-page-break', $html, 'Etat des lieux sur une nouvelle page.');
        $this->assertStringContainsString('paper-check-table', $html);
        $this->assertSame(4, substr_count($html, 'class="paper-box"'), 'Une ligne (le materiel) x quatre etats a cocher.');
        $this->assertStringContainsString('class="paper-notes"', $html, 'Zone de notes manuscrites.');
        $this->assertStringContainsString('data:image/', $html, 'Schemas de l\'etat des lieux visuel.');

        $condition = (new HandoverPdfBuilder())->renderPaperHtml($assetsign, PaperSignature::VARIANT_CONDITION);
        $this->assertStringContainsString('paper-check-table', $condition);
        $this->assertStringNotContainsString('paper-page-break', $condition, 'Etat des lieux seul : une page.');
        $this->assertSame(2, substr_count($condition, 'class="paper-sign-box"'));
    }

    public function testPrintingProducesARealPdfTracesItAndNeverChangesTheStatus(): void
    {
        global $DB;
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Impression');
        $technician = $this->createTestUser('Ines', 'Imprimefictif');
        $_SESSION['glpiID'] = $technician;
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Leo', 'Lecteurfictif'), Assetsign::STATUS_SENT);

        $pdf = PaperSignature::renderPdf($assetsign, PaperSignature::VARIANT_FORM, $technician);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $pages = $this->extractPageTexts($pdf);
        $this->assertGreaterThanOrEqual(2, count($pages), 'Fiche puis etat des lieux.');
        $this->assertStringContainsString('Lecteurfictif', implode(' ', $pages));

        $condition = PaperSignature::renderPdf($assetsign, PaperSignature::VARIANT_CONDITION, $technician);
        $this->assertCount(1, $this->extractPageTexts($condition), 'Etat des lieux imprimable : une page A4.');

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SENT, (int) $assetsign->fields['status'], 'Imprimer ne signe rien.');
        $this->assertNotEmpty($assetsign->fields['paper_printed_at']);
        $this->assertSame($technician, (int) $assetsign->fields['paper_printed_by_users_id']);
        $this->assertSame(0, (int) $assetsign->fields['document_id_paper_scan']);
        $this->assertNull(Signature::getForAssetsign($assetsign->getID()));

        $itemLogs = $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_logs',
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => (int) $assetsign->fields['items_id'], 'new_value' => ['LIKE', '%Imprimefictif%']],
        ])->current()['c'];
        $this->assertSame(2, (int) $itemLogs, 'Chaque impression est tracee dans l\'historique du materiel.');
    }

    public function testAttachingTheScanMarksThePaperSignatureWithItsEvidence(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Scan');
        $technician = $this->createTestUser('Nora', 'Scanfictif');
        $_SESSION['glpiID'] = $technician;
        $beneficiary = $this->createTestUser('Sami', 'Signefictif');
        $assetsign = $this->createHandover($entityId, $beneficiary, Assetsign::STATUS_SENT);
        $rawToken = Token::createForAssetsign($assetsign, 10);

        $scan = $this->makePng();
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        // La fiche vient d'etre creee : date de signature du jour (pas avant la creation).
        PaperSignature::attachScan($assetsign, $scan, 'scan-signe.png', $technician, date('Y-m-d'), ['ip' => '192.0.2.10', 'user_agent' => 'PHPUnit papier']);

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SIGNED_ON_PAPER, (int) $assetsign->fields['status']);
        $this->assertNotSame(Assetsign::STATUS_SIGNED, (int) $assetsign->fields['status'], 'Statut distinct de la signature electronique.');
        $this->assertSame($technician, (int) $assetsign->fields['paper_scan_users_id']);
        $this->assertSame(date('Y-m-d'), (string) $assetsign->fields['paper_signature_date']);
        $this->assertNotEmpty($assetsign->fields['date_signed']);
        $this->assertSame(0, (int) $assetsign->fields['document_id_signed'], 'Aucun PDF signe electroniquement n\'est fabrique.');

        $document = new \Document();
        $this->assertTrue($document->getFromDB((int) $assetsign->fields['document_id_paper_scan']));
        $this->assertSame('image/png', (string) $document->fields['mime']);
        $hash = hash_file('sha256', $scan);
        $this->assertSame($hash, hash_file('sha256', GLPI_DOC_DIR . '/' . $document->fields['filepath']), 'Le fichier enregistre est le scan recu.');

        $proof = Signature::getForAssetsign($assetsign->getID());
        $this->assertSame(Signature::METHOD_PAPER, $proof['signature_method']);
        $this->assertSame($hash, $proof['document_hash']);
        $this->assertStringContainsString('Signefictif', (string) $proof['signer_name']);
        $this->assertSame('192.0.2.10', $proof['ip_address']);

        try {
            Token::validate($rawToken);
            $this->fail('Le lien de signature electronique ne doit plus fonctionner.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        // Badges et listes : statut signe, pastille propre, scan propose au beneficiaire.
        $this->assertContains(Assetsign::STATUS_SIGNED_ON_PAPER, Assetsign::STATUSES_SIGNED);
        $this->assertNotSame(
            Assetsign::getStatusBadgeClasses()[Assetsign::STATUS_SIGNED],
            Assetsign::getStatusBadgeClasses()[Assetsign::STATUS_SIGNED_ON_PAPER]
        );
        $this->assertArrayHasKey(Assetsign::STATUS_SIGNED_ON_PAPER, Assetsign::getStatuses());
        $forBeneficiary = MyEquipment::signedDocumentFor($assetsign->getID(), $beneficiary);
        $this->assertNotNull($forBeneficiary);
        $this->assertSame((int) $assetsign->fields['document_id_paper_scan'], (int) $forBeneficiary->getID());

        // Plus rien a signer : ni nouvelle impression, ni second scan.
        $this->expectException(RuntimeException::class);
        PaperSignature::attachScan($assetsign, $this->makePng(), 'autre.png', $technician, $yesterday);
    }

    public function testAPdfScanIsAccepted(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier PDF scan');
        $technician = $this->createTestUser('Paul', 'Pdffictif');
        $_SESSION['glpiID'] = $technician;
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Rose', 'Retourfictif'), Assetsign::STATUS_DRAFT);

        $scan = $this->tempFile((new HandoverPdfBuilder())->renderPdf('<html><body><p>Scan fictif</p></body></html>'));
        PaperSignature::attachScan($assetsign, $scan, 'SCAN.PDF', $technician);

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SIGNED_ON_PAPER, (int) $assetsign->fields['status'], 'Un brouillon peut etre signe sur papier sans jamais etre envoye.');
        $this->assertEmpty($assetsign->fields['date_sent'], 'Aucun envoi electronique.');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'script PHP' => ["<?php echo 'x';", 'scan.php'];
        yield 'texte deguise en PNG' => ['pas une image', 'scan.png'];
        yield 'texte deguise en PDF' => ['pas un PDF', 'scan.pdf'];
        yield 'extension non prevue' => ['GIF89a', 'scan.gif'];
        yield 'fichier vide' => ['', 'scan.pdf'];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusedFilesChangeNothing(string $content, string $name): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Refus');
        $technician = $this->createTestUser('Yann', 'Refusfictif');
        $_SESSION['glpiID'] = $technician;
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Zoe', 'Intactfictif'), Assetsign::STATUS_SENT);

        try {
            PaperSignature::attachScan($assetsign, $this->tempFile($content), $name, $technician);
            $this->fail('Fichier refuse attendu.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SENT, (int) $assetsign->fields['status']);
        $this->assertSame(0, (int) $assetsign->fields['document_id_paper_scan']);
        $this->assertNull(Signature::getForAssetsign($assetsign->getID()));
    }

    public function testAPngWithAWrongExtensionIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        PaperSignature::validateScan($this->makePng(), 'scan.pdf');
    }

    public function testFutureSignatureDateIsRefused(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Date');
        $technician = $this->createTestUser('Adam', 'Datefictif');
        $_SESSION['glpiID'] = $technician;
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Eva', 'Futurfictif'), Assetsign::STATUS_SENT);

        $this->expectException(RuntimeException::class);
        PaperSignature::attachScan($assetsign, $this->makePng(), 'scan.png', $technician, date('Y-m-d', strtotime('+2 days')));
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function closedStatuses(): iterable
    {
        yield 'signee electroniquement' => [Assetsign::STATUS_SIGNED];
        yield 'annulee' => [Assetsign::STATUS_CANCELLED];
        yield 'expiree' => [Assetsign::STATUS_EXPIRED];
        yield 'en attente du responsable' => [Assetsign::STATUS_AWAITING_COSIGNATURE];
    }

    #[DataProvider('closedStatuses')]
    public function testNoPaperVersionOnceTheFormIsClosed(int $status): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Ferme');
        $technician = $this->createTestUser('Max', 'Fermefictif');
        $_SESSION['glpiID'] = $technician;
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Lou', 'Closfictif'), $status);

        $this->assertFalse(PaperSignature::isAvailableFor($assetsign));
        try {
            PaperSignature::renderPdf($assetsign, PaperSignature::VARIANT_FORM, $technician);
            $this->fail('Impression refusee attendue.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(RuntimeException::class);
        PaperSignature::attachScan($assetsign, $this->makePng(), 'scan.png', $technician);
    }

    public function testWithoutTheUpdateRightNothingIsPossible(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Droit');
        $reader = $this->createTestUser('Rita', 'Lecturefictif');
        $_SESSION['glpiID'] = $reader;
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Tom', 'Droitfictif'), Assetsign::STATUS_SENT);
        $_SESSION['glpiactiveprofile'][Profile::RIGHT_ASSETSIGN] = READ;

        try {
            PaperSignature::renderPdf($assetsign, PaperSignature::VARIANT_FORM, $reader);
            $this->fail('Impression refusee attendue sans droit de modification.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        try {
            PaperSignature::attachScan($assetsign, $this->makePng(), 'scan.png', $reader);
            $this->fail('Scan refuse attendu sans droit de modification.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SENT, (int) $assetsign->fields['status']);
        $this->assertFalse($assetsign->getPaperDisplayData()['can_act'], 'Aucun bouton affiche.');
    }

    public function testControllerAcceptsOnlyARealUpload(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Papier Controleur');
        $_SESSION['glpiID'] = $this->createTestUser('Ugo', 'Uploadfictif');
        $assetsign = $this->createHandover($entityId, $this->createTestUser('Vic', 'Envoifictif'), Assetsign::STATUS_SENT);
        $controller = new AssetsignFormController();

        foreach ([
            [],
            ['paper_scan' => ['name' => 'scan.png', 'tmp_name' => $this->makePng(), 'error' => UPLOAD_ERR_OK, 'size' => 10]],
            ['paper_scan' => ['name' => 'scan.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]],
        ] as $files) {
            try {
                $controller->attachPaperScan($assetsign, [], $files);
                $this->fail('Seul un vrai televersement HTTP est accepte.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SENT, (int) $assetsign->fields['status']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createHandover(int $entityId, int $userId, int $status, array $extra = [], string $serial = ''): Assetsign
    {
        $computer = $this->createTestComputer($entityId, 'PC-PAPIER-276');
        if ($serial !== '') {
            $computer->update(['id' => $computer->getID(), 'serial' => $serial]);
        }
        $assetsign = new Assetsign();
        $id = (int) $assetsign->add($extra + [
            'entities_id' => $entityId,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => $userId,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => $status,
        ]);
        $assetsign->getFromDB($id);
        return $assetsign;
    }

    private function makePng(): string
    {
        $image = imagecreatetruecolor(120, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 30, 110, 30, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        return $this->tempFile($binary);
    }

    private function tempFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'paper276');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }
}
