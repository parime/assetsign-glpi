<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;

/**
 * Journal des relances envoyees pour une remise : une ligne par relance,
 * uniquement pour en compter le nombre (affiche sur sa fiche, cf.
 * remise_form.html.twig) — le compteur faisant foi pour la logique metier
 * (limite max_reminders) est Remise.reminder_count, pas ce journal.
 */
class Reminder extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_REMISE;

   public static function log(Remise $remise): void {
       global $DB;
       $DB->insert(self::getTable(), [
           'plugin_remise_remises_id' => $remise->getID(),
       ]);
   }

   public static function countForRemise(int $remises_id): int {
       return countElementsInTable(self::getTable(), ['plugin_remise_remises_id' => $remises_id]);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_remise_remises_id` int unsigned NOT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_remise_remises_id` (`plugin_remise_remises_id`),
                CONSTRAINT `fk_reminder_remise` FOREIGN KEY (`plugin_remise_remises_id`) REFERENCES `glpi_plugin_remise_remises` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      } else {
          // Audit code mort : 'reminder_number'/'date_sent'/'channel' n'etaient
          // jamais relus (countForRemise() ne fait qu'un COUNT(*)) — 'channel'
          // valait d'ailleurs toujours 'email', log() n'etant jamais appele
          // avec un 3e argument.
         foreach (['reminder_number', 'date_sent', 'channel'] as $obsoleteField) {
            if ($DB->fieldExists($table, $obsoleteField)) {
               $migration->dropField($table, $obsoleteField);
            }
         }
      }
   }
}
