<?php

namespace GlpiPlugin\Remise\Pdf;

use Document;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\DamageMarker;
use GlpiPlugin\Remise\Maintenance;
use GlpiPlugin\Remise\Remise;

/**
 * Construit le compte-rendu PDF d'une fiche de maintenance, calque sur
 * HandoverPdfBuilder (meme logo, meme mise en page) mais sans rien de propre
 * au flux signe (pas de beneficiaire, pas de signature, pas de gabarit/charte)
 * — cf. le commentaire de classe de Maintenance : un sous-systeme separe, qui
 * n'a longtemps genere aucun PDF, jusqu'a cette fonctionnalite. Un seul PDF
 * est genere, une seule fois, juste apres la creation de la fiche (cf.
 * Maintenance::createWithChecklist()) : contrairement a Remise, une fiche de
 * maintenance n'est jamais modifiee ensuite, pas besoin de mecanisme de
 * regeneration.
 */
final class MaintenancePdfBuilder
{
   use PdfRendering;

   public function build(Maintenance $maintenance): Document {
       $html = $this->renderHtml($maintenance);
       $binary = $this->renderPdf($html);

       return $this->storeAsDocument($maintenance, $binary);
   }

   private function renderHtml(Maintenance $maintenance): string {
       $config = Config::getForEntity((int) $maintenance->fields['entities_id']);

       return TemplateRenderer::getInstance()->render('@remise/pdf/maintenance.html.twig', [
           'maintenance'      => $maintenance->fields,
           'technician'       => $maintenance->getTechnician(),
           'item'             => $maintenance->getTargetItem(),
           'itemtype'         => Remise::getCanonicalItemtypeLabel($maintenance->fields['itemtype']),
           'checklist_results' => $maintenance->getChecklistResults(),
           'comment'          => $maintenance->fields['comment'] ?? '',
           'damage_views'     => (bool) $config->fields['enable_damage_annotation']
               ? $this->getDamageViewsForPdf(DamageMarker::getForMaintenance($maintenance->getID()))
               : [],
           'logo_data_uri'    => $this->getLogoDataUri((int) $maintenance->fields['entities_id']),
       ]);
   }

   private function storeAsDocument(Maintenance $maintenance, string $pdfBinary): Document {
       // Document::moveDocument() (appelee par add() via l'entree "_filename")
       // lit obligatoirement le fichier source dans GLPI_TMP_DIR : meme
       // convention que HandoverPdfBuilder::storeAsDocument().
       $tmpPath = GLPI_TMP_DIR . '/' . uniqid('remise_maintenance_', true) . '.pdf';
       file_put_contents($tmpPath, $pdfBinary);

       $document = new Document();
       $documents_id = $document->add([
           'name'          => 'Maintenance #' . $maintenance->getID() . ' — ' . $maintenance->fields['itemtype'] . ' #' . $maintenance->fields['items_id'],
           'entities_id'   => $maintenance->fields['entities_id'],
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);

       $document->getFromDB($documents_id);
       return $document;
   }
}
