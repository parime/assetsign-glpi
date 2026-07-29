<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use CommonGLPI;
use Migration;
use State;

/**
 * Configuration du plugin, une ligne par entite qui souhaite surcharger
 * les reglages par defaut (herites sinon de l'entite racine, id=0).
 */
class Config extends CommonDBTM
{
    public static $rightname = 'plugin_remise_config';

    private const DEFAULTS = [
        'id'                                  => 0,
        'entities_id'                         => 0,
        'is_recursive'                        => 1,
        'sender_name'                         => 'GLPI - Gestion du parc',
        'sender_email'                        => '',
        'logo_documents_id'                   => 0,
        'logo_force_children'                 => 0,
        'charter_url'                         => '',
        'default_plugin_remise_templates_id'  => 0,
        'default_provider'                    => 'canvas',
        'provider_config'                     => '',
        'reminder_delays'                     => '3,7,7',
        'max_reminders'                       => 0,
        'link_validity_days'                  => 30,
        'signature_required'                  => 1,
        'sign_on_assignment'                  => 1,
        'sign_on_reassignment'                => 1,
        'sign_on_return'                      => 0,
        'managed_itemtypes'                   => '["Computer","Monitor","Peripheral","Phone"]',
        'handover_states'                     => '[]',
        'return_states'                       => '[]',
    ];

    public static function getTypeName($nb = 0): string
    {
        return __('Configuration remise & signature', 'remise');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Entity') {
            return __('Remise & signature', 'remise');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() !== 'Entity') {
            return false;
        }
        self::showConfigForm((int) $item->getID());
        return true;
    }

    /**
     * Rendu du formulaire de configuration, partage par l'onglet Entity et
     * la page dediee du menu d'administration (front/config.php).
     */
    public static function showConfigForm(int $entities_id): void
    {
        $config = self::getForEntity($entities_id);

        $effectiveLogoId = self::getEffectiveLogoDocumentId($entities_id);
        $logoIsForced = $effectiveLogoId > 0 && $effectiveLogoId !== (int) $config->fields['logo_documents_id'];

        $logoDocument = null;
        if ($effectiveLogoId > 0) {
            $doc = new \Document();
            if ($doc->getFromDB($effectiveLogoId)) {
                $logoDocument = $doc;
            }
        }

        $previewHtml = (new Pdf\HandoverPdfBuilder())->renderPreview($entities_id);

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/config_form.html.twig', [
            'config'          => $config->fields,
            'entity_id'       => $entities_id,
            'csrf_token'      => \Session::getNewCSRFToken(),
            'preview_html'    => $previewHtml,
            // Seul "canvas" est reellement implemente (cf. Provider\ProviderFactory) : Yousign et
            // DocuSeal ont ete retires du choix pour ne pas laisser un admin selectionner un
            // fournisseur qui ferait planter la creation de toute remise (RuntimeException non
            // rattrapee auparavant, cf. plugin_remise_item_assignment()).
            'providers'       => ['canvas' => __('Signature intégrée (canvas)', 'remise')],
            'itemtype_labels' => self::getItemtypeLabels(),
            'managed'         => $config->getManagedItemtypes(),
            'all_states'      => self::getAllStates(),
            'handover_states' => $config->getHandoverStates(),
            'return_states'   => $config->getReturnStates(),
            'logo_document'   => $logoDocument,
            'logo_is_forced'  => $logoIsForced,
        ]);
    }

    /**
     * Formats d'image acceptes pour le logo, uploade directement depuis le
     * poste de l'admin (input type="file" classique, pas le composant de
     * televersement JS de GLPI) : traite ici comme n'importe quel fichier
     * genere par du code (meme convention que HandoverPdfBuilder::
     * storeAsDocument()) — deplace dans GLPI_TMP_DIR puis attache via
     * Document::add()/_filename, sans dependre du widget de televersement natif.
     */
    private const LOGO_ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * @return int L'ID du Document cree, ou 0 si l'upload est absent/invalide
     *             (message d'erreur ajoute a la session dans ce dernier cas).
     */
    public static function uploadLogo(array $file, int $entities_id): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 0; // aucun fichier selectionne : rien a faire, pas une erreur
        }

        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            \Session::addMessageAfterRedirect(__('Échec de l\'envoi du logo.', 'remise'), false, ERROR);
            return 0;
        }

        $extension = strtolower((string) pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($extension, self::LOGO_ALLOWED_EXTENSIONS, true) || @getimagesize($file['tmp_name']) === false) {
            \Session::addMessageAfterRedirect(__('Le logo doit être une image (PNG, JPG, GIF ou WEBP).', 'remise'), false, ERROR);
            return 0;
        }

        $tmpName = uniqid('remise_logo_', true) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
            \Session::addMessageAfterRedirect(__('Échec de l\'envoi du logo.', 'remise'), false, ERROR);
            return 0;
        }

        $document = new \Document();
        $documents_id = $document->add([
            'name'          => 'Logo remise & signature',
            'entities_id'   => $entities_id,
            '_filename'     => [$tmpName],
            '_tag_filename' => [$tmpName],
        ]);

        return $documents_id ?: 0;
    }

    public static function getItemtypeLabels(): array
    {
        $labels = [];
        foreach (self::getAllManageableItemtypes() as $itemtype) {
            $labels[$itemtype] = $itemtype::getTypeName(2);
        }
        return $labels;
    }

    /**
     * Types geres "en dur" (Computer, Monitor...) + tous les actifs personnalises
     * actifs (Configuration > Actifs personnalisés / Glpi\Asset\AssetDefinitionManager),
     * enumeres dynamiquement a chaque appel : un actif personnalise cree ou active
     * apres coup est donc pris en compte sans modification ni reinstallation du
     * plugin. Chaque actif personnalise possede toujours users_id et states_id
     * (Glpi\Asset\Asset implemente AssignableItemInterface et StateInterface), la
     * logique de declenchement de Remise::handleItemAssignment() fonctionne donc
     * sans adaptation.
     */
    public static function getAllManageableItemtypes(): array
    {
        global $DB;

        $itemtypes = PLUGIN_REMISE_DEFAULT_ITEMTYPES;

        // Volontairement une requete SQL directe plutot que
        // Glpi\Asset\AssetDefinitionManager::getInstance()->getDefinitions() : cette
        // methode est utilisee depuis plugin_init_remise(), qui s'execute (listener
        // "InitializePlugins", priorite 110) AVANT que GLPI ne charge les definitions
        // d'actifs personnalises en memoire (listener "CustomObjectsBoot", priorite
        // 100 - donc plus tard). A ce stade du cycle de vie de la requete,
        // AssetDefinitionManager::getDefinitions() renvoie toujours un tableau vide,
        // meme si des actifs personnalises actifs existent en base. La table SQL,
        // elle, est deja disponible bien plus tot (connexion DB = priorite 190).
        if ($DB->tableExists('glpi_assets_assetdefinitions')) {
            foreach ($DB->request(['FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['is_active' => 1]]) as $row) {
                // Convention de nommage GLPI : voir Glpi\CustomObject\AbstractDefinition::
                // getCustomObjectClassName() et Glpi\Asset\AssetDefinition::getCustomObjectNamespace().
                $itemtypes[] = 'Glpi\\CustomAsset\\' . $row['system_name'] . 'Asset';
            }
        }

        return $itemtypes;
    }

    /**
     * Liste (id => nom) de tous les Etats GLPI (glpi_states), pour peupler les
     * menus deroulants de declenchement par statut. Volontairement toutes
     * entites confondues : un Etat est une liste deroulante globale dans GLPI.
     */
    public static function getAllStates(): array
    {
        global $DB;

        $states = [];
        foreach ($DB->request(['FROM' => State::getTable(), 'ORDER' => 'name']) as $row) {
            $states[(int) $row['id']] = $row['name'];
        }
        return $states;
    }

    /**
     * Renvoie la configuration effective pour une entite : sa propre ligne si
     * elle existe, sinon celle de l'ancetre le plus proche qui en a une (pas
     * seulement "elle-meme puis directement la racine" : une organisation avec
     * une hierarchie a plusieurs niveaux, ex. Racine > Region > Site, doit voir
     * la config d'une entite intermediaire s'appliquer a ses entites filles qui
     * n'ont pas leur propre config, meme si la racine n'en a pas non plus).
     * Sinon, valeurs par defaut en memoire (le plugin fonctionne meme sans
     * configuration explicite).
     */
    public static function getForEntity(int $entities_id): self
    {
        global $DB;

        $config = new self();
        if ($DB->tableExists(self::getTable())) {
            $candidateIds = getAncestorsOf('glpi_entities', $entities_id);
            $candidateIds[$entities_id] = $entities_id;

            $rows = iterator_to_array($DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['entities_id' => array_values($candidateIds)],
            ]));

            if (count($rows) === 1) {
                $config->getFromDB((int) reset($rows)['id']);
                return $config;
            }

            if (count($rows) > 1) {
                // Plusieurs ancetres ont une config : on garde celle de l'entite
                // la plus profonde (le "level" GLPI le plus eleve = la plus proche).
                $levels = [];
                foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['id' => array_column($rows, 'entities_id')]]) as $erow) {
                    $levels[(int) $erow['id']] = (int) $erow['level'];
                }
                usort($rows, static fn($a, $b) => ($levels[(int) $b['entities_id']] ?? 0) <=> ($levels[(int) $a['entities_id']] ?? 0));
                $config->getFromDB((int) $rows[0]['id']);
                return $config;
            }
        }

        $config->fields = self::DEFAULTS;
        $config->fields['entities_id'] = $entities_id;
        return $config;
    }

    public static function getDefault(): self
    {
        return self::getForEntity(0);
    }

    /**
     * Renvoie l'ID du Document a utiliser comme logo pour cette entite, en
     * tenant compte d'un logo impose par une entite ANCETRE (champ
     * `logo_force_children`) — qui l'emporte meme si l'entite elle-meme a deja
     * son propre logo configure. getForEntity() n'hérite le logo (comme le
     * reste de la config) que pour une entite qui n'a AUCUNE ligne propre :
     * une entite qui a deja personnalise autre chose (ex: son propre e-mail
     * d'expediteur) resterait sinon bloquee sur son propre logo — ou l'absence
     * de logo — sans moyen pour une entite parente d'imposer malgre tout le
     * sien a l'ensemble de ses filiales, quel que soit leur niveau de
     * profondeur (`getAncestorsOf()` remonte toute la chaine, pas seulement le
     * parent direct).
     */
    public static function getEffectiveLogoDocumentId(int $entities_id): int
    {
        global $DB;

        if ($DB->tableExists(self::getTable())) {
            $ancestorIds = getAncestorsOf('glpi_entities', $entities_id);

            if ($ancestorIds !== []) {
                $rows = iterator_to_array($DB->request([
                    'FROM'  => self::getTable(),
                    'WHERE' => [
                        'entities_id'         => array_values($ancestorIds),
                        'logo_force_children' => 1,
                        ['logo_documents_id' => ['>', 0]],
                    ],
                ]));

                if ($rows !== []) {
                    // Plusieurs ancetres (sur des branches differentes de la
                    // hierarchie) peuvent chacun imposer leur propre logo :
                    // celui de l'ancetre le plus proche (level GLPI le plus
                    // eleve) l'emporte, comme pour la resolution habituelle
                    // de la config (cf. getForEntity()).
                    $levels = [];
                    foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['id' => array_column($rows, 'entities_id')]]) as $erow) {
                        $levels[(int) $erow['id']] = (int) $erow['level'];
                    }
                    usort($rows, static fn($a, $b) => ($levels[(int) $b['entities_id']] ?? 0) <=> ($levels[(int) $a['entities_id']] ?? 0));
                    return (int) $rows[0]['logo_documents_id'];
                }
            }
        }

        return (int) self::getForEntity($entities_id)->fields['logo_documents_id'];
    }

    /**
     * Cree ou met a jour la ligne de configuration d'une entite (formulaire d'onglet Entity).
     */
    public static function upsertForEntity(int $entities_id, array $input): void
    {
        $managedItemtypes = [];
        foreach (self::getAllManageableItemtypes() as $itemtype) {
            if (!empty($input['manage_' . $itemtype])) {
                $managedItemtypes[] = $itemtype;
            }
        }

        $data = [
            'sender_name'          => $input['sender_name'] ?? '',
            'sender_email'         => $input['sender_email'] ?? '',
            'logo_documents_id'    => (int) ($input['logo_documents_id'] ?? 0),
            'logo_force_children'  => (int) ($input['logo_force_children'] ?? 0),
            'charter_url'          => trim($input['charter_url'] ?? ''),
            'default_provider'     => $input['default_provider'] ?? 'canvas',
            'reminder_delays'      => $input['reminder_delays'] ?? '3,7,7',
            'max_reminders'        => (int) ($input['max_reminders'] ?? 0),
            'link_validity_days'   => (int) ($input['link_validity_days'] ?? 30),
            'signature_required'   => (int) ($input['signature_required'] ?? 0),
            'sign_on_assignment'   => (int) ($input['sign_on_assignment'] ?? 0),
            'sign_on_reassignment' => (int) ($input['sign_on_reassignment'] ?? 0),
            'sign_on_return'       => (int) ($input['sign_on_return'] ?? 0),
            'managed_itemtypes'    => json_encode($managedItemtypes),
            'handover_states'      => json_encode(array_map('intval', $input['handover_states'] ?? [])),
            'return_states'        => json_encode(array_map('intval', $input['return_states'] ?? [])),
        ];

        $config = new self();
        if ($config->getFromDBByCrit(['entities_id' => $entities_id])) {
            $config->update(['id' => $config->getID()] + $data);
            return;
        }

        $config->add(['entities_id' => $entities_id, 'is_recursive' => 1] + $data);
    }

    public function getManagedItemtypes(): array
    {
        $decoded = json_decode($this->fields['managed_itemtypes'] ?? '', true);
        return is_array($decoded) && count($decoded) > 0 ? $decoded : PLUGIN_REMISE_DEFAULT_ITEMTYPES;
    }

    public function getReminderDelays(): array
    {
        return array_map('intval', explode(',', $this->fields['reminder_delays'] ?: '3,7,7'));
    }

    /** États GLPI (glpi_states) qui, une fois atteints, déclenchent une remise. */
    public function getHandoverStates(): array
    {
        $decoded = json_decode($this->fields['handover_states'] ?? '', true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /** États GLPI (glpi_states) qui, une fois atteints, déclenchent une restitution. */
    public function getReturnStates(): array
    {
        $decoded = json_decode($this->fields['return_states'] ?? '', true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
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
                `sender_name` varchar(255) DEFAULT NULL,
                `sender_email` varchar(255) DEFAULT NULL,
                `logo_documents_id` int unsigned NOT NULL DEFAULT 0,
                `logo_force_children` tinyint NOT NULL DEFAULT 0,
                `charter_url` varchar(255) DEFAULT NULL,
                `default_plugin_remise_templates_id` int unsigned NOT NULL DEFAULT 0,
                `default_provider` varchar(32) NOT NULL DEFAULT 'canvas',
                `provider_config` text,
                `reminder_delays` varchar(255) NOT NULL DEFAULT '3,7,7',
                `max_reminders` int unsigned NOT NULL DEFAULT 0,
                `link_validity_days` int unsigned NOT NULL DEFAULT 30,
                `signature_required` tinyint NOT NULL DEFAULT 1,
                `sign_on_assignment` tinyint NOT NULL DEFAULT 1,
                `sign_on_reassignment` tinyint NOT NULL DEFAULT 1,
                `sign_on_return` tinyint NOT NULL DEFAULT 0,
                `managed_itemtypes` text,
                `handover_states` text,
                `return_states` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`),
                KEY `is_recursive` (`is_recursive`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $DB->insert($table, [
                'entities_id'        => 0,
                'is_recursive'       => 1,
                'sender_name'        => self::DEFAULTS['sender_name'],
                'reminder_delays'    => self::DEFAULTS['reminder_delays'],
                'link_validity_days' => self::DEFAULTS['link_validity_days'],
                'signature_required' => 1,
                'sign_on_assignment' => 1,
                'sign_on_reassignment' => 1,
                'managed_itemtypes'  => self::DEFAULTS['managed_itemtypes'],
                'handover_states'    => self::DEFAULTS['handover_states'],
                'return_states'      => self::DEFAULTS['return_states'],
                'date_creation'      => date('Y-m-d H:i:s'),
            ]);
        } else {
            if (!$DB->fieldExists($table, 'handover_states')) {
                // Montee de version : ajoute les colonnes sans recreer la table.
                $migration->addField($table, 'handover_states', 'text');
                $migration->addField($table, 'return_states', 'text');
                $migration->migrationOneTable($table);
            }
            if (!$DB->fieldExists($table, 'charter_url')) {
                $migration->addField($table, 'charter_url', 'varchar(255)');
                $migration->migrationOneTable($table);
            }
            if (!$DB->fieldExists($table, 'logo_force_children')) {
                $migration->addField($table, 'logo_force_children', 'tinyint', ['value' => 0, 'after' => 'logo_documents_id']);
                $migration->migrationOneTable($table);
            }
        }
    }
}
