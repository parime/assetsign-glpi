<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Jonction assetsign <-> accessoires remis (chargeur, sacoche, ecran...).
 * Utilisee par la fiche Assetsign (front/assetsign.form.php), pas de front dedie.
 */
class AssetsignAccessory extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public static function attach(int $assetsigns_id, int $accessories_id, int $quantity = 1, string $comment = ''): void {
       global $DB;

       $existing = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_assetsign_assetsigns_id' => $assetsigns_id, 'plugin_assetsign_accessories_id' => $accessories_id],
       ]);

      if (count(iterator_to_array($existing)) > 0) {
          $DB->update(self::getTable(), ['quantity' => $quantity, 'comment' => $comment], [
              'plugin_assetsign_assetsigns_id'     => $assetsigns_id,
              'plugin_assetsign_accessories_id' => $accessories_id,
          ]);
          return;
      }

       (new self())->add([
           'plugin_assetsign_assetsigns_id'     => $assetsigns_id,
           'plugin_assetsign_accessories_id' => $accessories_id,
           'quantity'                     => $quantity,
           'comment'                      => $comment,
       ]);
   }

   public static function detach(int $assetsigns_id, int $accessories_id): void {
       global $DB;
       $DB->delete(self::getTable(), [
           'plugin_assetsign_assetsigns_id'     => $assetsigns_id,
           'plugin_assetsign_accessories_id' => $accessories_id,
       ]);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `plugin_assetsign_accessories_id` int unsigned NOT NULL,
                `quantity` int unsigned NOT NULL DEFAULT 1,
                `comment` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_assetsigns_id`,`plugin_assetsign_accessories_id`),
                KEY `plugin_assetsign_accessories_id` (`plugin_assetsign_accessories_id`),
                CONSTRAINT `fk_ra_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ra_accessory` FOREIGN KEY (`plugin_assetsign_accessories_id`) REFERENCES `glpi_plugin_assetsign_accessories` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
