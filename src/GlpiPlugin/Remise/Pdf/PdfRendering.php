<?php

namespace GlpiPlugin\Remise\Pdf;

use Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\DamageMarker;

/**
 * Rendu Dompdf, logo d'entite et etat des lieux visuel, communs a tout
 * gabarit PDF de ce plugin (HandoverPdfBuilder, MaintenancePdfBuilder...) -
 * extrait ici pour eviter de recopier ces methodes (identiques, sans rien de
 * propre a un type de fiche en particulier) a chaque nouveau type de document
 * genere.
 */
trait PdfRendering
{
   public function renderPdf(string $html): string {
       $options = new Options();
       $options->set('isRemoteEnabled', false);
       $options->set('defaultFont', 'DejaVu Sans');
       $options->set('chroot', GLPI_DOC_DIR);

       $dompdf = new Dompdf($options);
       $dompdf->loadHtml($html, 'UTF-8');
       $dompdf->setPaper('A4', 'portrait');
       $dompdf->render();

       return $dompdf->output();
   }

    /**
     * Le logo est configurable par entite (Config::logo_documents_id, cf.
     * l'onglet Entite / Remise & signature > Configuration). Encode en data URI
     * plutot qu'un chemin de fichier : Dompdf tourne avec isRemoteEnabled=false
     * (aucun fetch reseau autorise), un data URI evite tout probleme de chemin
     * relatif/chroot pour une image qui peut venir de n'importe quel sous-dossier
     * de GLPI_DOC_DIR.
     */
   protected function getLogoDataUri(int $entities_id): ?string {
       $documents_id = Config::getEffectiveLogoDocumentId($entities_id);
      if ($documents_id <= 0) {
          return null;
      }

       $document = new Document();
      if (!$document->getFromDB($documents_id)) {
          return null;
      }

       $fullpath = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
      if (!is_readable($fullpath)) {
          return null;
      }

       $binary = file_get_contents($fullpath);
      if ($binary === false) {
          return null;
      }

       $mime = $document->fields['mime'] ?: 'image/png';
       return 'data:' . $mime . ';base64,' . base64_encode($binary);
   }

    /**
     * Les 3 vues de reference sont TOUJOURS incluses dans le PDF des que le
     * reglage est actif — meme sans aucun repere encore depose (comme un
     * schema d'etat des lieux de location de vehicule, presente vierge par
     * defaut) — pour que le rendu reel corresponde exactement a l'apercu, qui
     * montre systematiquement les 3 vues. $markers est deja recupere par
     * l'appelant (DamageMarker::getForRemise()/getForMaintenance() selon le
     * type de fiche) : cette methode ne connait donc pas d'ou viennent les
     * reperes, seulement comment les repartir par vue et les mettre en forme
     * pour le gabarit PDF.
     *
     * @param array $markers Reperes deja recuperes (chaque ligne porte view_index).
     * @return array<int, array{label: string, image_data_uri: string, markers: array}>
     */
   protected function getDamageViewsForPdf(array $markers): array {
       $byView = [];
      foreach ($markers as $marker) {
          $byView[(int) $marker['view_index']][] = $marker;
      }

       $labels = DamageMarker::getCanonicalViewLabels();
       $filenames = DamageMarker::getViewImageFilenames();

       $views = [];
      foreach ($filenames as $viewIndex => $filename) {
          $dataUri = $this->getDamageViewDataUri($filename);
         if ($dataUri === null) {
             continue;
         }
          $views[] = [
              'label'                => $labels[$viewIndex] ?? '',
              'image_data_uri'       => $dataUri,
              'markers'              => $byView[$viewIndex] ?? [],
              'aspect_ratio_percent' => $this->getDamageViewAspectRatioPercent($filename),
          ];
      }
       return $views;
   }

    /**
     * Ratio hauteur/largeur (en %) de la vue de reference, utilise par les
     * gabarits PDF pour donner a .damage-pdf-view une hauteur EXPLICITE
     * (technique CSS "boite a ratio d'aspect" : height:0 + padding-bottom en %
     * de la LARGEUR, seule base que Dompdf resout correctement ici). Sans ca,
     * .damage-pdf-view n'a qu'un position:relative et une hauteur "auto" (celle
     * de l'image) — Dompdf ne parvient alors pas a etablir une base fiable pour
     * les top/left en % des reperes (.damage-pdf-marker, eux aussi en position
     * absolute) : un repere a top:5% s'affiche correctement pres du haut, mais
     * un repere a top:85% atterrit tres loin en bas de la PAGE entiere, bien
     * au-dela de la petite image — constate en conditions reelles en comparant
     * plusieurs reperes a des hauteurs croissantes sur la meme vue, avec des PDF
     * reellement generes (pas une simple lecture du gabarit).
     */
   protected function getDamageViewAspectRatioPercent(string $filename): float {
       $pluginRoot = dirname(__DIR__, 4);
       $fullpath = $pluginRoot . '/public/images/damage-views/' . $filename;

       $size = @getimagesize($fullpath);
      if ($size === false || (int) $size[0] <= 0) {
          return 75.0; // repli (ratio 4:3) si l'image est illisible
      }

       return ((int) $size[1] / (int) $size[0]) * 100;
   }

   protected function getDamageViewDataUri(string $filename): ?string {
      if ($filename === '') {
          return null;
      }

       // Racine du plugin : Pdf/ -> Remise/ -> GlpiPlugin/ -> src/ -> remise/
       $pluginRoot = dirname(__DIR__, 4);
       $fullpath = $pluginRoot . '/public/images/damage-views/' . $filename;
      if (!is_readable($fullpath)) {
          return null;
      }

       $binary = file_get_contents($fullpath);
      if ($binary === false) {
          return null;
      }

       $mime = match (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
           'jpg', 'jpeg' => 'image/jpeg',
           'png'         => 'image/png',
           'svg'         => 'image/svg+xml',
           default       => 'image/png',
       };

       return 'data:' . $mime . ';base64,' . base64_encode($binary);
   }
}
