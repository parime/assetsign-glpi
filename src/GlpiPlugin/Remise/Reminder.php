<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;

/**
 * Journal des relances envoyees pour une remise (nombre affiche sur sa fiche,
 * cf. remise_form.html.twig).
 */
class Reminder extends CommonDBTM
{
    public static $rightname = 'plugin_remise_remise';

    public static function log(Remise $remise, int $number, string $channel = 'email'): void
    {
        global $DB;
        $DB->insert(self::getTable(), [
            'plugin_remise_remises_id' => $remise->getID(),
            'reminder_number'          => $number,
            'date_sent'                => date('Y-m-d H:i:s'),
            'channel'                  => $channel,
        ]);
    }

    public static function countForRemise(int $remises_id): int
    {
        return countElementsInTable(self::getTable(), ['plugin_remise_remises_id' => $remises_id]);
    }

    public static function install(Migration $migration): void
    {
        global $DB;
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage('Création de la table ' . $table);
            $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_remise_remises_id` int unsigned NOT NULL,
                `reminder_number` int unsigned NOT NULL,
                `date_sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `channel` varchar(32) NOT NULL DEFAULT 'email',
                PRIMARY KEY (`id`),
                KEY `plugin_remise_remises_id` (`plugin_remise_remises_id`),
                CONSTRAINT `fk_reminder_remise` FOREIGN KEY (`plugin_remise_remises_id`) REFERENCES `glpi_plugin_remise_remises` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }
}
