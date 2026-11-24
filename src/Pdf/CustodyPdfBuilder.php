<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Pdf;

use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\CustodyCase;

/**
 * Issue #326 : PDF de la chaîne de possession, même mise en page que l'attestation d'effacement
 * (logo, en-tête de maîtrise documentaire). Toute l'histoire de la pièce à chaque version : les
 * étapes avec les deux signatures, les photos de la saisie avec leur empreinte, et l'empreinte
 * SHA-256 de chaque version précédente du document.
 */
final class CustodyPdfBuilder
{
    use PdfRenderingHelpers;

    /**
     * @param list<array<string, mixed>> $events étapes de la chaîne, la dernière comprise
     * @return array{documents_id: int, hash: string}
     */
   public function build(CustodyCase $case, array $events, int $version): array {
       $entitiesId = (int) $case->fields['entities_id'];
       $html = $this->renderHtml($case, $events, $version);
       $binary = $this->renderPdf($html, (bool) Config::getForEntity($entitiesId)->fields['protect_pdf'], PdfMetadata::build(
           $case->getDocumentTitle($version),
           $entitiesId,
           (string) $case->fields['seal_number']
       ));
       return [
           'documents_id' => $this->storeAsDocument($case, $binary, $version),
           'hash'         => hash('sha256', $binary),
       ];
   }

    /**
     * @param list<array<string, mixed>> $events
     */
   public function renderHtml(CustodyCase $case, array $events, int $version): string {
       // Dans la langue de la personne qui enregistre l'étape.
       return PdfLanguage::run(
           PdfLanguage::forUser((int) (\Session::getLoginUserID() ?: 0)),
           fn (): string => $this->renderLocalizedHtml($case, $events, $version)
       );
   }

    /**
     * @param list<array<string, mixed>> $events
     */
   private function renderLocalizedHtml(CustodyCase $case, array $events, int $version): string {
       $entitiesId = (int) $case->fields['entities_id'];
       $config = Config::getForEntity($entitiesId);

       $rows = CustodyCase::decorateEvents($events);
       $previous = [];
      foreach ($rows as &$row) {
          $row['sig_from_image'] = $this->imageDataUri((int) $row['sig_from_documents_id'], 'image/png');
          $row['sig_to_image'] = $this->imageDataUri((int) $row['sig_to_documents_id'], 'image/png');
         if ((int) $row['pdf_version'] > 0 && (string) $row['pdf_hash'] !== '') {
            $previous[] = ['version' => (int) $row['pdf_version'], 'date' => (string) $row['signed_at'], 'hash' => (string) $row['pdf_hash']];
         }
      }
       unset($row);

       $photos = [];
      foreach ($case->getPhotos() as $photo) {
          $photos[] = [
              'sha256' => (string) ($photo['sha256'] ?? ''),
              'image'  => $this->imageDataUri((int) ($photo['documents_id'] ?? 0), 'image/jpeg'),
          ];
      }

       return TemplateRenderer::getInstance()->render('@assetsign/pdf/custody_chain.html.twig', [
           'html_lang'      => PdfLanguage::htmlLang(),
           'case'           => $case->fields,
           'item'           => $case->getTargetItem(),
           'itemtype'       => Assetsign::getItemtypeLabel((string) $case->fields['itemtype']),
           'status_label'   => CustodyCase::getStatusLabels()[(int) $case->fields['status']] ?? '',
           'closure_label'  => CustodyCase::getClosureLabels()[(string) $case->fields['closure']] ?? '',
           'ticket_name'    => $case->getTicketName(),
           'incident_name'  => $case->getIncidentName(),
           'events'         => $rows,
           'photos'         => $photos,
           'previous'       => $previous,
           'version'        => $version,
           'generated_at'   => date('Y-m-d H:i:s'),
           'logo_data_uri'  => $this->getLogoDataUri($entitiesId),
           'company_name'   => $config->fields['company_name'] ?: null,
           'document_title' => $case->getDocumentTitle($version),
           'doc_header'     => DocumentControlHeader::forEntity($entitiesId),
       ]);
   }

    /** Image d'un Document GLPI (signature, photo) en data URI, ou null si le fichier manque. */
   private function imageDataUri(int $documentsId, string $mime): ?string {
       $document = new Document();
      if ($documentsId <= 0 || !$document->getFromDB($documentsId)) {
          return null;
      }
       $path = GLPI_DOC_DIR . '/' . (string) $document->fields['filepath'];
      if (!is_file($path)) {
          return null;
      }
       return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
   }

   private function storeAsDocument(CustodyCase $case, string $pdfBinary, int $version): int {
       $tmpPath = GLPI_TMP_DIR . '/' . uniqid('assetsign_custody_', true) . '.pdf';
       file_put_contents($tmpPath, $pdfBinary);

       $document = new Document();
       $documentsId = (int) $document->add([
           'name'          => $case->getDocumentTitle($version),
           'comment'       => 'SHA-256 : ' . hash('sha256', $pdfBinary),
           'entities_id'   => $case->fields['entities_id'],
           'is_recursive'  => 0,
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);
      if ($documentsId <= 0) {
          throw new \RuntimeException(__('Échec de l\'enregistrement de la chaîne de possession.', 'assetsign'));
      }
      foreach ($case->getDocumentTargets() as [$itemtype, $itemsId]) {
          (new Document_Item())->add(['documents_id' => $documentsId, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
      }
       return $documentsId;
   }
}
