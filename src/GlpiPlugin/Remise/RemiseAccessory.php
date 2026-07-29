<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;

/**
 * Jonction remise <-> accessoires remis (chargeur, sacoche, ecran...).
 * Utilisee par la fiche Remise (front/remise.form.php), pas de front dedie.
 */
class RemiseAccessory extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_REMISE;

    public static function attach(int $remises_id, int $accessories_id, int $quantity = 1, string $comment = ''): void
    {
        global $DB;

        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_remise_remises_id' => $remises_id, 'plugin_remise_accessories_id' => $accessories_id],
        ]);

        if (count(iterator_to_array($existing)) > 0) {
            $DB->update(self::getTable(), ['quantity' => $quantity, 'comment' => $comment], [
                'plugin_remise_remises_id'     => $remises_id,
                'plugin_remise_accessories_id' => $accessories_id,
            ]);
            return;
        }

        (new self())->add([
            'plugin_remise_remises_id'     => $remises_id,
            'plugin_remise_accessories_id' => $accessories_id,
            'quantity'                     => $quantity,
            'comment'                      => $comment,
        ]);
    }

    public static function detach(int $remises_id, int $accessories_id): void
    {
        global $DB;
        $DB->delete(self::getTable(), [
            'plugin_remise_remises_id'     => $remises_id,
            'plugin_remise_accessories_id' => $accessories_id,
        ]);
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
                `plugin_remise_accessories_id` int unsigned NOT NULL,
                `quantity` int unsigned NOT NULL DEFAULT 1,
                `comment` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_remise_remises_id`,`plugin_remise_accessories_id`),
                KEY `plugin_remise_accessories_id` (`plugin_remise_accessories_id`),
                CONSTRAINT `fk_ra_remise` FOREIGN KEY (`plugin_remise_remises_id`) REFERENCES `glpi_plugin_remise_remises` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ra_accessory` FOREIGN KEY (`plugin_remise_accessories_id`) REFERENCES `glpi_plugin_remise_accessories` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }
}
