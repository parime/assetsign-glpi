<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Details propres au type Don (organisme beneficiaire) : table dediee 1-vers-1
 * avec Assetsign, meme motif exact que VenteDetails (issue #78, "fin de vie
 * structuree"). Le justificatif lui-meme (fichier) n'est PAS stocke ici : il
 * reutilise le Document/Document_Item natif GLPI attache directement a la
 * fiche Assetsign (cf. Assetsign::attachUploadedDocument()), jamais une nouvelle table
 * de stockage de fichiers.
 * Pas de front dedie, utilisee par Assetsign::createManual()/updateDonDetails()
 * et HandoverPdfBuilder.
 */
class DonDetails extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public static function createForAssetsign(int $assetsigns_id, string $organizationName): void {
       (new self())->add([
           'plugin_assetsign_assetsigns_id' => $assetsigns_id,
           'organization_name'        => $organizationName,
       ]);
   }

   public static function getForAssetsign(int $assetsigns_id): ?self {
       $details = new self();
       return $details->getFromDBByCrit(['plugin_assetsign_assetsigns_id' => $assetsigns_id]) ? $details : null;
   }

    /**
     * Cree la ligne si elle n'existe pas encore (cas d'un Don declenche
     * automatiquement par changement d'Etat, cf. Assetsign::handleStateBasedTrigger()
     * — aucun organisme connu au moment de la creation), sinon la met a jour.
     */
   public static function upsertForAssetsign(int $assetsigns_id, string $organizationName): void {
       $existing = self::getForAssetsign($assetsigns_id);
      if ($existing !== null) {
          $existing->update(['id' => $existing->getID(), 'organization_name' => $organizationName]);
          return;
      }
       self::createForAssetsign($assetsigns_id, $organizationName);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `organization_name` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_assetsigns_id`),
                CONSTRAINT `fk_dd_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
