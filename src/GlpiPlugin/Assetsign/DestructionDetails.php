<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Details propres au type Destruction (prestataire) : table dediee 1-vers-1
 * avec Assetsign, meme motif exact que VenteDetails/DonDetails (issue #78,
 * "fin de vie structuree"). Le certificat de destruction lui-meme (fichier)
 * n'est PAS stocke ici : il reutilise le Document/Document_Item natif GLPI
 * attache directement a la fiche Assetsign (cf. Assetsign::attachUploadedDocument()),
 * jamais une nouvelle table de stockage de fichiers.
 * Pas de front dedie, utilisee par Assetsign::createManual()/
 * updateDestructionDetails() et HandoverPdfBuilder.
 */
class DestructionDetails extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public static function createForAssetsign(int $assetsigns_id, string $providerName): void {
       (new self())->add([
           'plugin_assetsign_assetsigns_id' => $assetsigns_id,
           'provider_name'            => $providerName,
       ]);
   }

   public static function getForAssetsign(int $assetsigns_id): ?self {
       $details = new self();
       return $details->getFromDBByCrit(['plugin_assetsign_assetsigns_id' => $assetsigns_id]) ? $details : null;
   }

    /**
     * Cree la ligne si elle n'existe pas encore (cas d'une Destruction declenchee
     * automatiquement par changement d'Etat, cf. Assetsign::handleStateBasedTrigger()
     * — aucun prestataire connu au moment de la creation), sinon la met a jour.
     */
   public static function upsertForAssetsign(int $assetsigns_id, string $providerName): void {
       $existing = self::getForAssetsign($assetsigns_id);
      if ($existing !== null) {
          $existing->update(['id' => $existing->getID(), 'provider_name' => $providerName]);
          return;
      }
       self::createForAssetsign($assetsigns_id, $providerName);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `provider_name` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_assetsigns_id`),
                CONSTRAINT `fk_destrd_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
