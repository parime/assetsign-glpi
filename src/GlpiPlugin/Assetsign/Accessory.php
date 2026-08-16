<?php

namespace GlpiPlugin\Assetsign;

use CommonDropdown;
use Migration;

/**
 * Catalogue des accessoires pouvant accompagner une remise
 * (chargeur, sacoche, station d'accueil, ecran, clavier, souris, casque...).
 * Dropdown standard GLPI : profite gratuitement du CRUD, de la recherche et
 * des droits generiques des listes deroulantes.
 */
class Accessory extends CommonDropdown
{
   public static function getTypeName($nb = 0): string {
       return _n('Accessoire de remise', 'Accessoires de remise', $nb, 'assetsign');
   }

    /**
     * Rattache au fil d'Ariane de Assetsign (menu 'tools') — meme raison que
     * Template::getSectorizedDetails().
     */
   public static function getSectorizedDetails(): array {
       return ['tools', Assetsign::class, self::class];
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

         foreach (['Chargeur', 'Station d\'accueil', 'Sacoche', 'Souris', 'Clavier', 'Casque', 'Écran additionnel'] as $name) {
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
