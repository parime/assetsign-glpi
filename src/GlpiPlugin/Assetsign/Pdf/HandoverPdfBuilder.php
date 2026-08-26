<?php

namespace GlpiPlugin\Assetsign\Pdf;

use Document;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\DamageMarker;
use GlpiPlugin\Assetsign\DestructionDetails;
use GlpiPlugin\Assetsign\DonDetails;
use GlpiPlugin\Assetsign\QrCode;
use GlpiPlugin\Assetsign\Template;
use GlpiPlugin\Assetsign\VenteDetails;

/**
 * Construit le PDF non signe de la fiche de remise a partir d'un gabarit Twig,
 * et l'enregistre immediatement comme Document GLPI (visible mais provisoire :
 * il sera remplace par la version signee et horodatee apres passage par
 * SignatureStamper).
 */
final class HandoverPdfBuilder
{
   use PdfRenderingHelpers;

   public function build(Assetsign $assetsign): Document {
       $html = $this->renderHtml($assetsign);
       $protect = (bool) Config::getForEntity((int) $assetsign->fields['entities_id'])->fields['protect_pdf'];
       $binary = $this->renderPdf($html, $protect);

       return $this->storeAsDocument($assetsign, $binary, 'fiche-attribution-' . $assetsign->getID() . '.pdf');
   }

    /**
     * @param array $extra Variables additionnelles (ex: signature_image, signed_at)
     *                      fusionnees dans le contexte Twig — utilise par
     *                      SignatureStamper pour re-rendre le meme gabarit avec la
     *                      signature incrustee, plutot que de superposer des calques
     *                      sur un PDF deja rendu (plus simple, plus robuste).
     */
   public function renderHtml(Assetsign $assetsign, array $extra = []): string {
       $template = $assetsign->getTemplate();
       $headings = Assetsign::getPdfHeadings((int) $assetsign->fields['type']);
       $config = Config::getForEntity((int) $assetsign->fields['entities_id']);

       $venteDetails = ((int) $assetsign->fields['type'] === Assetsign::TYPE_VENTE)
           ? VenteDetails::getForAssetsign($assetsign->getID())
           : null;
       $donDetails = ((int) $assetsign->fields['type'] === Assetsign::TYPE_DON)
           ? DonDetails::getForAssetsign($assetsign->getID())
           : null;
       $destructionDetails = ((int) $assetsign->fields['type'] === Assetsign::TYPE_DESTRUCTION)
           ? DestructionDetails::getForAssetsign($assetsign->getID())
           : null;

       $user = $assetsign->getBeneficiary();
       $item = $assetsign->getTargetItem();
       $technician = new \User();
       $technician->getFromDB((int) $assetsign->fields['users_id_tech']);

       $placeholders = [
           'beneficiaire' => trim(\formatUserName(0, $user['name'] ?? '', $user['realname'] ?? '', $user['firstname'] ?? '')),
           'technicien'   => trim(\formatUserName(0, $technician->fields['name'] ?? '', $technician->fields['realname'] ?? '', $technician->fields['firstname'] ?? '')),
           'materiel'     => $item['name'] ?? '',
           'date'         => $assetsign->fields['date_creation'] ? date('d/m/Y', strtotime($assetsign->fields['date_creation'])) : date('d/m/Y'),
           'entite'       => \Dropdown::getDropdownName('glpi_entities', (int) $assetsign->fields['entities_id']),
       ];

       return TemplateRenderer::getInstance()->render('@assetsign/pdf/handover.html.twig', array_merge([
           'assetsign'              => $assetsign->fields,
           'user'                => $user,
           'beneficiary_is_external' => (int) ($assetsign->fields['beneficiary_type'] ?? Assetsign::BENEFICIARY_INTERNAL) === Assetsign::BENEFICIARY_EXTERNAL,
           'item'                => $item,
           'itemtype'            => Assetsign::getCanonicalItemtypeLabel($assetsign->fields['itemtype']),
           'accessories'         => $assetsign->getAccessories(),
           'contract'            => $this->resolvePlaceholders($template?->fields['content'] ?? '', $placeholders),
           'include_content'     => (bool) ($template?->fields['include_content'] ?? true),
           'charter'             => $this->resolvePlaceholders($template?->fields['charter_content'] ?? '', $placeholders),
           'include_charter'     => (bool) ($template?->fields['include_charter'] ?? true),
           'charter_url'         => $config->fields['charter_url'] ?: null,
           'enable_observations' => (bool) $config->fields['enable_observations'],
           'observations'        => $assetsign->fields['observations'] ?? '',
           'beneficiary_comment' => $assetsign->fields['beneficiary_comment'] ?? '',
           'vente_price'         => $venteDetails?->fields['price'] ?? null,
           'vente_sale_date'     => $venteDetails?->fields['sale_date']
               ? date('d/m/Y', strtotime($venteDetails->fields['sale_date']))
               : null,
           'don_organization_name'        => $donDetails?->fields['organization_name'] ?: null,
           'destruction_provider_name'    => $destructionDetails?->fields['provider_name'] ?: null,
           'currency_symbol'     => $config->fields['currency_symbol'] ?: '€',
           'damage_views'        => (bool) $config->fields['enable_damage_annotation']
               ? $this->getDamageViewsForPdf(DamageMarker::getForAssetsign($assetsign->getID()))
               : [],
           'page_title'          => $headings['page_title'],
           'material_heading'    => $headings['material_heading'],
           'document_title'      => $assetsign->getDocumentTitle(),
           'logo_data_uri'       => $this->getLogoDataUri((int) $assetsign->fields['entities_id']),
           'company_name'        => $config->fields['company_name'] ?: null,
           'qr_data_uri'         => (bool) $config->fields['show_qr_code']
               ? QrCode::toDataUri(rtrim((string) ($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . '/plugins/assetsign/front/assetsign.form.php?id=' . $assetsign->getID())
               : null,
       ], $extra));
   }

    /**
     * Rendu du meme gabarit qu'un vrai PDF, mais avec des donnees fictives —
     * utilise par la page de configuration ET par la page de gabarit pour
     * afficher un aperçu en direct sans qu'aucune remise reelle n'existe.
     * Reutilise handover.html.twig tel quel : l'aperçu est donc visuellement
     * strictement identique a ce que produira un vrai PDF.
     *
     * @param int $type Quel type de fiche previsualiser (Assetsign, Restitution,
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
   public function renderPreview(int $entities_id, int $type = Assetsign::TYPE_HANDOVER, array $overrides = []): string {
       $template = Template::getDefaultFor($type, $entities_id);
       $headings = Assetsign::getPdfHeadings($type);
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
       $companyName = array_key_exists('company_name', $overrides)
           ? (trim((string) $overrides['company_name']) ?: null)
           : ($config->fields['company_name'] ?: null);
       $observationsEnabled = array_key_exists('enable_observations', $overrides)
           ? (bool) $overrides['enable_observations']
           : (bool) $config->fields['enable_observations'];
       $damageEnabled = array_key_exists('enable_damage_annotation', $overrides)
           ? (bool) $overrides['enable_damage_annotation']
           : (bool) $config->fields['enable_damage_annotation'];
       $qrEnabled = array_key_exists('show_qr_code', $overrides)
           ? (bool) $overrides['show_qr_code']
           : (bool) $config->fields['show_qr_code'];
       $currencySymbol = array_key_exists('currency_symbol', $overrides)
           ? ((string) $overrides['currency_symbol'] ?: '€')
           : ($config->fields['currency_symbol'] ?: '€');
       $watermarkText = array_key_exists('preview_watermark_text', $overrides)
           ? ((string) $overrides['preview_watermark_text'] ?: 'APERÇU')
           : ($config->fields['preview_watermark_text'] ?: 'APERÇU');
       $watermarkOpacity = max(5, min(100, (int) (array_key_exists('preview_watermark_opacity', $overrides)
           ? $overrides['preview_watermark_opacity']
           : $config->fields['preview_watermark_opacity'])));

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

       return TemplateRenderer::getInstance()->render('@assetsign/pdf/handover.html.twig', [
           'assetsign'              => ['id' => 0, 'date_creation' => date('Y-m-d H:i:s')],
           'user'                => ['firstname' => 'Alex', 'realname' => 'Dupont', 'name' => 'adupont', 'email' => 'alex.dupont@exemple.fr'],
           'item'                => ['name' => 'PC-EXEMPLE-001', 'serial' => 'SN-EXEMPLE-042', 'otherserial' => 'INV-1234', 'manufacturer_name' => 'Dell', 'model_name' => 'Latitude 5440'],
           'itemtype'            => Assetsign::getCanonicalItemtypeLabel('Computer'),
           'accessories'         => [],
           'contract'            => $this->resolvePlaceholders(array_key_exists('content', $overrides) ? (string) $overrides['content'] : ($template?->fields['content'] ?? ''), $placeholders),
           'include_content'     => $includeContent,
           'charter'             => $this->resolvePlaceholders(array_key_exists('charter_content', $overrides) ? (string) $overrides['charter_content'] : ($template?->fields['charter_content'] ?? ''), $placeholders),
           'include_charter'     => $includeCharter,
           'charter_url'         => $charterUrl,
           'enable_observations' => $observationsEnabled,
           'observations'        => $observationsEnabled ? __('Exemple : petite rayure sur le capot, sans gravité.', 'assetsign') : '',
           'vente_price'         => $type === Assetsign::TYPE_VENTE ? 199.00 : null,
           'vente_sale_date'     => $type === Assetsign::TYPE_VENTE ? date('d/m/Y') : null,
           'don_organization_name'     => $type === Assetsign::TYPE_DON ? 'Association Locale' : null,
           'destruction_provider_name' => $type === Assetsign::TYPE_DESTRUCTION ? 'Prestataire Exemple SAS' : null,
           'damage_views'        => $damageEnabled ? $this->getSampleDamageViews() : [],
           'page_title'          => $headings['page_title'],
           'material_heading'    => $headings['material_heading'],
           'document_title'      => 'Aperçu',
           'logo_data_uri'       => $this->getLogoDataUri($entities_id),
           'company_name'        => $companyName,
           'currency_symbol'     => $currencySymbol,
           'qr_data_uri'         => $qrEnabled
               ? QrCode::toDataUri(rtrim((string) ($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . '/plugins/assetsign/front/assetsign.form.php?id=0')
               : null,
           // Uniquement dans renderPreview(), JAMAIS dans renderHtml() (vrai PDF) :
           // c'est ce qui garantit qu'un vrai document genere ne peut pas se
           // retrouver filigrane par erreur — cf. handover.html.twig, qui
           // conditionne l'affichage a la simple presence de cette variable.
           'watermark_text'      => $watermarkText,
           'watermark_opacity'   => $watermarkOpacity,
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

   private function storeAsDocument(Assetsign $assetsign, string $pdfBinary, string $filename): Document {
       // Document::moveDocument() (appelee par add() via l'entree "_filename")
       // lit obligatoirement le fichier source dans GLPI_TMP_DIR : c'est la
       // convention native pour attacher un fichier genere par du code, par
       // opposition a "upload_file" reserve aux uploads HTTP directs.
       $tmpPath = GLPI_TMP_DIR . '/' . uniqid('assetsign_', true) . '.pdf';
       file_put_contents($tmpPath, $pdfBinary);

       $document = new Document();
       $documents_id = $document->add([
           'name'          => $assetsign->getDocumentTitle() . ' [non signée]',
           'entities_id'   => $assetsign->fields['entities_id'],
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);

       $document->getFromDB($documents_id);
       return $document;
   }
}
