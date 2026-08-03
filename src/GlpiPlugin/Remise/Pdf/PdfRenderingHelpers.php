<?php

namespace GlpiPlugin\Remise\Pdf;

use Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\DamageMarker;

/**
 * Logique de rendu PDF commune a HandoverPdfBuilder (Remise) et
 * MaintenancePdfBuilder (Maintenance) : ni l'une ni l'autre n'est specifique
 * a un type de fiche parente en particulier (logo par entite, moteur Dompdf,
 * vues de reference de l'etat des lieux visuel) — seule la RECUPERATION des
 * marqueurs differe (DamageMarker::getForRemise() vs getForMaintenance()),
 * deja geree par l'appelant avant d'invoquer getDamageViewsForPdf().
 */
trait PdfRenderingHelpers
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
   private function getLogoDataUri(int $entities_id): ?string {
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
     * montre systematiquement les 3 vues. Chaque vue porte les repres reels
     * qui lui sont propres (tableau vide si aucun). Image encodee en data
     * URI, meme raison que le logo (cf. getLogoDataUri()) : ces images vivent
     * dans public/images/ du plugin, hors de GLPI_DOC_DIR sur lequel Dompdf
     * est chroote.
     *
     * @param array<int, array<string, mixed>> $markers Deja recupere par
     *        l'appelant (DamageMarker::getForRemise()/getForMaintenance()) -
     *        cette methode ne sait pas d'ou ils viennent, seulement les mettre
     *        en forme pour le gabarit.
     * @return array<int, array{label: string, image_data_uri: string, markers: array}>
     */
   private function getDamageViewsForPdf(array $markers): array {
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
     * Les 3 vues de reference avec un repere d'exemple chacune, pour que
     * l'apercu montre a quoi ressemble la section "Etat des lieux visuel"
     * au complet (cote a cote, comme le vrai PDF) une fois le reglage active.
     *
     * @return array<int, array{label: string, image_data_uri: string, markers: array}>
     */
   private function getSampleDamageViews(): array {
       $labels = DamageMarker::getCanonicalViewLabels();
       $filenames = DamageMarker::getViewImageFilenames();
       $sampleMarkers = [
           ['x_percent' => 60, 'y_percent' => 40, 'severity' => DamageMarker::SEVERITY_MINOR, 'description' => __('Exemple : petite rayure sur le capot, sans gravité.', 'remise')],
           ['x_percent' => 35, 'y_percent' => 55, 'severity' => DamageMarker::SEVERITY_MAJOR, 'description' => __('Exemple : touche cassée.', 'remise')],
           ['x_percent' => 50, 'y_percent' => 30, 'severity' => DamageMarker::SEVERITY_MINOR, 'description' => __('Exemple : pied manquant.', 'remise')],
       ];

       $views = [];
       foreach ($filenames as $index => $filename) {
           $dataUri = $this->getDamageViewDataUri($filename);
          if ($dataUri === null) {
              continue;
          }
           $views[] = [
               'label'                => $labels[$index] ?? '',
               'image_data_uri'       => $dataUri,
               'markers'              => [$sampleMarkers[$index] ?? $sampleMarkers[0]],
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
   private function getDamageViewAspectRatioPercent(string $filename): float {
       $pluginRoot = dirname(__DIR__, 4);
       $fullpath = $pluginRoot . '/public/images/damage-views/' . $filename;

       $size = @getimagesize($fullpath);
      if ($size === false || (int) $size[0] <= 0) {
          return 75.0; // repli (ratio 4:3) si l'image est illisible
      }

       return ((int) $size[1] / (int) $size[0]) * 100;
   }

   private function getDamageViewDataUri(string $filename): ?string {
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
