<?php

namespace GlpiPlugin\Remise;

use CommonDropdown;
use Migration;

/**
 * Catalogue des points de controle proposes sur une fiche de maintenance
 * (nettoyage, mise a jour, controle batterie...). Dropdown standard GLPI,
 * meme motif qu'Accessory.php : ajout/suppression/reordonnancement par
 * l'administrateur sans toucher au code (Configuration > Intitulés).
 */
class MaintenanceChecklistItem extends CommonDropdown
{
    public static function getTypeName($nb = 0): string
    {
        return _n('Point de contrôle de maintenance', 'Points de contrôle de maintenance', $nb, 'remise');
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
                `comment` text,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            foreach ([
                'Nettoyage physique',
                'Mise à jour des pilotes',
                'Mise à jour du système',
                'Contrôle de la batterie',
                'Contrôle antivirus',
                'Vérification des ports/connectiques',
            ] as $name) {
                $DB->insert($table, [
                    'entities_id'   => 0,
                    'is_recursive'  => 1,
                    'name'          => $name,
                    'is_active'     => 1,
                    'date_creation' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
