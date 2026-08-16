<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;

/**
 * Fiche de maintenance/preparation interne : checklist de points de controle
 * (configurables sans code, cf. MaintenanceChecklistItem) + commentaire libre.
 *
 * Sous-systeme VOLONTAIREMENT separe du moteur de fiches signees (Assetsign) :
 * pas de beneficiaire, pas de jeton, pas de notification — juste un technicien
 * qui coche une checklist. Partager la table glpi_plugin_assetsign_assetsigns aurait
 * melange deux cycles de vie tres differents dans le meme enregistrement
 * (decision actee avec l'utilisateur).
 *
 * Genere neanmoins un PDF (meme gabarit visuel que Assetsign, cf.
 * Pdf\MaintenancePdfBuilder et Pdf\PdfRenderingHelpers) et supporte OPTIONNELLEMENT
 * une signature du technicien (Config::enable_maintenance_signature, defaut
 * desactive) : contrairement au flux beneficiaire (jeton + page publique +
 * e-mail), le technicien est deja authentifie et signe directement sur CE
 * MEME formulaire de creation, en une seule requete (pas de jeton, pas
 * d'e-mail, pas de page separee - decision actee avec l'utilisateur).
 */
class Maintenance extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_MAINTENANCE;

   public static function getTypeName($nb = 0): string {
       return _n('Fiche de maintenance', 'Fiches de maintenance', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-tool';
   }

    /**
     * Enregistre sous le secteur 'tools' (Outils), pas 'admin' — meme raison
     * que Assetsign::getSectorizedDetails().
     */
   public static function getSectorizedDetails(): array {
       return ['tools', self::class];
   }

    /**
     * Etend le menu par defaut avec une entree pour MaintenanceChecklistItem —
     * meme raison que Assetsign::getMenuContent() pour Template/Accessory.
     */
   public static function getMenuContent(): array {
       $menu = parent::getMenuContent();
      if (!$menu) {
          return $menu;
      }

      if (MaintenanceChecklistItem::canView()) {
          $menu['options'][MaintenanceChecklistItem::class] = [
              'title' => MaintenanceChecklistItem::getTypeName(Session::getPluralNumber()),
              'page'  => MaintenanceChecklistItem::getSearchURL(false),
              'icon'  => MaintenanceChecklistItem::getIcon(),
              'links' => [
                  'search' => MaintenanceChecklistItem::getSearchURL(false),
                  'add'    => MaintenanceChecklistItem::getFormURL(false),
              ],
          ];
      }

       return $menu;
   }

    // rawSearchOptions() (pas getSearchOptions(), `final` dans CommonDBTM) :
    // meme correctif que Assetsign::rawSearchOptions(), meme cause, meme
    // symptome (liste "Fiches de maintenance" sans colonnes ni en-tetes).
   public function rawSearchOptions(): array {
       return [
           ['id' => 'common', 'name' => self::getTypeName(1)],
           ['id' => 1, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'],
           ['id' => 2, 'table' => self::getTable(), 'field' => 'itemtype', 'name' => __('Type de matériel', 'assetsign'), 'datatype' => 'itemtype'],
           ['id' => 3, 'table' => self::getTable(), 'field' => 'items_id', 'name' => __('Matériel', 'assetsign'), 'datatype' => 'itemlink', 'itemlink_type' => ''],
           ['id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech', 'name' => __('Technicien', 'assetsign'), 'datatype' => 'itemlink', 'itemlink_type' => 'User'],
           ['id' => 5, 'table' => self::getTable(), 'field' => 'date_creation', 'name' => __('Date'), 'datatype' => 'datetime'],
           // 'nosearch' : un ID de Document interne n'a aucun sens a filtrer,
           // cette colonne ne sert qu'a afficher un lien de telechargement
           // direct depuis la liste (cf. getSpecificValueToDisplay()), meme
           // convention que Assetsign::rawSearchOptions().
           ['id' => 6, 'table' => self::getTable(), 'field' => 'document_id', 'name' => __('PDF', 'assetsign'), 'datatype' => 'specific', 'nosearch' => true],
       ];
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
      if ($field === 'document_id') {
          $documents_id = (int) ($values[$field] ?? 0);
         if ($documents_id <= 0) {
             return '';
         }
          global $CFG_GLPI;
          return '<a href="' . $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documents_id . '" target="_blank">' . __('Télécharger', 'assetsign') . '</a>';
      }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return '';
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
       return self::createTabEntry(__('Maintenance', 'assetsign'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!($item instanceof CommonDBTM)) {
          return false;
      }
       self::showForItem($item);
       return true;
   }

   public static function showForItem(CommonDBTM $item): void {
       global $DB;

       $rows = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
           'ORDER' => 'date_creation DESC',
       ]);

       $entities_id = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());

       TemplateRenderer::getInstance()->display('@assetsign/maintenance_tab.html.twig', [
           'item'            => $item,
           'maintenances'    => iterator_to_array($rows),
           'checklist_items' => self::getActiveChecklistItems(),
           'can_create'      => Session::haveRight(self::$rightname, CREATE),
           // Etat des lieux visuel : meme reglage que sur les fiches signees
           // (Config::enable_damage_annotation, cf. Assetsign::showForm()) -
           // deposes cote client AVANT la creation de la fiche (jamais
           // modifiables ensuite, cf. DamageMarker), soumis d'un bloc avec le
           // reste du formulaire.
           'damage_annotation_enabled' => (bool) Config::getForEntity($entities_id)->fields['enable_damage_annotation'],
           'damage_views'    => DamageMarker::getViewLabels(),
           'damage_images'   => DamageMarker::getViewImageFilenames(),
           // Signature du technicien : optionnelle (Config::enable_maintenance_signature,
           // defaut desactivee), capturee cote client (canvas) AVANT la creation
           // de la fiche et soumise d'un bloc avec le reste du formulaire — meme
           // logique que l'etat des lieux visuel ci-dessus, jamais d'AJAX ici.
           'signature_required' => (bool) Config::getForEntity($entities_id)->fields['enable_maintenance_signature'],
           'csrf_token'      => Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Fiche en lecture seule : une fois creee, une fiche de maintenance n'est
     * pas destinee a etre modifiee (c'est un constat a un instant donne, pas
     * un document qui evolue) — meme logique que Assetsign::showForm().
     */
   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);

       TemplateRenderer::getInstance()->display('@assetsign/maintenance_form.html.twig', [
           'item'              => $this,
           // Resultats lus depuis la jointure, PAS depuis
           // getActiveChecklistItems() : un point desactive APRES la creation
           // de cette fiche doit rester visible sur ce constat historique.
           'checklist_results' => $this->isNewID($ID) ? [] : $this->getChecklistResults(),
           // Etat des lieux visuel : purement en lecture (fiche immuable des
           // sa creation, cf. commentaire de classe) - jamais de JS d'edition
           // charge ici, contrairement a assetsign_form.html.twig.
           'damage_annotation_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_damage_annotation'],
           'damage_views'   => DamageMarker::getViewLabels(),
           'damage_images'  => DamageMarker::getViewImageFilenames(),
           'damage_markers_by_view' => $this->isNewID($ID) ? [] : Assetsign::groupMarkersByView(DamageMarker::getForMaintenance((int) $ID)),
           'signature_proof' => $this->isNewID($ID) ? null : Signature::getForMaintenance((int) $ID),
       ]);

       return true;
   }

    /**
     * Materiel concerne, avec marque/modele resolus — meme forme que
     * Assetsign::getTargetItem() (reutilise ses resolveurs statiques, la
     * resolution ne depend pas du type de fiche parente).
     */
   public function getTargetItem(): array {
       $itemtype = $this->fields['itemtype'];
       $item = new $itemtype();
       $item->getFromDB((int) $this->fields['items_id']);

       $fields = $item->fields;
       $fields['manufacturer_name'] = Assetsign::resolveManufacturerName($item);
       $fields['model_name'] = Assetsign::resolveModelName($item);

       return $fields;
   }

    /** Technicien ayant realise cette fiche, avec son e-mail fusionne (meme logique que Assetsign::getBeneficiary()). */
   public function getTechnician(): array {
       $user = new \User();
       $user->getFromDB((int) $this->fields['users_id_tech']);
       $fields = $user->fields;
       $fields['email'] = \UserEmail::getDefaultForUser((int) $this->fields['users_id_tech']) ?: '';
       return $fields;
   }

    /**
     * Titre lisible utilise pour nommer le Document GLPI du PDF genere : meme
     * principe que Assetsign::getDocumentTitle() (date en tete pour le tri
     * chronologique, texte fixe en francais car un nom de Document est fige
     * a sa creation et ne doit pas dependre de la session de qui declenche
     * la generation).
     */
   public function getDocumentTitle(): string {
       $tech = $this->getTechnician();
       $techName = trim(\formatUserName(0, $tech['name'] ?? '', $tech['realname'] ?? '', $tech['firstname'] ?? ''));

       $item = $this->getTargetItem();
       $itemName = $item['name'] ?? '';

       $date = $this->fields['date_creation'] ? date('Y-m-d', strtotime($this->fields['date_creation'])) : date('Y-m-d');

       return trim(sprintf('%s — Maintenance — %s (%s)', $date, $itemName ?: $this->fields['itemtype'], $techName ?: '?'));
   }

    /**
     * Points de controle renseignes sur cette fiche (actifs ou non au moment
     * de la consultation), avec leur type et la valeur enregistree : texte
     * saisi ou option choisie pour text/select, null pour checkbox (sa seule
     * presence en base signifie "coche").
     * @return array<int, array{name: string, type: int, value: ?string}>
     */
   public function getChecklistResults(): array {
       global $DB;

       $results = [];
      foreach ($DB->request([
           'SELECT' => [
               'glpi_plugin_assetsign_maintenancechecklistitems.name',
               'glpi_plugin_assetsign_maintenancechecklistitems.type',
               'glpi_plugin_assetsign_maintenancechecklistvalues.value',
           ],
           'FROM'   => 'glpi_plugin_assetsign_maintenancechecklistvalues',
           'INNER JOIN' => [
               'glpi_plugin_assetsign_maintenancechecklistitems' => [
                   'FKEY' => [
                       'glpi_plugin_assetsign_maintenancechecklistvalues'  => 'plugin_assetsign_maintenancechecklistitems_id',
                       'glpi_plugin_assetsign_maintenancechecklistitems'   => 'id',
                   ],
               ],
           ],
           'WHERE' => ['glpi_plugin_assetsign_maintenancechecklistvalues.plugin_assetsign_maintenances_id' => $this->getID()],
           'ORDER' => 'glpi_plugin_assetsign_maintenancechecklistitems.name',
       ]) as $row) {
          $results[] = [
              'name'  => $row['name'],
              'type'  => (int) $row['type'],
              'value' => $row['value'],
          ];
      }
       return $results;
   }

    /**
     * Formulaire de creation autonome, affiche sur front/maintenance.php (en
     * plus du formulaire deja present sur l'onglet Maintenance de chaque
     * materiel, cf. showForItem()) : choisir le materiel concerne SANS avoir
     * a ouvrir sa fiche au prealable. Reutilise le mecanisme natif GLPI de
     * selection "type de materiel puis materiel" (Ajax::updateItemOnSelectEvent()
     * + ajax/dropdownAllItems.php, meme convention que Item_Enclosure/Item_Rack
     * dans le cœur GLPI) plutot que d'ecrire un composant de recherche maison.
     */
   public static function showCreateForm(): void {
      if (!Session::haveRight(self::$rightname, CREATE)) {
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
       echo '<span id="assetsign-maintenance-items-container">'
           . __('Choisissez d\'abord un type de matériel ci-dessus.', 'assetsign')
           . '</span>';
       \Ajax::updateItemOnSelectEvent(
           "dropdown_itemtype$rand",
           'assetsign-maintenance-items-container',
           $CFG_GLPI['root_doc'] . '/ajax/dropdownAllItems.php',
           [
               'idtable' => '__VALUE__',
               'name'    => 'items_id',
               'rand'    => $rand,
           ]
       );
       $itemDropdownHtml = ob_get_clean();

       TemplateRenderer::getInstance()->display('@assetsign/maintenance_create.html.twig', [
           'itemtype_dropdown_html' => $itemtypeDropdownHtml,
           'item_dropdown_html'     => $itemDropdownHtml,
           'checklist_items'        => self::getActiveChecklistItems(),
           // Le materiel n'est pas encore choisi a ce stade (formulaire
           // autonome, cf. commentaire de methode) : son entite n'est donc pas
           // encore connue - on se rabat sur l'entite active de la session,
           // meme logique que la plupart des reglages GLPI resolus avant
           // qu'une cible precise ne soit selectionnee.
           'damage_annotation_enabled' => (bool) Config::getForEntity(Session::getActiveEntity())->fields['enable_damage_annotation'],
           'damage_views'    => DamageMarker::getViewLabels(),
           'damage_images'   => DamageMarker::getViewImageFilenames(),
           'signature_required' => (bool) Config::getForEntity(Session::getActiveEntity())->fields['enable_maintenance_signature'],
           'csrf_token'             => Session::getNewCSRFToken(),
       ]);
   }

    /** @return array<int, array{name: string, type: int, options: string[]}> Tous les points de controle actifs, par id. */
   public static function getActiveChecklistItems(): array {
       global $DB;

       $items = [];
      foreach ($DB->request(['FROM' => MaintenanceChecklistItem::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $row) {
          $items[(int) $row['id']] = [
              'name'    => $row['name'],
              'type'    => (int) $row['type'],
              'options' => MaintenanceChecklistItem::parseOptions((string) ($row['options'] ?? '')),
          ];
      }
       return $items;
   }

    /**
     * Cree une fiche de maintenance a partir des valeurs soumises pour
     * chaque point de controle actif : $itemValues est le tableau brut
     * $_POST['checklist'] (id => valeur soumise), interprete selon le type
     * propre de chaque point (case a cocher / texte libre / menu deroulant).
     * $damageMarkers : marqueurs d'etat des lieux deposes cote client AVANT
     * cette creation (cf. DamageMarker::createMarkersForMaintenance()) - deja
     * decodes depuis le JSON soumis par damage-annotation-local.js.
     *
     * Genere systematiquement le PDF de la fiche (Config::
     * enable_maintenance_signature ne conditionne QUE la presence d'une
     * signature dedans, pas la generation du PDF lui-meme, cf. USER_GUIDE.md).
     * $signatureImage : data URI PNG brute soumise par le formulaire, validee
     * ICI (pas par l'appelant) quand la signature est activee pour l'entite -
     * meme convention que Assetsign::createManual() (valide et leve AVANT toute
     * ecriture en base, cf. front/maintenance.form.php qui se contente d'un
     * try/catch autour de cet appel). Ignoree si la signature n'est pas activee.
     * $signatureMeta : 'ip'/'user_agent' du technicien signataire, memes cles
     * que le $meta de Api\SignController::submit() (cf. front/sign.php) -
     * lus depuis $_SERVER par l'appelant, jamais ici (le modele ne lit pas les
     * superglobales directement).
     *
     * @param array<int|string, mixed> $itemValues
     * @param array<int, array<string, mixed>> $damageMarkers
     * @param array{ip?: string, user_agent?: string} $signatureMeta
     * @throws \RuntimeException si la signature est activee pour l'entite mais absente/invalide
     */
   public static function createWithChecklist(
       string $itemtype,
       int $items_id,
       int $entities_id,
       array $itemValues,
       string $comment,
       array $damageMarkers = [],
       ?string $signatureImage = null,
       array $signatureMeta = []
   ): int {
       global $DB;

       $signatureEnabled = (bool) Config::getForEntity($entities_id)->fields['enable_maintenance_signature'];
      if ($signatureEnabled) {
          Pdf\SignatureImageValidator::assertValid((string) $signatureImage);
      }

       $maintenance = new self();
       $id = (int) $maintenance->add([
           'entities_id'   => $entities_id,
           'itemtype'      => $itemtype,
           'items_id'      => $items_id,
           'users_id_tech' => Session::getLoginUserID() ?: 0,
           'comment'       => $comment,
       ]);

      if ($id <= 0) {
          return 0;
      }

      if ($damageMarkers !== []) {
          DamageMarker::createMarkersForMaintenance($id, $damageMarkers);
      }

      foreach (self::getActiveChecklistItems() as $checklistItemId => $checklistItem) {
         if (!array_key_exists($checklistItemId, $itemValues) && !array_key_exists((string) $checklistItemId, $itemValues)) {
             continue;
         }
          $submitted = $itemValues[$checklistItemId] ?? $itemValues[(string) $checklistItemId];

          $value = match ($checklistItem['type']) {
              MaintenanceChecklistItem::TYPE_TEXT, MaintenanceChecklistItem::TYPE_SELECT => trim((string) $submitted),
              default => null,
          };

            // Case a cocher : presence de la cle suffit (valeur '1' du
            // <input type="checkbox">). Texte/select : seule une valeur non
            // vide est enregistree, sinon le point est considere non renseigne.
         if ($checklistItem['type'] !== MaintenanceChecklistItem::TYPE_CHECKBOX && $value === '') {
             continue;
         }

            $DB->insert('glpi_plugin_assetsign_maintenancechecklistvalues', [
              'plugin_assetsign_maintenances_id'          => $id,
              'plugin_assetsign_maintenancechecklistitems_id' => $checklistItemId,
              'value'                                   => $value,
            ]);
      }

       $maintenance->getFromDB($id);
       $signedAt = $signatureEnabled ? date('Y-m-d H:i:s') : null;

       $builder = new Pdf\MaintenancePdfBuilder();
       $result = $builder->build($maintenance, $signatureEnabled ? $signatureImage : null, $signedAt);

       $maintenance->update(['id' => $id, 'document_id' => $result['document']->getID()]);

      if ($signatureEnabled) {
          $tech = $maintenance->getTechnician();
          Signature::recordProofForMaintenance($maintenance, [
              'signer_name'   => trim(($tech['firstname'] ?? '') . ' ' . ($tech['realname'] ?? '')),
              'signer_email'  => $tech['email'] ?? '',
              'ip_address'    => $signatureMeta['ip'] ?? '',
              'user_agent'    => $signatureMeta['user_agent'] ?? '',
              'document_hash' => $result['hash'],
              'signed_at'     => $signedAt,
          ]);
      }

       PassportEvent::recordForMaintenance($maintenance);

       return $id;
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `users_id_tech` int unsigned NOT NULL DEFAULT 0,
                `comment` text,
                `document_id` int unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `users_id_tech` (`users_id_tech`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      } else if (!$DB->fieldExists($table, 'document_id')) {
          // Montee de version : ajoute la reference au PDF genere a la
          // creation (cf. createWithChecklist()), toujours produit desormais
          // meme quand aucune signature n'est activee pour l'entite.
          $migration->addField($table, 'document_id', 'integer', ['value' => 0, 'after' => 'comment']);
          $migration->migrationOneTable($table);
      }

       $valuesTable = 'glpi_plugin_assetsign_maintenancechecklistvalues';
      if (!$DB->tableExists($valuesTable)) {
          $migration->displayMessage('Création de la table ' . $valuesTable);
          $DB->doQuery("CREATE TABLE `$valuesTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_maintenances_id` int unsigned NOT NULL,
                `plugin_assetsign_maintenancechecklistitems_id` int unsigned NOT NULL,
                `value` text,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_maintenances_id`,`plugin_assetsign_maintenancechecklistitems_id`),
                KEY `plugin_assetsign_maintenancechecklistitems_id` (`plugin_assetsign_maintenancechecklistitems_id`),
                CONSTRAINT `fk_mcv_maintenance` FOREIGN KEY (`plugin_assetsign_maintenances_id`) REFERENCES `glpi_plugin_assetsign_maintenances` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mcv_checklistitem` FOREIGN KEY (`plugin_assetsign_maintenancechecklistitems_id`) REFERENCES `glpi_plugin_assetsign_maintenancechecklistitems` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }

      if (!$DB->fieldExists($valuesTable, 'value')) {
          $migration->addField($valuesTable, 'value', 'text');
      }

       self::seedDefaultDisplayPreferences();
   }

    /**
     * Meme raison que Assetsign::seedDefaultDisplayPreferences() : sans ca, la
     * liste "Fiches de maintenance" n'affiche par defaut que la colonne ID.
     * Seme une seule fois (jamais si une ligne existe deja pour cet itemtype).
     */
   private static function seedDefaultDisplayPreferences(): void {
       global $DB;

       $alreadySeeded = $DB->request([
           'FROM'  => 'glpi_displaypreferences',
           'WHERE' => ['itemtype' => self::class],
           'LIMIT' => 1,
       ])->count() > 0;

      if ($alreadySeeded) {
          return;
      }

       $rank = 1;
      foreach ([2, 3, 4, 5] as $searchOptionId) {
          $DB->insert('glpi_displaypreferences', [
              'itemtype'  => self::class,
              'num'       => $searchOptionId,
              'rank'      => $rank++,
              'users_id'  => 0,
              'interface' => 'central',
          ]);
      }
   }
}
