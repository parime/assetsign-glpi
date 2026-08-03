<?php

namespace GlpiPlugin\Remise\Pdf;

use Document;
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
   use PdfRenderingHelpers;

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

       $user = $remise->getBeneficiary();
       $item = $remise->getTargetItem();
       $technician = new \User();
       $technician->getFromDB((int) $remise->fields['users_id_tech']);

       $placeholders = [
           'beneficiaire' => trim(($user['firstname'] ?? '') . ' ' . ($user['realname'] ?? '')),
           'technicien'   => trim(($technician->fields['firstname'] ?? '') . ' ' . ($technician->fields['realname'] ?? '')),
           'materiel'     => $item['name'] ?? '',
           'date'         => $remise->fields['date_creation'] ? date('d/m/Y', strtotime($remise->fields['date_creation'])) : date('d/m/Y'),
           'entite'       => \Dropdown::getDropdownName('glpi_entities', (int) $remise->fields['entities_id']),
       ];

       return TemplateRenderer::getInstance()->render('@remise/pdf/handover.html.twig', array_merge([
           'remise'              => $remise->fields,
           'user'                => $user,
           'beneficiary_is_external' => (int) ($remise->fields['beneficiary_type'] ?? Remise::BENEFICIARY_INTERNAL) === Remise::BENEFICIARY_EXTERNAL,
           'item'                => $item,
           'itemtype'            => Remise::getCanonicalItemtypeLabel($remise->fields['itemtype']),
           'accessories'         => $remise->getAccessories(),
           'contract'            => $this->resolvePlaceholders($template?->fields['content'] ?? '', $placeholders),
           'include_content'     => (bool) ($template?->fields['include_content'] ?? true),
           'charter'             => $this->resolvePlaceholders($template?->fields['charter_content'] ?? '', $placeholders),
           'include_charter'     => (bool) ($template?->fields['include_charter'] ?? true),
           'charter_url'         => $config->fields['charter_url'] ?: null,
           'enable_observations' => (bool) $config->fields['enable_observations'],
           'observations'        => $remise->fields['observations'] ?? '',
           'beneficiary_comment' => $remise->fields['beneficiary_comment'] ?? '',
           'vente_price'         => $venteDetails?->fields['price'] ?? null,
           'vente_sale_date'     => $venteDetails?->fields['sale_date'] ?? null,
           'damage_views'        => (bool) $config->fields['enable_damage_annotation']
               ? $this->getDamageViewsForPdf(DamageMarker::getForRemise($remise->getID()))
               : [],
           'page_title'          => $headings['page_title'],
           'material_heading'    => $headings['material_heading'],
           'document_title'      => $remise->getDocumentTitle(),
           'logo_data_uri'       => $this->getLogoDataUri((int) $remise->fields['entities_id']),
       ], $extra));
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

       // Donnees fictives (cf. commentaire de methode) : le nom d'entite est,
       // lui, reel (utile pour verifier que {entite} se resout correctement
       // sans avoir a enregistrer quoi que ce soit).
       $placeholders = [
           'beneficiaire' => 'Alex Dupont',
           'technicien'   => 'Sophie Martin',
           'materiel'     => 'PC-EXEMPLE-001',
           'date'         => date('d/m/Y'),
           'entite'       => \Dropdown::getDropdownName('glpi_entities', $entities_id),
       ];

       return TemplateRenderer::getInstance()->render('@remise/pdf/handover.html.twig', [
           'remise'              => ['id' => 0, 'date_creation' => date('Y-m-d H:i:s')],
           'user'                => ['firstname' => 'Alex', 'realname' => 'Dupont', 'name' => 'adupont', 'email' => 'alex.dupont@exemple.fr'],
           'item'                => ['name' => 'PC-EXEMPLE-001', 'serial' => 'SN-EXEMPLE-042', 'otherserial' => 'INV-1234', 'manufacturer_name' => 'Dell', 'model_name' => 'Latitude 5440'],
           'itemtype'            => Remise::getCanonicalItemtypeLabel('Computer'),
           'accessories'         => [],
           'contract'            => $this->resolvePlaceholders(array_key_exists('content', $overrides) ? (string) $overrides['content'] : ($template?->fields['content'] ?? ''), $placeholders),
           'include_content'     => $includeContent,
           'charter'             => $this->resolvePlaceholders(array_key_exists('charter_content', $overrides) ? (string) $overrides['charter_content'] : ($template?->fields['charter_content'] ?? ''), $placeholders),
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
     * Remplace {beneficiaire}/{technicien}/{materiel}/{date}/{entite} dans le
     * contrat/la charte (texte libre saisi par l'administrateur dans le
     * gabarit, cf. Template::class) — permet de rediger un texte generique
     * une seule fois plutot que de dupliquer un gabarit par variante. Simple
     * str_replace sur le HTML brut (contenu riche TinyMCE) : la syntaxe
     * {xxx} n'entre jamais en collision avec du HTML/texte normal.
     * @param array<string, string> $values
     */
   private function resolvePlaceholders(string $text, array $values): string {
      if ($text === '' || !str_contains($text, '{')) {
          return $text;
      }
       $search = [];
       $replace = [];
      foreach ($values as $key => $value) {
          $search[] = '{' . $key . '}';
          $replace[] = $value;
      }
       return str_replace($search, $replace, $text);
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
