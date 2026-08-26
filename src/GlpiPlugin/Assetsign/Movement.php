<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonGLPI;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;

/**
 * Mouvement structuré de matériel (issue #75, cf. docs/design/ADR-passeport-v1.md
 * section 3.2 pour le schéma initialement proposé) : généralise la notion de
 * "remise" d'Assetsign (toujours une remise PERSONNE A -> PERSONNE B) à N'IMPORTE
 * QUEL déplacement physique de matériel (transfert inter-site, retour au stock,
 * envoi vers un centre de réparation...) - départ/destination (lieu + date),
 * document(s) joint(s), signature optionnelle.
 *
 * Déviation assumée par rapport au schéma initialement proposé dans l'ADR
 * (ALTER TABLE glpi_plugin_assetsign_assetsigns ADD locations_id_from/to) : une
 * classe/table strictement NOUVELLE plutôt que deux colonnes de plus sur
 * Assetsign, parce qu'un mouvement structuré doit pouvoir exister SANS aucune
 * remise associée (ex: un simple retour au stock, ou un transfert entre deux
 * sites, n'a pas de bénéficiaire humain à faire signer) - greffer ça sur
 * Assetsign aurait forcé chaque mouvement à porter tous les champs d'une remise
 * (bénéficiaire, jeton, statut de signature à distance...) même quand ils n'ont
 * aucun sens. Les trois principes de l'ADR restent scrupuleusement respectés :
 * départ/destination réutilisent `Location` (dropdown natif GLPI, jamais une
 * nouvelle table de lieux) ; les documents joints réutilisent `Document`/
 * `Document_Item` (relation polymorphe déjà native GLPI, jamais un nouveau
 * stockage) ; la signature réutilise `Signature` (déjà existante) selon le même
 * patron que `Maintenance` (technicien déjà authentifié, signe directement sur
 * CE formulaire de création - pas de jeton, pas d'e-mail, pas de PDF dédié :
 * contrairement à Assetsign, un mouvement n'a pas de document contractuel à
 * faire signer à distance par un bénéficiaire externe).
 *
 * Alimente la MÊME frise que Assetsign/Maintenance (glpi_plugin_assetsign_events,
 * cf. PassportEvent::recordForMovement()) : un nouveau PRODUCTEUR d'événements,
 * jamais une réécriture des producteurs existants (principe déjà établi par
 * l'ADR, section 1).
 */
class Movement extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public const STATUS_PLANNED    = 0;
   public const STATUS_IN_TRANSIT = 1;
   public const STATUS_COMPLETED  = 2;
   public const STATUS_CANCELLED  = 3;

   private const STATUSES_STILL_EDITABLE = [self::STATUS_PLANNED, self::STATUS_IN_TRANSIT];

   public static function getTypeName($nb = 0): string {
       return _n('Mouvement', 'Mouvements', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-truck-delivery';
   }

    /** Secteur 'tools' (Outils), pas 'admin' - même raison que Assetsign::getSectorizedDetails(). */
   public static function getSectorizedDetails(): array {
       return ['tools', self::class];
   }

    /** @return array<int, string> Libellé de chaque statut, par constante STATUS_*. */
   public static function getStatuses(): array {
       return [
           self::STATUS_PLANNED    => __('Prévu', 'assetsign'),
           self::STATUS_IN_TRANSIT => __('En cours', 'assetsign'),
           self::STATUS_COMPLETED  => __('Terminé', 'assetsign'),
           self::STATUS_CANCELLED  => __('Annulé', 'assetsign'),
       ];
   }

    /** Couleur de badge par statut (même palette que Config::health_score_*_color). */
   public static function getStatusColor(int $status): string {
       return match ($status) {
           self::STATUS_PLANNED    => '#6c757d',
           self::STATUS_IN_TRANSIT => '#f76707',
           self::STATUS_COMPLETED  => '#2fb344',
           self::STATUS_CANCELLED  => '#d63939',
           default                 => '#6c757d',
       };
   }

    /**
     * rawSearchOptions() (pas getSearchOptions(), `final` dans CommonDBTM) : même
     * correctif déjà documenté sur Assetsign::rawSearchOptions()/Maintenance::
     * rawSearchOptions(), même cause (méthode jamais appelée par Search::getOptions()
     * sous l'ancien nom), même symptôme (liste sans colonnes ni en-têtes).
     */
   public function rawSearchOptions(): array {
       $tab = [];

       $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];

       // Row link - même motif que Assetsign::rawSearchOptions() id=1 : sans ça, la
       // liste "Mouvements" n'offrirait aucun moyen de revenir à showForm().
       $tab[] = [
           'id'       => 1,
           'table'    => self::getTable(),
           'field'    => 'id',
           'name'     => __('ID'),
           'datatype' => 'itemlink',
           'itemtype' => self::class,
       ];
       $tab[] = [
           'id'       => 2,
           'table'    => self::getTable(),
           'field'    => 'itemtype',
           'name'     => __('Type de matériel', 'assetsign'),
           'datatype' => 'itemtype',
       ];
       $tab[] = [
           'id'            => 3,
           'table'         => self::getTable(),
           'field'         => 'items_id',
           'name'          => __('Matériel', 'assetsign'),
           'datatype'      => 'itemlink',
           'itemlink_type' => '',
       ];
       $tab[] = [
           'id'        => 4,
           'table'     => 'glpi_locations',
           'field'     => 'completename',
           'linkfield' => 'locations_id_from',
           'name'      => __('Lieu de départ', 'assetsign'),
           'datatype'  => 'dropdown',
       ];
       $tab[] = [
           'id'        => 5,
           'table'     => 'glpi_locations',
           'field'     => 'completename',
           'linkfield' => 'locations_id_to',
           'name'      => __('Lieu de destination', 'assetsign'),
           'datatype'  => 'dropdown',
       ];
       $tab[] = [
           'id'       => 6,
           'table'    => self::getTable(),
           'field'    => 'date_from',
           'name'     => __('Date de départ', 'assetsign'),
           'datatype' => 'datetime',
       ];
       $tab[] = [
           'id'       => 7,
           'table'    => self::getTable(),
           'field'    => 'date_to',
           'name'     => __('Date de destination', 'assetsign'),
           'datatype' => 'datetime',
       ];
       $tab[] = [
           'id'         => 8,
           'table'      => self::getTable(),
           'field'      => 'status',
           'name'       => __('Statut', 'assetsign'),
           'datatype'   => 'specific',
           'searchtype' => 'equals',
       ];
       $tab[] = [
           'id'        => 9,
           'table'     => 'glpi_users',
           'field'     => 'name',
           'linkfield' => 'users_id',
           'name'      => __('Déclaré par', 'assetsign'),
           'datatype'  => 'itemlink',
           'itemlink_type' => 'User',
       ];
       $tab[] = [
           'id'       => 10,
           'table'    => self::getTable(),
           'field'    => 'is_signed',
           'name'     => __('Signé', 'assetsign'),
           'datatype' => 'bool',
       ];
       $tab[] = [
           'id'       => 11,
           'table'    => self::getTable(),
           'field'    => 'comment',
           'name'     => __('Commentaire', 'assetsign'),
           'datatype' => 'text',
       ];

       return $tab;
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
      if ($field === 'status') {
          $status = (int) $values['status'];
          $label = self::getStatuses()[$status] ?? $status;
          return '<span class="badge" style="background-color: ' . self::getStatusColor($status) . '; color: #fff;">' . htmlspecialchars((string) $label) . '</span>';
      }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if (!($item instanceof CommonDBTM) || !self::isEnabledForItem($item)) {
          return '';
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
       return self::createTabEntry(__('Mouvements', 'assetsign'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!($item instanceof CommonDBTM) || !self::isEnabledForItem($item)) {
          return false;
      }
       self::showForItem($item);
       return true;
   }

    /** Onglet disponible pour ce type d'item (matériel géré par le plugin) ET la fonctionnalité activée pour son entité. */
   private static function isEnabledForItem(CommonDBTM $item): bool {
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return false;
      }
       return (bool) Config::getForEntity((int) ($item->fields['entities_id'] ?? 0))->fields['enable_movements'];
   }

    /**
     * Onglet "Mouvements" d'un matériel donné : liste de ses mouvements déjà
     * enregistrés + formulaire de création dont itemtype/items_id sont déjà
     * connus par le contexte (contrairement à showCreateForm(), utilisée sur
     * front/movement.php où le matériel reste à choisir).
     */
   public static function showForItem(CommonDBTM $item): void {
       global $DB;

       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
           'ORDER' => 'date_creation DESC',
       ]));

       $statuses = self::getStatuses();
      foreach ($rows as &$row) {
          $row['status_label'] = $statuses[(int) $row['status']] ?? '';
          $row['status_color'] = self::getStatusColor((int) $row['status']);
          $row['location_from_name'] = empty($row['locations_id_from']) ? '' : \Dropdown::getDropdownName('glpi_locations', (int) $row['locations_id_from']);
          $row['location_to_name']   = empty($row['locations_id_to']) ? '' : \Dropdown::getDropdownName('glpi_locations', (int) $row['locations_id_to']);
      }
       unset($row);

       $entities_id = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());

       TemplateRenderer::getInstance()->display('@assetsign/movement_tab.html.twig', [
           'item'                => $item,
           'movements'           => $rows,
           'can_create'          => Session::haveRight(self::$rightname, CREATE),
           'locations'           => self::getAllLocations(),
           'signature_required'  => (bool) Config::getForEntity($entities_id)->fields['enable_movement_signature'],
           'csrf_token'          => Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Formulaire de création autonome, affiché sur front/movement.php (en plus du
     * formulaire déjà présent sur l'onglet Mouvements de chaque matériel, cf.
     * showForItem()) : choisir le matériel concerné sans avoir à ouvrir sa fiche
     * au préalable. Réutilise le même mécanisme natif GLPI de sélection
     * "type de matériel puis matériel" que Maintenance::showCreateForm()
     * (Ajax::updateItemOnSelectEvent() + ajax/dropdownAllItems.php).
     */
   public static function showCreateForm(): void {
      if (!Session::haveRight(self::$rightname, CREATE)) {
          return;
      }
      if (!(bool) Config::getForEntity(Session::getActiveEntity())->fields['enable_movements']) {
          echo '<div class="alert alert-info">'
              . __('La fonctionnalité « Mouvements » n\'est pas activée pour cette entité (Configuration > Mouvements).', 'assetsign')
              . '</div>';
          return;
      }

       global $CFG_GLPI;

       $rand = mt_rand();

       ob_start();
       \Dropdown::showFromArray('itemtype', Config::getItemtypeLabels(), [
           'display_emptychoice' => true,
           'rand'                => $rand,
       ]);
       $itemtypeDropdownHtml = ob_get_clean();

       ob_start();
       echo '<span id="assetsign-movement-items-container">'
           . __('Choisissez d\'abord un type de matériel ci-dessus.', 'assetsign')
           . '</span>';
       \Ajax::updateItemOnSelectEvent(
           "dropdown_itemtype$rand",
           'assetsign-movement-items-container',
           $CFG_GLPI['root_doc'] . '/ajax/dropdownAllItems.php',
           [
               'idtable' => '__VALUE__',
               'name'    => 'items_id',
               'rand'    => $rand,
           ]
       );
       $itemDropdownHtml = ob_get_clean();

       TemplateRenderer::getInstance()->display('@assetsign/movement_create.html.twig', [
           'itemtype_dropdown_html' => $itemtypeDropdownHtml,
           'item_dropdown_html'     => $itemDropdownHtml,
           'locations'              => self::getAllLocations(),
           'signature_required'     => (bool) Config::getForEntity(Session::getActiveEntity())->fields['enable_movement_signature'],
           'csrf_token'             => Session::getNewCSRFToken(),
       ]);
   }

    /** @return array<int, string> Tous les lieux GLPI (glpi_locations), toutes entités confondues (dropdown global). */
   public static function getAllLocations(): array {
       global $DB;

       $locations = [];
      foreach ($DB->request(['FROM' => 'glpi_locations', 'ORDER' => 'completename']) as $row) {
          $locations[(int) $row['id']] = $row['completename'];
      }
       return $locations;
   }

    /**
     * Fiche en lecture seule pour le contenu du mouvement lui-même (départ/
     * destination/dates ne sont pas destinés à changer après coup - même
     * logique que Assetsign::showForm()/Maintenance::showForm()) : seul le
     * STATUT peut évoluer après création (boutons dédiés, cf.
     * front/movement.form.php), tant que le mouvement reste dans un état non
     * terminal (STATUSES_STILL_EDITABLE).
     */
   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);

       TemplateRenderer::getInstance()->display('@assetsign/movement_form.html.twig', [
           'item'              => $this,
           'params'            => $options,
           'statuses'          => self::getStatuses(),
           'status_color'      => self::getStatusColor((int) ($this->fields['status'] ?? self::STATUS_PLANNED)),
           'can_transition'    => !$this->isNewID($ID)
               && in_array((int) $this->fields['status'], self::STATUSES_STILL_EDITABLE, true)
               && Session::haveRight(self::$rightname, UPDATE),
           'location_from_name' => $this->isNewID($ID) || empty($this->fields['locations_id_from']) ? '' : \Dropdown::getDropdownName('glpi_locations', (int) $this->fields['locations_id_from']),
           'location_to_name'   => $this->isNewID($ID) || empty($this->fields['locations_id_to']) ? '' : \Dropdown::getDropdownName('glpi_locations', (int) $this->fields['locations_id_to']),
           'signature_proof'   => (!$this->isNewID($ID) && (int) $this->fields['is_signed'] === 1)
               ? Signature::getForMovement((int) $ID)
               : null,
           'documents'         => $this->isNewID($ID) ? [] : self::getAttachedDocuments((int) $ID),
           'can_attach_document' => !$this->isNewID($ID) && Session::haveRight(self::$rightname, UPDATE),
           'csrf_token'        => Session::getNewCSRFToken(),
       ]);
       return true;
   }

    /**
     * Documents joints a ce mouvement (bulletin de livraison, document de
     * transport...) - reutilise Document_Item, la relation polymorphe deja
     * native GLPI (cf. risque 2.2 de l'ADR), jamais un nouveau stockage de
     * documents. Inclut aussi bien les documents deposes via
     * attachUploadedDocument() (ci-dessous) que l'image de signature deposee
     * par create() (cf. attachSignatureDocument()) : les deux passent par la
     * meme table Document_Item, un seul mecanisme, jamais deux.
     * @return list<array{id: int, name: string}>
     */
   public static function getAttachedDocuments(int $movements_id): array {
       global $DB;

       $documents = [];
      foreach ($DB->request([
          'SELECT'     => ['glpi_documents.id', 'glpi_documents.name'],
          'FROM'       => 'glpi_documents_items',
          'INNER JOIN' => ['glpi_documents' => ['FKEY' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id']]],
          'WHERE'      => ['glpi_documents_items.itemtype' => self::class, 'glpi_documents_items.items_id' => $movements_id],
          'ORDER'      => 'glpi_documents.date_creation DESC',
      ]) as $row) {
          $documents[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
      }
       return $documents;
   }

    /**
     * Attache un document deja televerse (bulletin de livraison, document de
     * transport...) au mouvement - input type="file" classique (comme
     * Config::uploadLogo()), pas le composant de televersement JS de GLPI, pour
     * rester un formulaire autonome simple sur front/movement.form.php.
     */
   public function attachDocument(array $file): void {
      if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
          return; // aucun fichier selectionne : rien a faire, pas une erreur
      }
      if (($file['error'] ?? null) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du document.', 'assetsign'), false, ERROR);
          return;
      }

       $tmpName = uniqid('assetsign_movement_doc_', true) . '_' . basename((string) $file['name']);
      if (!copy($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du document.', 'assetsign'), false, ERROR);
          return;
      }

       $document = new Document();
       $documents_id = $document->add([
           'name'          => (string) ($file['name'] ?? $tmpName),
           'entities_id'   => $this->fields['entities_id'],
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);
      if (!$documents_id) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du document.', 'assetsign'), false, ERROR);
          return;
      }

       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => self::class,
           'items_id'     => $this->getID(),
       ]);
   }

    /**
     * Crée un mouvement structuré et l'événement Passeport correspondant en une
     * seule opération (même point de passage unique que Assetsign::launchWorkflow()/
     * Maintenance::createWithChecklist() : jamais deux endroits différents qui
     * pourraient diverger). $signatureImage : data URI PNG brute soumise par le
     * formulaire (canvas), optionnelle - validée ICI avant toute écriture en base,
     * même convention que Maintenance::createWithChecklist().
     * @return int L'ID du mouvement créé.
     */
   public static function create(
       string $itemtype,
       int $items_id,
       int $entities_id,
       array $input,
       ?string $signatureImage = null,
       array $signatureMeta = []
   ): int {
      if ($signatureImage !== null) {
          Pdf\SignatureImageValidator::assertValid($signatureImage);
      }

       $movement = new self();
       $id = (int) $movement->add([
           'entities_id'        => $entities_id,
           'itemtype'            => $itemtype,
           'items_id'            => $items_id,
           'locations_id_from'   => (int) ($input['locations_id_from'] ?? 0),
           'locations_id_to'     => (int) ($input['locations_id_to'] ?? 0),
           'date_from'           => $input['date_from'] ?: null,
           'date_to'             => $input['date_to'] ?: null,
           'status'              => (int) ($input['status'] ?? self::STATUS_PLANNED),
           'users_id'            => Session::getLoginUserID() ?: 0,
           'comment'             => (string) ($input['comment'] ?? ''),
       ]);
      if (!$id) {
          throw new \RuntimeException(__('Échec de la création du mouvement.', 'assetsign'));
      }
       $movement->getFromDB($id);

      if ($signatureImage !== null) {
          $documentHash = self::attachSignatureDocument($movement, $signatureImage);

           $signer = new \User();
           $signerName = '';
           $signerEmail = '';
         if ($signer->getFromDB((int) $movement->fields['users_id'])) {
             $signerName = trim(\formatUserName(0, $signer->fields['name'] ?? '', $signer->fields['realname'] ?? '', $signer->fields['firstname'] ?? ''));
             $signerEmail = \UserEmail::getDefaultForUser((int) $movement->fields['users_id']) ?: '';
         }

           Signature::recordProofForMovement($movement, [
               'signer_name'   => $signerName,
               'signer_email'  => $signerEmail,
               'ip_address'    => $signatureMeta['ip'] ?? '',
               'user_agent'    => $signatureMeta['user_agent'] ?? '',
               'document_hash' => $documentHash,
               'signed_at'     => date('Y-m-d H:i:s'),
           ]);

           $movement->update(['id' => $id, 'is_signed' => 1]);
           $movement->getFromDB($id);
      }

       PassportEvent::recordForMovement($movement);

       return $id;
   }

    /**
     * Sauvegarde l'image de signature (PNG, canvas) comme Document natif GLPI,
     * rattaché au mouvement lui-même (Document_Item, itemtype=self::class) - pas
     * de PDF dédié généré ici (contrairement à Assetsign/Maintenance) : un
     * mouvement n'a pas de document contractuel propre, la preuve visuelle de
     * signature EST le document. Rattaché en plus au matériel concerné, pour
     * rester visible depuis l'onglet Documents du matériel lui-même sans avoir à
     * ouvrir la fiche du mouvement.
     * @return string Empreinte SHA-256 du fichier PNG écrit, pour Signature::recordProofForMovement().
     */
   private static function attachSignatureDocument(self $movement, string $dataUri): string {
       preg_match('/^data:image\/png;base64,(.+)$/', $dataUri, $matches);
       $binary = base64_decode($matches[1], true);

       $tmpName = uniqid('assetsign_movement_signature_', true) . '.png';
       $tmpPath = GLPI_TMP_DIR . '/' . $tmpName;
       file_put_contents($tmpPath, $binary);
       $hash = hash_file('sha256', $tmpPath);

       $document = new Document();
       $documents_id = $document->add([
           'name'          => sprintf(__('Signature du mouvement #%d', 'assetsign'), $movement->getID()),
           'entities_id'   => $movement->fields['entities_id'],
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);

       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => self::class,
           'items_id'     => $movement->getID(),
       ]);
       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => $movement->fields['itemtype'],
           'items_id'     => $movement->fields['items_id'],
       ]);

       return (string) $hash;
   }

    /** Passe le mouvement en "En cours" (départ effectif). */
   public function markInTransit(): void {
      if (!in_array((int) $this->fields['status'], self::STATUSES_STILL_EDITABLE, true)) {
          return;
      }
       $this->update(['id' => $this->getID(), 'status' => self::STATUS_IN_TRANSIT]);
   }

    /** Passe le mouvement en "Terminé" (arrivée effective). */
   public function markCompleted(?string $dateTo = null): void {
      if (!in_array((int) $this->fields['status'], self::STATUSES_STILL_EDITABLE, true)) {
          return;
      }
       $this->update([
           'id'      => $this->getID(),
           'status'  => self::STATUS_COMPLETED,
           'date_to' => $dateTo ?: date('Y-m-d H:i:s'),
       ]);
   }

    /** Annule le mouvement (ex: transfert finalement abandonné). */
   public function cancel(): void {
      if (!in_array((int) $this->fields['status'], self::STATUSES_STILL_EDITABLE, true)) {
          return;
      }
       $this->update(['id' => $this->getID(), 'status' => self::STATUS_CANCELLED]);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `locations_id_from` int unsigned NOT NULL DEFAULT 0,
                `locations_id_to` int unsigned NOT NULL DEFAULT 0,
                `date_from` timestamp NULL DEFAULT NULL,
                `date_to` timestamp NULL DEFAULT NULL,
                `status` tinyint NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `is_signed` tinyint NOT NULL DEFAULT 0,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `entities_id` (`entities_id`),
                KEY `locations_id_from` (`locations_id_from`),
                KEY `locations_id_to` (`locations_id_to`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
