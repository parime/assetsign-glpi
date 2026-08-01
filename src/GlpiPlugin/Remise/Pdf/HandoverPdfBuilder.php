<?php

namespace GlpiPlugin\Remise\Pdf;

use Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\DamageMarker;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Template;
use GlpiPlugin\Remise\VenteDetails;

/**
 * Construit le PDF non signe de la fiche de remise a partir d'un gabarit Twig,
 * et l'enregistre immediatement comme Document GLPI (visible mais provisoire :
 * il sera remplace par la version signee et horodatee apres passage par
 * SignatureStamper).
 */
final class HandoverPdfBuilder
{
   public function build(Remise $remise): Document {
       $html = $this->renderHtml($remise);
       $binary = $this->renderPdf($html);

       return $this->storeAsDocument($remise, $binary, 'fiche-remise-' . $remise->getID() . '.pdf');
   }

    /**
     * @param array $extra Variables additionnelles (ex: signature_image, signed_at)
     *                      fusionnees dans le contexte Twig — utilise par
     *                      SignatureStamper pour re-rendre le meme gabarit avec la
     *                      signature incrustee, plutot que de superposer des calques
     *                      sur un PDF deja rendu (plus simple, plus robuste).
     */
   public function renderHtml(Remise $remise, array $extra = []): string {
       $template = $remise->getTemplate();
       $headings = Remise::getPdfHeadings((int) $remise->fields['type']);
       $config = Config::getForEntity((int) $remise->fields['entities_id']);

       $venteDetails = ((int) $remise->fields['type'] === Remise::TYPE_VENTE)
           ? VenteDetails::getForRemise($remise->getID())
           : null;

       return TemplateRenderer::getInstance()->render('@remise/pdf/handover.html.twig', array_merge([
           'remise'              => $remise->fields,
           'user'                => $remise->getBeneficiary(),
           'beneficiary_is_external' => (int) ($remise->fields['beneficiary_type'] ?? Remise::BENEFICIARY_INTERNAL) === Remise::BENEFICIARY_EXTERNAL,
           'item'                => $remise->getTargetItem(),
           'itemtype'            => Remise::getCanonicalItemtypeLabel($remise->fields['itemtype']),
           'accessories'         => $remise->getAccessories(),
           'contract'            => $template?->fields['content'] ?? '',
           'include_content'     => (bool) ($template?->fields['include_content'] ?? true),
           'charter'             => $template?->fields['charter_content'] ?? '',
           'include_charter'     => (bool) ($template?->fields['include_charter'] ?? true),
           'charter_url'         => $config->fields['charter_url'] ?: null,
           'enable_observations' => (bool) $config->fields['enable_observations'],
           'observations'        => $remise->fields['observations'] ?? '',
           'beneficiary_comment' => $remise->fields['beneficiary_comment'] ?? '',
           'vente_price'         => $venteDetails?->fields['price'] ?? null,
           'vente_sale_date'     => $venteDetails?->fields['sale_date'] ?? null,
           'damage_views'        => (bool) $config->fields['enable_damage_annotation']
               ? $this->getDamageViewsForPdf($remise->getID())
               : [],
           'page_title'          => $headings['page_title'],
           'material_heading'    => $headings['material_heading'],
           'document_title'      => $remise->getDocumentTitle(),
           'logo_data_uri'       => $this->getLogoDataUri((int) $remise->fields['entities_id']),
       ], $extra));
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
     * @return array<int, array{label: string, image_data_uri: string, markers: array}>
     */
   private function getDamageViewsForPdf(int $remises_id): array {
       $byView = [];
      foreach (DamageMarker::getForRemise($remises_id) as $marker) {
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
     * Ratio hauteur/largeur (en %) de la vue de reference, utilise par
     * handover.html.twig pour donner a .damage-pdf-view une hauteur EXPLICITE
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

    /**
     * Rendu du meme gabarit qu'un vrai PDF, mais avec des donnees fictives —
     * utilise par la page de configuration ET par la page de gabarit pour
     * afficher un aperçu en direct sans qu'aucune remise reelle n'existe.
     * Reutilise handover.html.twig tel quel : l'aperçu est donc visuellement
     * strictement identique a ce que produira un vrai PDF.
     *
     * @param int $type Quel type de fiche previsualiser (Remise, Restitution,
     *                   Don, Vente...) — chaque onglet de configuration montre
     *                   l'apercu de SON propre type.
     * @param array $overrides Valeurs pas encore enregistrees (en cours de
     *                          saisie dans un formulaire), qui remplacent
     *                          ponctuellement ce qui serait lu en base :
     *                          'content', 'include_content', 'charter_content',
     *                          'include_charter' (page de gabarit) ;
     *                          'charter_url', 'enable_observations',
     *                          'enable_damage_annotation' (page de configuration).
     *                          Sans override, le comportement est identique a
     *                          l'ancien renderPreview() (lit le vrai gabarit
     *                          par defaut + la vraie configuration).
     */
   public function renderPreview(int $entities_id, int $type = Remise::TYPE_HANDOVER, array $overrides = []): string {
       $template = Template::getDefaultFor($type, $entities_id);
       $headings = Remise::getPdfHeadings($type);
       $config = Config::getForEntity($entities_id);

       $includeContent = array_key_exists('include_content', $overrides)
           ? (bool) $overrides['include_content']
           : (bool) ($template?->fields['include_content'] ?? true);
       $includeCharter = array_key_exists('include_charter', $overrides)
           ? (bool) $overrides['include_charter']
           : (bool) ($template?->fields['include_charter'] ?? true);
       $charterUrl = array_key_exists('charter_url', $overrides)
           ? (trim((string) $overrides['charter_url']) ?: null)
           : ($config->fields['charter_url'] ?: null);
       $observationsEnabled = array_key_exists('enable_observations', $overrides)
           ? (bool) $overrides['enable_observations']
           : (bool) $config->fields['enable_observations'];
       $damageEnabled = array_key_exists('enable_damage_annotation', $overrides)
           ? (bool) $overrides['enable_damage_annotation']
           : (bool) $config->fields['enable_damage_annotation'];

       return TemplateRenderer::getInstance()->render('@remise/pdf/handover.html.twig', [
           'remise'              => ['id' => 0, 'date_creation' => date('Y-m-d H:i:s')],
           'user'                => ['firstname' => 'Alex', 'realname' => 'Dupont', 'name' => 'adupont', 'email' => 'alex.dupont@exemple.fr'],
           'item'                => ['name' => 'PC-EXEMPLE-001', 'serial' => 'SN-EXEMPLE-042', 'otherserial' => 'INV-1234', 'manufacturer_name' => 'Dell', 'model_name' => 'Latitude 5440'],
           'itemtype'            => Remise::getCanonicalItemtypeLabel('Computer'),
           'accessories'         => [],
           'contract'            => array_key_exists('content', $overrides) ? (string) $overrides['content'] : ($template?->fields['content'] ?? ''),
           'include_content'     => $includeContent,
           'charter'             => array_key_exists('charter_content', $overrides) ? (string) $overrides['charter_content'] : ($template?->fields['charter_content'] ?? ''),
           'include_charter'     => $includeCharter,
           'charter_url'         => $charterUrl,
           'enable_observations' => $observationsEnabled,
           'observations'        => $observationsEnabled ? __('Exemple : petite rayure sur le capot, sans gravité.', 'remise') : '',
           'vente_price'         => $type === Remise::TYPE_VENTE ? 199.00 : null,
           'vente_sale_date'     => $type === Remise::TYPE_VENTE ? date('Y-m-d') : null,
           'damage_views'        => $damageEnabled ? $this->getSampleDamageViews() : [],
           'page_title'          => $headings['page_title'],
           'material_heading'    => $headings['material_heading'],
           'document_title'      => 'Aperçu',
           'logo_data_uri'       => $this->getLogoDataUri($entities_id),
       ]);
   }

    /**
     * Les 3 vues de reference avec un repere d'exemple chacune, pour que
     * l'apercu montre a quoi ressemble la section "Etat des lieux visuel"
     * au complet (cote a cote, comme le vrai PDF) une fois le reglage active
     * — mêmes donnees fictives que le reste de renderPreview().
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

   private function storeAsDocument(Remise $remise, string $pdfBinary, string $filename): Document {
       // Document::moveDocument() (appelee par add() via l'entree "_filename")
       // lit obligatoirement le fichier source dans GLPI_TMP_DIR : c'est la
       // convention native pour attacher un fichier genere par du code, par
       // opposition a "upload_file" reserve aux uploads HTTP directs.
       $tmpPath = GLPI_TMP_DIR . '/' . uniqid('remise_', true) . '.pdf';
       file_put_contents($tmpPath, $pdfBinary);

       $document = new Document();
       $documents_id = $document->add([
           'name'          => $remise->getDocumentTitle() . ' [non signée]',
           'entities_id'   => $remise->fields['entities_id'],
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);

       $document->getFromDB($documents_id);
       return $document;
   }
}
