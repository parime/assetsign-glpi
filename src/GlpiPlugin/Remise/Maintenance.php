<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Remise\Pdf\MaintenancePdfBuilder;
use GlpiPlugin\Remise\Pdf\SignatureImageValidator;
use Migration;
use Session;

/**
 * Fiche de maintenance/preparation interne : checklist de points de controle
 * (configurables sans code, cf. MaintenanceChecklistItem) + commentaire libre.
 *
 * Sous-systeme VOLONTAIREMENT separe du moteur de fiches signees (Remise) :
 * pas de beneficiaire, pas de jeton, pas de notification — juste un
 * technicien qui coche une checklist, avec une signature OPTIONNELLE de sa
 * part (activable via Config::maintenance_signature_required, auquel cas
 * elle devient obligatoire pour creer la fiche — cf. createWithChecklist()).
 * Partager la table glpi_plugin_remise_remises aurait melange deux cycles de
 * vie tres differents dans le meme enregistrement (decision actee avec
 * l'utilisateur).
 */
class Maintenance extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_MAINTENANCE;

   public static function getTypeName($nb = 0): string {
       return _n('Fiche de maintenance', 'Fiches de maintenance', $nb, 'remise');
   }

   public static function getIcon(): string {
       return 'ti ti-tool';
   }

    // rawSearchOptions() (pas getSearchOptions(), `final` dans CommonDBTM) :
    // meme correctif que Remise::rawSearchOptions(), meme cause, meme
    // symptome (liste "Fiches de maintenance" sans colonnes ni en-tetes).
   public function rawSearchOptions(): array {
       return [
           ['id' => 'common', 'name' => self::getTypeName(1)],
           ['id' => 1, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'],
           ['id' => 2, 'table' => self::getTable(), 'field' => 'itemtype', 'name' => __('Type de matériel', 'remise'), 'datatype' => 'itemtype'],
           ['id' => 3, 'table' => self::getTable(), 'field' => 'items_id', 'name' => __('Matériel', 'remise'), 'datatype' => 'itemlink', 'itemlink_type' => ''],
           ['id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech', 'name' => __('Technicien', 'remise'), 'datatype' => 'itemlink', 'itemlink_type' => 'User'],
           ['id' => 5, 'table' => self::getTable(), 'field' => 'date_creation', 'name' => __('Date'), 'datatype' => 'datetime'],
           // 'nosearch' : un ID de Document interne n'a aucun sens a filtrer,
           // cette colonne ne sert qu'a afficher un lien de telechargement
           // direct depuis la liste (cf. getSpecificValueToDisplay()), meme
           // motif que Remise::rawSearchOptions() (colonnes 10/11).
           ['id' => 6, 'table' => self::getTable(), 'field' => 'document_id', 'name' => __('Compte-rendu (PDF)', 'remise'), 'datatype' => 'specific', 'nosearch' => true],
       ];
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
      if ($field === 'document_id') {
          $documents_id = (int) ($values['document_id'] ?? 0);
         if ($documents_id <= 0) {
             return '';
         }
          global $CFG_GLPI;
          return '<a href="' . $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documents_id . '" target="_blank">'
              . __('Télécharger le PDF', 'remise') . '</a>';
      }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return '';
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
       return self::createTabEntry(__('Maintenance', 'remise'), $count);
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
       $config = Config::getForEntity($entities_id);

       TemplateRenderer::getInstance()->display('@remise/maintenance_tab.html.twig', [
           'item'            => $item,
           'maintenances'    => iterator_to_array($rows),
           'checklist_items' => self::getActiveChecklistItems(),
           'can_create'      => Session::haveRight(self::$rightname, CREATE),
           // Etat des lieux visuel : meme reglage que sur les fiches signees
           // (Config::enable_damage_annotation, cf. Remise::showForm()) -
           // deposes cote client AVANT la creation de la fiche (jamais
           // modifiables ensuite, cf. DamageMarker), soumis d'un bloc avec le
           // reste du formulaire.
           'damage_annotation_enabled' => (bool) $config->fields['enable_damage_annotation'],
           'damage_views'    => DamageMarker::getViewLabels(),
           'damage_images'   => DamageMarker::getViewImageFilenames(),
           // Signature du technicien : un seul reglage pilote a la fois l'affichage
           // et le caractere obligatoire (pas de troisieme etat "propose mais
           // facultatif", cf. demande utilisateur/TROUBLESHOOTING.md) — capturee
           // cote client, soumise d'un bloc avec le reste du formulaire de
           // creation (cf. signature_edit.html.twig).
           'maintenance_signature_required' => (bool) $config->fields['maintenance_signature_required'],
           'csrf_token'      => Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Fiche en lecture seule : une fois creee, une fiche de maintenance n'est
     * pas destinee a etre modifiee (c'est un constat a un instant donne, pas
     * un document qui evolue) — meme logique que Remise::showForm().
     */
   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);

       TemplateRenderer::getInstance()->display('@remise/maintenance_form.html.twig', [
           'item'              => $this,
           // Resultats lus depuis la jointure, PAS depuis
           // getActiveChecklistItems() : un point desactive APRES la creation
           // de cette fiche doit rester visible sur ce constat historique.
           'checklist_results' => $this->isNewID($ID) ? [] : $this->getChecklistResults(),
           // Etat des lieux visuel : purement en lecture (fiche immuable des
           // sa creation, cf. commentaire de classe) - jamais de JS d'edition
           // charge ici, contrairement a remise_form.html.twig.
           'damage_annotation_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_damage_annotation'],
           'damage_views'   => DamageMarker::getViewLabels(),
           'damage_images'  => DamageMarker::getViewImageFilenames(),
           'damage_markers_by_view' => $this->isNewID($ID) ? [] : Remise::groupMarkersByView(DamageMarker::getForMaintenance((int) $ID)),
           // Preuve de signature du technicien, si la fiche en comporte une —
           // meme presentation que remise_form.html.twig (Signature::getForRemise()).
           'signature_proof' => $this->isNewID($ID) ? null : Signature::getForMaintenance((int) $ID),
       ]);

       return true;
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
               'glpi_plugin_remise_maintenancechecklistitems.name',
               'glpi_plugin_remise_maintenancechecklistitems.type',
               'glpi_plugin_remise_maintenancechecklistvalues.value',
           ],
           'FROM'   => 'glpi_plugin_remise_maintenancechecklistvalues',
           'INNER JOIN' => [
               'glpi_plugin_remise_maintenancechecklistitems' => [
                   'FKEY' => [
                       'glpi_plugin_remise_maintenancechecklistvalues'  => 'plugin_remise_maintenancechecklistitems_id',
                       'glpi_plugin_remise_maintenancechecklistitems'   => 'id',
                   ],
               ],
           ],
           'WHERE' => ['glpi_plugin_remise_maintenancechecklistvalues.plugin_remise_maintenances_id' => $this->getID()],
           'ORDER' => 'glpi_plugin_remise_maintenancechecklistitems.name',
       ]) as $row) {
          $results[] = [
              'name'  => $row['name'],
              'type'  => (int) $row['type'],
              'value' => $row['value'],
          ];
      }
       return $results;
   }

    /** @return array Champs du materiel cible, enrichis de marque/modele — meme forme que Remise::getTargetItem(). */
   public function getTargetItem(): array {
       $itemtype = $this->fields['itemtype'];
       $item = new $itemtype();
       $item->getFromDB((int) $this->fields['items_id']);

       $fields = $item->fields;
       $fields['manufacturer_name'] = Remise::resolveManufacturerName($item);
       $fields['model_name'] = Remise::resolveModelName($item);

       return $fields;
   }

    /**
     * Technicien ayant realise la maintenance (users_id_tech, renseigne
     * automatiquement a la creation par createWithChecklist() — c'est
     * toujours celui qui a rempli la checklist, jamais saisi separement).
     * Meme forme de tableau que Remise::getBeneficiary() (fusion de l'e-mail,
     * absent de glpi_users) pour que le gabarit PDF n'ait rien a distinguer.
     */
   public function getTechnician(): array {
       $user = new \User();
       $user->getFromDB((int) $this->fields['users_id_tech']);
       $fields = $user->fields;
       $fields['email'] = \UserEmail::getDefaultForUser((int) $this->fields['users_id_tech']) ?: '';
       return $fields;
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
       echo '<span id="remise-maintenance-items-container">'
           . __('Choisissez d\'abord un type de matériel ci-dessus.', 'remise')
           . '</span>';
       \Ajax::updateItemOnSelectEvent(
           "dropdown_itemtype$rand",
           'remise-maintenance-items-container',
           $CFG_GLPI['root_doc'] . '/ajax/dropdownAllItems.php',
           [
               'idtable' => '__VALUE__',
               'name'    => 'items_id',
               'rand'    => $rand,
           ]
       );
       $itemDropdownHtml = ob_get_clean();

       // Le materiel n'est pas encore choisi a ce stade (formulaire
       // autonome, cf. commentaire de methode) : son entite n'est donc pas
       // encore connue - on se rabat sur l'entite active de la session,
       // meme logique que la plupart des reglages GLPI resolus avant
       // qu'une cible precise ne soit selectionnee.
       $config = Config::getForEntity(Session::getActiveEntity());

       TemplateRenderer::getInstance()->display('@remise/maintenance_create.html.twig', [
           'itemtype_dropdown_html' => $itemtypeDropdownHtml,
           'item_dropdown_html'     => $itemDropdownHtml,
           'checklist_items'        => self::getActiveChecklistItems(),
           'damage_annotation_enabled' => (bool) $config->fields['enable_damage_annotation'],
           'damage_views'    => DamageMarker::getViewLabels(),
           'damage_images'   => DamageMarker::getViewImageFilenames(),
           'maintenance_signature_required' => (bool) $config->fields['maintenance_signature_required'],
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
     * $signatureImage : data URI PNG de la signature du technicien, capturee
     * cote client (cf. signature_edit.html.twig / signature-local.js) et
     * soumise d'un bloc avec le reste du formulaire, comme les marqueurs
     * d'etat des lieux — chaine vide si aucune signature n'a ete tracee.
     * @param array<int|string, mixed> $itemValues
     * @param array<int, array<string, mixed>> $damageMarkers
     * @throws \RuntimeException si la signature est obligatoire (cf.
     *         Config::maintenance_signature_required) mais absente, ou si une
     *         signature fournie est invalide (cf. SignatureImageValidator) —
     *         meme idiome que Remise::createManual(), attrape par le
     *         controleur front (cf. front/maintenance.form.php).
     */
   public static function createWithChecklist(string $itemtype, int $items_id, int $entities_id, array $itemValues, string $comment, array $damageMarkers = [], string $signatureImage = ''): int {
       global $DB;

       $config = Config::getForEntity($entities_id);
       $signatureImage = trim($signatureImage);

      if ((bool) $config->fields['maintenance_signature_required'] && $signatureImage === '') {
          throw new \RuntimeException(__('La signature du technicien est obligatoire pour valider cette fiche.', 'remise'));
      }
      if ($signatureImage !== '') {
          SignatureImageValidator::assertValid($signatureImage);
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

            $DB->insert('glpi_plugin_remise_maintenancechecklistvalues', [
              'plugin_remise_maintenances_id'          => $id,
              'plugin_remise_maintenancechecklistitems_id' => $checklistItemId,
              'value'                                   => $value,
            ]);
      }

       $signedAt = $signatureImage !== '' ? date('Y-m-d H:i:s') : null;

       // PDF genere une seule fois, ici, juste apres que la checklist et les
       // marqueurs d'etat des lieux soient en base : contrairement a Remise,
       // une fiche de maintenance n'est jamais modifiee ensuite (cf. commentaire
       // de showForm()), un seul PDF suffit donc pour toute sa vie - pas de
       // mecanisme de regeneration comme Remise::regenerateUnsignedPdf().
       $document = (new MaintenancePdfBuilder())->build($maintenance, $signatureImage, $signedAt);
       $maintenance->update(['id' => $id, 'document_id' => $document->getID()]);

      if ($signatureImage !== '') {
          $technician = $maintenance->getTechnician();
          $fullpath = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
          Signature::recordProofForMaintenance($id, [
              'signer_name'   => trim(($technician['firstname'] ?? '') . ' ' . ($technician['realname'] ?? '')),
              'signer_email'  => $technician['email'] ?? '',
              'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
              'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
              'document_hash' => hash('sha256', (string) file_get_contents($fullpath)),
              'signed_at'     => $signedAt,
          ]);
      }

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
          // Montee de version : ajoute la colonne sans recreer la table -
          // 'integer' (pas 'int unsigned' brut) pour que Migration::addField()
          // pose reellement un DEFAULT 0/NOT NULL, meme piege deja documente
          // ailleurs dans ce plugin (cf. TROUBLESHOOTING.md).
          $migration->addField($table, 'document_id', 'integer', ['value' => 0, 'after' => 'comment']);
      }

       $valuesTable = 'glpi_plugin_remise_maintenancechecklistvalues';
      if (!$DB->tableExists($valuesTable)) {
          $migration->displayMessage('Création de la table ' . $valuesTable);
          $DB->doQuery("CREATE TABLE `$valuesTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_remise_maintenances_id` int unsigned NOT NULL,
                `plugin_remise_maintenancechecklistitems_id` int unsigned NOT NULL,
                `value` text,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_remise_maintenances_id`,`plugin_remise_maintenancechecklistitems_id`),
                KEY `plugin_remise_maintenancechecklistitems_id` (`plugin_remise_maintenancechecklistitems_id`),
                CONSTRAINT `fk_mcv_maintenance` FOREIGN KEY (`plugin_remise_maintenances_id`) REFERENCES `glpi_plugin_remise_maintenances` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mcv_checklistitem` FOREIGN KEY (`plugin_remise_maintenancechecklistitems_id`) REFERENCES `glpi_plugin_remise_maintenancechecklistitems` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }

      if (!$DB->fieldExists($valuesTable, 'value')) {
          $migration->addField($valuesTable, 'value', 'text');
      }

       self::seedDefaultDisplayPreferences();
   }

    /**
     * Meme raison que Remise::seedDefaultDisplayPreferences() : sans ca, la
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
      foreach ([2, 3, 4, 5, 6] as $searchOptionId) {
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
