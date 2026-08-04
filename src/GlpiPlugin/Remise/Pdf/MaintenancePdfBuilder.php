<?php

namespace GlpiPlugin\Remise\Pdf;

use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\DamageMarker;
use GlpiPlugin\Remise\Maintenance;
use GlpiPlugin\Remise\Remise;

/**
 * Construit le PDF d'une fiche de maintenance, meme gabarit visuel (logo,
 * style, etat des lieux visuel) que HandoverPdfBuilder (Remise), via le
 * trait partage PdfRenderingHelpers — cf. templates/pdf/maintenance.html.twig.
 *
 * Contrairement a Remise (PDF non signe genere a la creation, remplace par un
 * PDF signe apres passage du beneficiaire sur une page separee, cf.
 * SignatureStamper), une fiche de maintenance ne genere qu'UN SEUL PDF, une
 * seule fois : le technicien signe (si active pour l'entite) directement sur
 * le meme formulaire de creation, en une seule requete — il n'y a donc jamais
 * de PDF "non signe" intermediaire a remplacer ensuite.
 */
final class MaintenancePdfBuilder
{
   use PdfRenderingHelpers;

    /**
     * @param string|null $signatureImage Data URI PNG deja validee par l'appelant
     *        (cf. Maintenance::createWithChecklist()), ou null si la signature
     *        n'est pas activee pour l'entite.
     * @return array{document: Document, hash: ?string} Empreinte SHA-256 du PDF,
     *         uniquement calculee quand une signature y est incrustee (sert de
     *         preuve, cf. Signature::recordProofForMaintenance()).
     */
   public function build(Maintenance $maintenance, ?string $signatureImage = null, ?string $signedAt = null): array {
       $html = $this->renderHtml($maintenance, $signatureImage, $signedAt);
       $protect = (bool) Config::getForEntity((int) $maintenance->fields['entities_id'])->fields['protect_pdf'];
       $binary = $this->renderPdf($html, $protect);
       $hash = $signatureImage !== null ? hash('sha256', $binary) : null;

       return [
           'document' => $this->storeAsDocument($maintenance, $binary),
           'hash'     => $hash,
       ];
   }

   public function renderHtml(Maintenance $maintenance, ?string $signatureImage = null, ?string $signedAt = null): string {
       $config = Config::getForEntity((int) $maintenance->fields['entities_id']);
       $qrDataUri = (bool) $config->fields['show_qr_code']
           ? $this->getQrCodeDataUri(rtrim((string) ($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . '/plugins/remise/front/maintenance.form.php?id=' . $maintenance->getID())
           : null;

       return TemplateRenderer::getInstance()->render('@remise/pdf/maintenance.html.twig', [
           'maintenance'         => $maintenance->fields,
           'technician'          => $maintenance->getTechnician(),
           'item'                => $maintenance->getTargetItem(),
           'itemtype'            => Remise::getCanonicalItemtypeLabel($maintenance->fields['itemtype']),
           'checklist_results'   => $maintenance->getChecklistResults(),
           'comment'             => $maintenance->fields['comment'] ?? '',
           'damage_views'        => (bool) $config->fields['enable_damage_annotation']
               ? $this->getDamageViewsForPdf(DamageMarker::getForMaintenance($maintenance->getID()))
               : [],
           'signature_required'  => (bool) $config->fields['enable_maintenance_signature'],
           'signature_image'     => $signatureImage,
           'signed_at'           => $signedAt,
           'logo_data_uri'       => $this->getLogoDataUri((int) $maintenance->fields['entities_id']),
           'company_name'        => $config->fields['company_name'] ?: null,
           'qr_data_uri'         => $qrDataUri,
           'document_title'      => $maintenance->getDocumentTitle(),
       ]);
   }

   private function storeAsDocument(Maintenance $maintenance, string $pdfBinary): Document {
       // Document::moveDocument() lit obligatoirement le fichier source dans
       // GLPI_TMP_DIR, meme convention que HandoverPdfBuilder::storeAsDocument().
       $tmpPath = GLPI_TMP_DIR . '/' . uniqid('remise_maintenance_', true) . '.pdf';
       file_put_contents($tmpPath, $pdfBinary);

       $document = new Document();
       $documents_id = $document->add([
           'name'          => $maintenance->getDocumentTitle(),
           'entities_id'   => $maintenance->fields['entities_id'],
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);
       $document->getFromDB($documents_id);

       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => $maintenance->fields['itemtype'],
           'items_id'     => $maintenance->fields['items_id'],
       ]);
      if ((int) $maintenance->fields['users_id_tech'] > 0) {
          (new Document_Item())->add([
              'documents_id' => $documents_id,
              'itemtype'     => 'User',
              'items_id'     => $maintenance->fields['users_id_tech'],
          ]);
      }

       return $document;
   }
}
