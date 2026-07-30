<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use CommonGLPI;
use Migration;
use Session;
use Glpi\Application\View\TemplateRenderer;

/**
 * Fiche de maintenance/preparation interne : checklist de points de controle
 * (configurables sans code, cf. MaintenanceChecklistItem) + commentaire libre.
 *
 * Sous-systeme VOLONTAIREMENT separe du moteur de fiches signees (Remise) :
 * pas de beneficiaire, pas de jeton, pas de signature, pas de notification,
 * pas de PDF — juste un technicien qui coche une checklist. Partager la table
 * glpi_plugin_remise_remises aurait melange deux cycles de vie tres
 * differents dans le meme enregistrement (decision actee avec l'utilisateur).
 */
class Maintenance extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_MAINTENANCE;

    public static function getTypeName($nb = 0): string
    {
        return _n('Fiche de maintenance', 'Fiches de maintenance', $nb, 'remise');
    }

    public static function getIcon(): string
    {
        return 'ti ti-tool';
    }

    // rawSearchOptions() (pas getSearchOptions(), `final` dans CommonDBTM) :
    // meme correctif que Remise::rawSearchOptions(), meme cause, meme
    // symptome (liste "Fiches de maintenance" sans colonnes ni en-tetes).
    public function rawSearchOptions(): array
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'],
            ['id' => 2, 'table' => self::getTable(), 'field' => 'itemtype', 'name' => __('Type de matériel', 'remise'), 'datatype' => 'itemtype'],
            ['id' => 3, 'table' => self::getTable(), 'field' => 'items_id', 'name' => __('Matériel', 'remise'), 'datatype' => 'itemlink', 'itemlink_type' => ''],
            ['id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech', 'name' => __('Technicien', 'remise'), 'datatype' => 'itemlink', 'itemlink_type' => 'User'],
            ['id' => 5, 'table' => self::getTable(), 'field' => 'date_creation', 'name' => __('Date'), 'datatype' => 'datetime'],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
            return '';
        }
        $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
        return self::createTabEntry(__('Maintenance', 'remise'), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof CommonDBTM)) {
            return false;
        }
        self::showForItem($item);
        return true;
    }

    public static function showForItem(CommonDBTM $item): void
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
            'ORDER' => 'date_creation DESC',
        ]);

        TemplateRenderer::getInstance()->display('@remise/maintenance_tab.html.twig', [
            'item'            => $item,
            'maintenances'    => iterator_to_array($rows),
            'checklist_items' => self::getActiveChecklistItems(),
            'can_create'      => Session::haveRight(self::$rightname, CREATE),
            'csrf_token'      => Session::getNewCSRFToken(),
        ]);
    }

    /**
     * Fiche en lecture seule : une fois creee, une fiche de maintenance n'est
     * pas destinee a etre modifiee (c'est un constat a un instant donne, pas
     * un document qui evolue) — meme logique que Remise::showForm().
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@remise/maintenance_form.html.twig', [
            'item'          => $this,
            // Noms des points coches lus depuis la jointure, PAS depuis
            // getActiveChecklistItems() : un point desactive APRES la creation
            // de cette fiche doit rester visible sur ce constat historique.
            'checked_names' => $this->isNewID($ID) ? [] : $this->getCheckedItemNames(),
        ]);

        return true;
    }

    /** @return string[] Noms des points de controle coches (actifs ou non), pour l'affichage. */
    public function getCheckedItemNames(): array
    {
        global $DB;

        $names = [];
        foreach ($DB->request([
            'SELECT' => ['glpi_plugin_remise_maintenancechecklistitems.name'],
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
            $names[] = $row['name'];
        }
        return $names;
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
    public static function showCreateForm(): void
    {
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

        TemplateRenderer::getInstance()->display('@remise/maintenance_create.html.twig', [
            'itemtype_dropdown_html' => $itemtypeDropdownHtml,
            'item_dropdown_html'     => $itemDropdownHtml,
            'checklist_items'        => self::getActiveChecklistItems(),
            'csrf_token'             => Session::getNewCSRFToken(),
        ]);
    }

    /** @return array<int, string> Tous les points de controle actifs (id => nom). */
    public static function getActiveChecklistItems(): array
    {
        global $DB;

        $items = [];
        foreach ($DB->request(['FROM' => MaintenanceChecklistItem::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $row) {
            $items[(int) $row['id']] = $row['name'];
        }
        return $items;
    }

    /**
     * Cree une fiche de maintenance avec les points de controle coches fournis.
     * @param int[] $checkedItemIds
     */
    public static function createWithChecklist(string $itemtype, int $items_id, int $entities_id, array $checkedItemIds, string $comment): int
    {
        global $DB;

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

        $activeIds = array_keys(self::getActiveChecklistItems());
        foreach (array_intersect($checkedItemIds, $activeIds) as $checklistItemId) {
            $DB->insert('glpi_plugin_remise_maintenancechecklistvalues', [
                'plugin_remise_maintenances_id'          => $id,
                'plugin_remise_maintenancechecklistitems_id' => $checklistItemId,
            ]);
        }

        return $id;
    }

    public static function install(Migration $migration): void
    {
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
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `users_id_tech` (`users_id_tech`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }

        $valuesTable = 'glpi_plugin_remise_maintenancechecklistvalues';
        if (!$DB->tableExists($valuesTable)) {
            $migration->displayMessage('Création de la table ' . $valuesTable);
            $DB->doQuery("CREATE TABLE `$valuesTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_remise_maintenances_id` int unsigned NOT NULL,
                `plugin_remise_maintenancechecklistitems_id` int unsigned NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_remise_maintenances_id`,`plugin_remise_maintenancechecklistitems_id`),
                KEY `plugin_remise_maintenancechecklistitems_id` (`plugin_remise_maintenancechecklistitems_id`),
                CONSTRAINT `fk_mcv_maintenance` FOREIGN KEY (`plugin_remise_maintenances_id`) REFERENCES `glpi_plugin_remise_maintenances` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mcv_checklistitem` FOREIGN KEY (`plugin_remise_maintenancechecklistitems_id`) REFERENCES `glpi_plugin_remise_maintenancechecklistitems` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }
}
