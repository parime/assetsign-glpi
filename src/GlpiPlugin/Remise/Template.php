<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;

/**
 * Gabarit de contrat / charte, editable dans Configuration > Remise > Gabarits.
 * Le contenu est du HTML brut insere tel quel dans le PDF (balise Twig |raw).
 */
class Template extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_TEMPLATE;

    private const DEFAULT_HANDOVER_CONTENT = '<p>Je soussigné(e) reconnais avoir reçu le matériel décrit ci-dessus, en bon état de fonctionnement, '
        . 'et m\'engage à en assurer la garde, l\'usage raisonnable et la restitution en cas de départ ou de demande '
        . 'de l\'équipe informatique.</p>';

    private const DEFAULT_RETURN_CONTENT = '<p>Je soussigné(e) atteste avoir restitué le matériel décrit ci-dessus au service informatique.</p>';

    private const DEFAULT_CHARTER_CONTENT = '<p>L\'utilisation du matériel informatique doit se conformer à la charte informatique en vigueur '
        . 'dans l\'entreprise. Toute anomalie ou dysfonctionnement doit être signalé sans délai au service informatique.</p>';

    /**
     * Texte pre-rempli propose a l'administrateur pour un NOUVEAU gabarit — pas
     * seulement pour le gabarit seme automatiquement a l'installation (cf.
     * install()). Sans cela, un administrateur qui cree son propre gabarit
     * partait d'un champ entierement vide, avec un risque reel de fiche de
     * remise/restitution sans aucune condition generale ni charte affichee au
     * beneficiaire — constate en conditions reelles. Le texte reste entierement
     * modifiable (ou effaçable) via le formulaire, ce n'est qu'une valeur de
     * depart.
     */
    public static function getDefaultContentFor(int $type): array
    {
        return match ($type) {
            Remise::TYPE_RETURN => ['content' => self::DEFAULT_RETURN_CONTENT, 'charter_content' => ''],
            default             => ['content' => self::DEFAULT_HANDOVER_CONTENT, 'charter_content' => self::DEFAULT_CHARTER_CONTENT],
        };
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Gabarit de remise', 'Gabarits de remise', $nb, 'remise');
    }

    public function getSearchOptions(): array
    {
        return [
            1 => ['table' => self::getTable(), 'field' => 'name', 'name' => __('Nom'), 'datatype' => 'itemlink'],
            2 => ['table' => self::getTable(), 'field' => 'type', 'name' => __('Type de remise', 'remise'), 'datatype' => 'specific'],
            3 => ['table' => self::getTable(), 'field' => 'is_default', 'name' => __('Par défaut', 'remise'), 'datatype' => 'bool'],
            4 => ['table' => self::getTable(), 'field' => 'is_active', 'name' => __('Actif'), 'datatype' => 'bool'],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'type') {
            return Remise::getTypes()[$values['type']] ?? $values['type'];
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        // Pour un NOUVEAU gabarit (pas encore en base), pre-remplit avec un texte
        // par defaut raisonnable plutot que de laisser les champs vides — cf.
        // getDefaultContentFor(). Le type par defaut du formulaire est
        // TYPE_HANDOVER (premiere option du select) tant que l'administrateur n'a
        // pas choisi ; il verra alors la version "restitution" si demandee.
        $defaultContent = $this->isNewID($ID)
            ? self::getDefaultContentFor(Remise::TYPE_HANDOVER)
            : ['content' => $this->fields['content'] ?? '', 'charter_content' => $this->fields['charter_content'] ?? ''];

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/template_form.html.twig', [
            'item'            => $this,
            'types'           => Remise::getTypes(),
            'csrf_token'      => \Session::getNewCSRFToken(),
            'default_content' => $defaultContent,
        ]);

        return true;
    }

    public static function getDefaultFor(int $type, int $entities_id): ?self
    {
        global $DB;

        foreach ([$entities_id, 0] as $tryEntity) {
            $rows = iterator_to_array($DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['type' => $type, 'is_default' => 1, 'is_active' => 1, 'entities_id' => $tryEntity],
                'LIMIT' => 1,
            ]));
            if (count($rows) > 0) {
                $template = new self();
                $template->getFromDB(reset($rows)['id']);
                return $template;
            }
        }
        return null;
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
                `name` varchar(255) NOT NULL DEFAULT '',
                `type` tinyint NOT NULL DEFAULT 0,
                `content` text,
                `charter_content` text,
                `is_default` tinyint NOT NULL DEFAULT 0,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `type` (`type`),
                KEY `is_default` (`is_default`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $handoverDefaults = self::getDefaultContentFor(\GlpiPlugin\Remise\Remise::TYPE_HANDOVER);
            $DB->insert($table, [
                'entities_id'     => 0,
                'is_recursive'    => 1,
                'name'            => 'Gabarit de remise par défaut',
                'type'            => \GlpiPlugin\Remise\Remise::TYPE_HANDOVER,
                'content'         => $handoverDefaults['content'],
                'charter_content' => $handoverDefaults['charter_content'],
                'is_default'      => 1,
                'is_active'       => 1,
                'date_creation'   => date('Y-m-d H:i:s'),
            ]);

            $returnDefaults = self::getDefaultContentFor(\GlpiPlugin\Remise\Remise::TYPE_RETURN);
            $DB->insert($table, [
                'entities_id'     => 0,
                'is_recursive'    => 1,
                'name'            => 'Gabarit de restitution par défaut',
                'type'            => \GlpiPlugin\Remise\Remise::TYPE_RETURN,
                'content'         => $returnDefaults['content'],
                'charter_content' => $returnDefaults['charter_content'],
                'is_default'      => 1,
                'is_active'       => 1,
                'date_creation'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            // Montee de version : desactive les gabarits de l'ancien type "Echange"
            // (valeur 2, retiree — cf. Remise::TYPE_EXCHANGE) sans les supprimer,
            // pour ne pas perdre l'historique tout en les sortant de la liste active.
            $DB->update($table, ['is_active' => 0], ['type' => 2]);
        }
    }
}
