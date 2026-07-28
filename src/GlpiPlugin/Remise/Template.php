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
    public static $rightname = 'plugin_remise_template';

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

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/template_form.html.twig', [
            'item'       => $this,
            'types'      => Remise::getTypes(),
            'csrf_token' => \Session::getNewCSRFToken(),
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

            $DB->insert($table, [
                'entities_id'     => 0,
                'is_recursive'    => 1,
                'name'            => 'Gabarit de remise par défaut',
                'type'            => \GlpiPlugin\Remise\Remise::TYPE_HANDOVER,
                'content'         => '<p>Je soussigné(e) reconnais avoir reçu le matériel décrit ci-dessus, en bon état de fonctionnement, '
                    . 'et m\'engage à en assurer la garde, l\'usage raisonnable et la restitution en cas de départ ou de demande '
                    . 'de l\'équipe informatique.</p>',
                'charter_content' => '<p>L\'utilisation du matériel informatique doit se conformer à la charte informatique en vigueur '
                    . 'dans l\'entreprise. Toute anomalie ou dysfonctionnement doit être signalé sans délai au service informatique.</p>',
                'is_default'      => 1,
                'is_active'       => 1,
                'date_creation'   => date('Y-m-d H:i:s'),
            ]);

            $DB->insert($table, [
                'entities_id'     => 0,
                'is_recursive'    => 1,
                'name'            => 'Gabarit de restitution par défaut',
                'type'            => \GlpiPlugin\Remise\Remise::TYPE_RETURN,
                'content'         => '<p>Je soussigné(e) atteste avoir restitué le matériel décrit ci-dessus au service informatique.</p>',
                'charter_content' => '',
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
