<?php

namespace GlpiPlugin\Assetsign\Pdf;

use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\WipeAttestation;

/**
 * Issue #322 : PDF signé de l'attestation d'effacement des données, même mise en page que la fiche
 * de maintenance (logo, en-tête de maîtrise documentaire #274, titre du signataire #280). Un seul
 * PDF, généré une fois : le technicien signe sur le formulaire de création.
 */
final class WipeAttestationPdfBuilder
{
   use PdfRenderingHelpers;

    /**
     * @return array{document: Document, hash: string} empreinte SHA-256 du PDF enregistré
     */
   public function build(WipeAttestation $attestation, string $signatureImage, string $signedAt): array {
       $entitiesId = (int) $attestation->fields['entities_id'];
       $html = $this->renderHtml($attestation, $signatureImage, $signedAt);
       $binary = $this->renderPdf($html, (bool) Config::getForEntity($entitiesId)->fields['protect_pdf'], PdfMetadata::build(
           $attestation->getDocumentTitle(),
           $entitiesId,
           PdfMetadata::personName($attestation->getTechnician())
       ));
       return [
           'document' => $this->storeAsDocument($attestation, $binary),
           'hash'     => hash('sha256', $binary),
       ];
   }

   public function renderHtml(WipeAttestation $attestation, ?string $signatureImage = null, ?string $signedAt = null): string {
       // Dans la langue du technicien, seul signataire (comme la fiche de maintenance).
       return PdfLanguage::run(
           PdfLanguage::forUser((int) $attestation->fields['users_id_tech']),
           fn (): string => $this->renderLocalizedHtml($attestation, $signatureImage, $signedAt)
       );
   }

   private function renderLocalizedHtml(WipeAttestation $attestation, ?string $signatureImage, ?string $signedAt): string {
       $entitiesId = (int) $attestation->fields['entities_id'];
       $config = Config::getForEntity($entitiesId);
       $technician = $attestation->getTechnician();
       $level = (string) $attestation->fields['nist_level'];

       return TemplateRenderer::getInstance()->render('@assetsign/pdf/wipe_attestation.html.twig', [
           'html_lang'        => PdfLanguage::htmlLang(),
           'attestation'      => $attestation->fields,
           'item'             => $attestation->getTargetItem(),
           'itemtype'         => Assetsign::getItemtypeLabel((string) $attestation->fields['itemtype']),
           'technician_name'  => PdfMetadata::personName($technician),
           'technician_title' => PdfMetadata::signerTitle($technician),
           'method_label'     => WipeAttestation::getMethodLabels()[$attestation->fields['method']] ?? '',
           'level_label'      => $level !== '' ? (WipeAttestation::getLevelLabels()[$level] ?? '') : '',
           'level_help'       => WipeAttestation::getLevelHelp()[$level] ?? '',
           'result_label'     => WipeAttestation::getResultLabels()[(int) $attestation->fields['verification_result']] ?? '',
           'media'            => $attestation->getMedia(),
           'report_hash'      => (string) ($attestation->fields['report_hash'] ?? ''),
           'date_wipe'        => date('d/m/Y H:i', strtotime((string) $attestation->fields['date_wipe'])),
           'signature_image'  => $signatureImage,
           'signed_at'        => $signedAt,
           'logo_data_uri'    => $this->getLogoDataUri($entitiesId),
           'company_name'     => $config->fields['company_name'] ?: null,
           'document_title'   => $attestation->getDocumentTitle(),
           'doc_header'       => DocumentControlHeader::forEntity($entitiesId),
       ]);
   }

   private function storeAsDocument(WipeAttestation $attestation, string $pdfBinary): Document {
       $tmpPath = GLPI_TMP_DIR . '/' . uniqid('assetsign_wipe_', true) . '.pdf';
       file_put_contents($tmpPath, $pdfBinary);

       $document = new Document();
       $documentsId = (int) $document->add([
           'name'          => $attestation->getDocumentTitle(),
           'entities_id'   => $attestation->fields['entities_id'],
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);
       $document->getFromDB($documentsId);

       $links = [
           [WipeAttestation::class, $attestation->getID()],
           [(string) $attestation->fields['itemtype'], (int) $attestation->fields['items_id']],
       ];
       if ((int) $attestation->fields['users_id_tech'] > 0) {
          $links[] = ['User', (int) $attestation->fields['users_id_tech']];
       }
       foreach ($links as [$itemtype, $itemsId]) {
          (new Document_Item())->add(['documents_id' => $documentsId, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
       }
       return $document;
   }
}
