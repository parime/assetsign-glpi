<?php

namespace GlpiPlugin\Assetsign\Pdf;

use Document;
use Glpi\Application\View\TemplateRenderer;
use Glpi\RichText\RichText;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\DamageMarker;
use GlpiPlugin\Assetsign\DestructionDetails;
use GlpiPlugin\Assetsign\DonDetails;
use GlpiPlugin\Assetsign\FieldsPluginLogic;
use GlpiPlugin\Assetsign\FieldsPluginValues;
use GlpiPlugin\Assetsign\PaperSignature;
use GlpiPlugin\Assetsign\QrCode;
use GlpiPlugin\Assetsign\Template;
use GlpiPlugin\Assetsign\VenteDetails;
use GlpiPlugin\Assetsign\WipeAttestation;
use GlpiPlugin\Assetsign\WipeRequirement;

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
       $binary = $this->renderPdf($html, $protect, $this->metadataFor($assetsign));

       return $this->storeAsDocument($assetsign, $binary, 'fiche-attribution-' . $assetsign->getID() . '.pdf');
   }

    /**
     * @param array $extra Variables additionnelles (ex: signature_image, signed_at)
     *                      fusionnees dans le contexte Twig : utilise par
     *                      SignatureStamper pour re-rendre le meme gabarit avec la
     *                      signature incrustee, plutot que de superposer des calques
     *                      sur un PDF deja rendu (plus simple, plus robuste).
     */
   public function renderHtml(Assetsign $assetsign, array $extra = []): string {
       // Issue #208 : toujours dans la langue du beneficiaire (externe : langue par defaut), que
       // le rendu soit declenche par le technicien, le beneficiaire, un delegue ou le responsable.
       return PdfLanguage::run(
           PdfLanguage::forUser((int) ($assetsign->getBeneficiary()['id'] ?? 0)),
           fn (): string => $this->renderLocalizedHtml($assetsign, $extra)
       );
   }

    /**
     * Issue #276 : PDF de la version papier (cf. PaperSignature) : jamais enregistre comme
     * Document, rien n'y est signe. Meme protection que le PDF de la fiche.
     */
   public function renderPaperPdf(Assetsign $assetsign, string $variant): string {
       $protect = (bool) Config::getForEntity((int) $assetsign->fields['entities_id'])->fields['protect_pdf'];
       return $this->renderPdf($this->renderPaperHtml($assetsign, $variant), $protect, $this->metadataFor($assetsign));
   }

    /**
     * Issue #276 : HTML de la version papier, dans la langue du beneficiaire comme renderHtml().
     * VARIANT_FORM : la fiche pre-remplie, cadres de signature vides, puis l'etat des lieux ;
     * VARIANT_CONDITION : l'etat des lieux seul (une page A4).
     */
   public function renderPaperHtml(Assetsign $assetsign, string $variant): string {
       return PdfLanguage::run(
           PdfLanguage::forUser((int) ($assetsign->getBeneficiary()['id'] ?? 0)),
           fn (): string => $this->renderLocalizedHtml(
               $assetsign,
               [
                   'paper_mode'         => true,
                   'paper_signers'      => $this->getPaperSigners($assetsign),
                   'paper_condition'    => $this->getPaperCondition($assetsign),
                   'paper_generated_at' => date('Y-m-d H:i:s'),
               ],
               $variant === PaperSignature::VARIANT_CONDITION
                   ? '@assetsign/pdf/paper_condition_report.html.twig'
                   : '@assetsign/pdf/handover.html.twig'
           )
       );
   }

    /**
     * Issue #276 : un cadre de signature par signataire de la fiche : beneficiaire (ou son
     * delegue), responsable qui contresigne s'il y en a un, technicien de la fiche. Le role est
     * traduit par le gabarit (langue du PDF).
     *
     * @return list<array{role: string, name: string, title: string, on_behalf: string}>
     */
   private function getPaperSigners(Assetsign $assetsign): array {
       $beneficiary = $assetsign->getBeneficiary();
       $beneficiaryName = PdfMetadata::personName($beneficiary);
       $signers = [];
       $delegate = $assetsign->getDelegate();
      if ($delegate !== null) {
          $signers[] = ['role' => 'delegate', 'name' => PdfMetadata::personName($delegate), 'title' => PdfMetadata::signerTitle($delegate), 'on_behalf' => $beneficiaryName];
      } else {
          $signers[] = ['role' => 'beneficiary', 'name' => $beneficiaryName, 'title' => PdfMetadata::signerTitle($beneficiary), 'on_behalf' => ''];
      }
       $cosigner = $assetsign->getCosigner();
      if ($cosigner !== null) {
          $signers[] = ['role' => 'cosigner', 'name' => PdfMetadata::personName($cosigner), 'title' => PdfMetadata::signerTitle($cosigner), 'on_behalf' => ''];
      }
       $technician = new \User();
      if ((int) $assetsign->fields['users_id_tech'] > 0 && $technician->getFromDB((int) $assetsign->fields['users_id_tech'])) {
          $signers[] = ['role' => 'technician', 'name' => PdfMetadata::personName($technician->fields), 'title' => PdfMetadata::signerTitle($technician->fields), 'on_behalf' => ''];
      }
       return $signers;
   }

    /**
     * Issue #276 : etat des lieux imprimable : une ligne par element remis (materiel, objets du
     * parc, accessoires) a cocher a la main, et les schemas de l'etat des lieux visuel (avec les
     * reperes deja poses, meme si l'etat des lieux visuel n'est pas active a l'ecran).
     *
     * @return array{rows: list<array{type: string, name: string, serial: string}>, views: array<int, array<string, mixed>>, has_markers: bool}
     */
   private function getPaperCondition(Assetsign $assetsign): array {
       $item = $assetsign->getTargetItem();
       $rows = [[
           'type'   => Assetsign::getItemtypeLabel((string) $assetsign->fields['itemtype']),
           'name'   => (string) ($item['name'] ?? ''),
           'serial' => (string) ($item['serial'] ?? ''),
       ]];
       foreach (AssetsignItem::getForAssetsign($assetsign->getID()) as $row) {
          $rows[] = ['type' => (string) ($row['type_label'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'serial' => (string) ($row['serial'] ?? '')];
       }
       foreach ($assetsign->getAccessories() as $accessory) {
          $rows[] = ['type' => __('Accessoire', 'assetsign'), 'name' => sprintf('%s x %d', $accessory['name'], (int) $accessory['quantity']), 'serial' => ''];
       }
       $markers = DamageMarker::getForAssetsign($assetsign->getID());

       return [
           'rows'        => $rows,
           'views'       => $this->getDamageViewsForPdf($markers, (string) $assetsign->fields['itemtype']),
           'has_markers' => $markers !== [],
       ];
   }

   private function renderLocalizedHtml(Assetsign $assetsign, array $extra, string $twigTemplate = '@assetsign/pdf/handover.html.twig'): string {
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

       // Issue #229 : champs du plugin Fields des blocs retenus (aucun par defaut). Echappes avant
       // d'entrer dans le texte riche du gabarit, comme toute donnee saisie hors du gabarit.
       $itemFields = FieldsPluginValues::getValues(
           (string) $assetsign->fields['itemtype'],
           (int) $assetsign->fields['items_id'],
           FieldsPluginLogic::decodeContainerIds($config->fields['fields_containers'] ?? '[]')
       );
      foreach ($itemFields as $field) {
          $placeholders[FieldsPluginLogic::placeholderKey($field['name'])] = htmlescape($field['value']);
      }

       return TemplateRenderer::getInstance()->render($twigTemplate, array_merge([
           'html_lang'           => PdfLanguage::htmlLang(),
           'assetsign'              => $assetsign->fields,
           'user'                => $user,
           'beneficiary_is_external' => (int) ($assetsign->fields['beneficiary_type'] ?? Assetsign::BENEFICIARY_INTERNAL) === Assetsign::BENEFICIARY_EXTERNAL,
           'item'                => $item,
           'itemtype'            => Assetsign::getItemtypeLabel($assetsign->fields['itemtype']),
           'accessories'         => $assetsign->getAccessories(),
           'inventoried_items'   => AssetsignItem::getForAssetsign($assetsign->getID()),
           'item_fields'         => $itemFields,
           // RichText::getSafeHtml() (pas seulement htmlspecialchars, qui casserait
           // la mise en forme voulue du gabarit) : ce contenu est affiche via
           // {{ contract_safe_html|raw }}/{{ charter_safe_html|raw }} dans le twig PDF, et provient
           // d'un champ de configuration modifiable par toute personne ayant
           // Config::$rightname ou Template::$rightname UPDATE - non purifie
           // jusqu'ici, un compte disposant de ce droit (mais pas forcement
           // d'un droit d'administration GLPI plus large) pouvait stocker du
           // JS qui s'executerait a chaque generation de PDF (rendu HTML avant
           // impression) et sur la page d'apercu (front/preview.php).
           'contract_safe_html'  => RichText::getSafeHtml($this->resolvePlaceholders($template?->fields['content'] ?? '', $placeholders)),
           'include_content'     => (bool) ($template?->fields['include_content'] ?? true),
           'charter_safe_html'   => RichText::getSafeHtml($this->resolvePlaceholders($template?->fields['charter_content'] ?? '', $placeholders)),
           'include_charter'     => (bool) ($template?->fields['include_charter'] ?? true),
           'charter_url'         => $config->fields['charter_url'] ?: null,
           'enable_observations' => (bool) $config->fields['enable_observations'],
           'observations'        => $assetsign->fields['observations'] ?? '',
           // Issue #193 : pret temporaire, date de retour prevue (format du document, comme 'date').
           'loan_until'          => !empty($assetsign->fields['loan_until']) ? date('d/m/Y', strtotime((string) $assetsign->fields['loan_until'])) : null,
           'beneficiary_comment' => $assetsign->fields['beneficiary_comment'] ?? '',
           'vente_price'         => $venteDetails?->fields['price'] ?? null,
           'vente_sale_date'     => $venteDetails?->fields['sale_date']
               ? date('d/m/Y', strtotime($venteDetails->fields['sale_date']))
               : null,
           'don_organization_name'        => $donDetails?->fields['organization_name'] ?: null,
           'destruction_provider_name'    => $destructionDetails?->fields['provider_name'] ?: null,
           // Issue #322 : effacement des donnees avant la sortie du materiel (don, vente, destruction).
           'wipe'                => $this->getWipeSection($assetsign, $config),
           'currency_symbol'     => $config->fields['currency_symbol'] ?: '€',
           'damage_views'        => (bool) $config->fields['enable_damage_annotation']
               ? $this->getDamageViewsForPdf(DamageMarker::getForAssetsign($assetsign->getID()), $assetsign->fields['itemtype'])
               : [],
           'page_title'          => $headings['page_title'],
           'material_heading'    => $headings['material_heading'],
           'document_title'      => $assetsign->getDocumentTitle(),
           'logo_data_uri'       => $this->getLogoDataUri((int) $assetsign->fields['entities_id']),
           'company_name'        => $config->fields['company_name'] ?: null,
           'qr_data_uri'         => (bool) $config->fields['show_qr_code']
               ? QrCode::toDataUri(rtrim((string) ($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . '/plugins/assetsign/front/assetsign.form.php?id=' . $assetsign->getID())
               : null,
           // Delegation de signature (issue #115) : toujours lu depuis
           // $assetsign->fields (pas seulement au moment de la signature, cf.
           // $extra ci-dessus) : un PDF non signe regenere apres delegation
           // (ex: ajout d'un accessoire) doit deja mentionner le delegue.
           'delegated_users_id' => (int) ($assetsign->fields['delegated_users_id'] ?? 0),
           'delegation_date'    => $assetsign->fields['delegation_date'] ?? null,
           'delegation_reason'  => $assetsign->fields['delegation_reason'] ?? null,
           // Qui a REELLEMENT autorise la delegation (technicien/admin ou le
           // beneficiaire lui-meme), distinct du beneficiaire d'origine
           // toujours affiche par ailleurs (cf. delegated_by_users_id,
           // Assetsign::delegateSignatureTo()) - sans ca le PDF archive
           // affirmait a tort que le beneficiaire avait toujours delegue lui-meme.
           'delegated_by_name'  => $this->resolveDelegatedByName((int) ($assetsign->fields['delegated_by_users_id'] ?? 0)),
           // Issue #274 : en-tete de maitrise documentaire (null si desactive pour l'entite).
           'doc_header'         => DocumentControlHeader::forEntity((int) $assetsign->fields['entities_id'], $template),
       ], $extra));
   }

    /**
     * Issue #322 : section « Effacement des donnees » des fiches de don, de vente et de destruction :
     * l'attestation valable si elle existe ; sinon une mention d'absence, seulement si l'entite exige
     * l'attestation pour ce type (aucun PDF ne change tant que la fonction n'est pas utilisee).
     *
     * @return array{summary: array<string, mixed>|null}|null
     */
   private function getWipeSection(Assetsign $assetsign, Config $config): ?array {
       $type = (int) $assetsign->fields['type'];
      if (!in_array($type, [Assetsign::TYPE_DON, Assetsign::TYPE_VENTE, Assetsign::TYPE_DESTRUCTION], true)) {
          return null;
      }
       $summary = WipeAttestation::summaryFor((string) $assetsign->fields['itemtype'], (int) $assetsign->fields['items_id']);
      if ($summary === null && WipeRequirement::modeForType($config, $type) === WipeRequirement::MODE_DISABLED) {
          return null;
      }
       return ['summary' => $summary];
   }

    /**
     * Issue #280 : metadonnees du PDF de la fiche. Sujet = signataire : celui qui a signe, sinon
     * celui qui est attendu (delegue ou beneficiaire, nom saisi pour un beneficiaire externe).
     *
     * @return array<string, string>
     */
   public function metadataFor(Assetsign $assetsign, ?string $signerName = null): array {
       $signerName ??= PdfMetadata::personName($assetsign->getExpectedSigner());
       return PdfMetadata::build($assetsign->getDocumentTitle(), (int) $assetsign->fields['entities_id'], $signerName);
   }

   private function resolveDelegatedByName(int $usersId): ?string {
      if ($usersId <= 0) {
          return null;
      }
       $initiator = new \User();
      if (!$initiator->getFromDB($usersId)) {
          return null;
      }
       return trim(\formatUserName(0, $initiator->fields['name'] ?? '', $initiator->fields['realname'] ?? '', $initiator->fields['firstname'] ?? ''));
   }

    /**
     * Rendu du meme gabarit qu'un vrai PDF, mais avec des donnees fictives -
     * utilise par la page de configuration ET par la page de gabarit pour
     * afficher un aperçu en direct sans qu'aucune remise reelle n'existe.
     * Reutilise handover.html.twig tel quel : l'aperçu est donc visuellement
     * strictement identique a ce que produira un vrai PDF.
     *
     * @param int $type Quel type de fiche previsualiser (Assetsign, Restitution,
     *                   Don, Vente...): chaque onglet de configuration montre
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
           ? (Config::sanitizeCharterUrl((string) $overrides['charter_url']) ?: null)
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
       // Issue #274 : en-tete de maitrise documentaire, avec les valeurs en cours de saisie
       // (configuration : activation et type ; gabarit : code et revision).
       $docHeaderConfig = $config->fields;
      foreach (['doc_header_enabled', 'doc_header_doc_type'] as $key) {
         if (array_key_exists($key, $overrides)) {
             $docHeaderConfig[$key] = $overrides[$key];
         }
      }
       $docHeaderConfig['company_name'] = $companyName ?? '';
       $docHeaderTemplate = $template->fields ?? [];
      foreach (['doc_code', 'doc_revision', 'doc_revision_date'] as $key) {
         if (array_key_exists($key, $overrides)) {
             $docHeaderTemplate[$key] = $overrides[$key];
         }
      }
       $docHeader = DocumentControlHeader::build(
           $docHeaderConfig,
           DocumentControlHeader::entityName($entities_id),
           Template::normalizeDocControlInput($docHeaderTemplate),
           true
       );
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
           'html_lang'           => PdfLanguage::htmlLang(),
           // Issue #273 : exemple de reference au format de l'entite (vide : identifiant, comme avant).
           'assetsign'              => ['id' => 0, 'date_creation' => date('Y-m-d H:i:s'), 'reference' => $config->getReferencePreview()['example']],
           'user'                => ['firstname' => 'Alex', 'realname' => 'Dupont', 'name' => 'adupont', 'email' => 'alex.dupont@exemple.fr'],
           'item'                => ['name' => 'PC-EXEMPLE-001', 'serial' => 'SN-EXEMPLE-042', 'otherserial' => 'INV-1234', 'manufacturer_name' => 'Dell', 'model_name' => 'Latitude 5440'],
           'itemtype'            => Assetsign::getItemtypeLabel('Computer'),
           'accessories'         => [],
           'inventoried_items'   => [['type_label' => 'Écran', 'name' => 'ECRAN-EXEMPLE-007', 'manufacturer' => 'Dell', 'model' => 'P2422H', 'serial' => 'SN-ECR-0815', 'otherserial' => 'INV-5678']],
           // RichText::getSafeHtml() : cf. renderHtml() ci-dessus - ce chemin est
           // meme plus expose, puisque le contenu vient ici directement du
           // POST de live-preview.js (front/preview.php), affiche en HTML brut
           // dans le navigateur AVANT tout enregistrement.
           'contract_safe_html'  => RichText::getSafeHtml($this->resolvePlaceholders(array_key_exists('content', $overrides) ? (string) $overrides['content'] : ($template?->fields['content'] ?? ''), $placeholders)),
           'include_content'     => $includeContent,
           'charter_safe_html'   => RichText::getSafeHtml($this->resolvePlaceholders(array_key_exists('charter_content', $overrides) ? (string) $overrides['charter_content'] : ($template?->fields['charter_content'] ?? ''), $placeholders)),
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
           // retrouver filigrane par erreur : cf. handover.html.twig, qui
           // conditionne l'affichage a la simple presence de cette variable.
           'watermark_text'      => $watermarkText,
           'watermark_opacity'   => $watermarkOpacity,
           'doc_header'          => $docHeader,
       ]);
   }

    /**
     * Remplace {beneficiaire}/{technicien}/{materiel}/{date}/{entite} dans le
     * contrat/la charte (texte libre saisi par l'administrateur dans le
     * gabarit, cf. Template::class) : permet de rediger un texte generique
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
           'comment'       => $assetsign->getDocumentComment(),
           'entities_id'   => $assetsign->fields['entities_id'],
           '_filename'     => [basename($tmpPath)],
           '_tag_filename' => [basename($tmpPath)],
       ]);

       $document->getFromDB($documents_id);
       return $document;
   }
}
