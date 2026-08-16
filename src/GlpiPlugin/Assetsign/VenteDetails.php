<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Details propres au type Vente (prix, date de vente) : table dediee 1-vers-1
 * avec Assetsign, exactement le point d'extension prevu en Phase 1 pour un futur
 * type qui aurait besoin de champs specifiques (cf. Workflow\WorkflowTypeInterface).
 * Vendeur et acheteur ne sont PAS ici : ce sont deja users_id_tech/users_id sur
 * Assetsign elle-meme, aucun besoin de les dupliquer.
 * Pas de front dedie, utilisee par Assetsign::createManual() et HandoverPdfBuilder.
 */
class VenteDetails extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public static function createForAssetsign(int $assetsigns_id, float $price, string $saleDate): void {
       (new self())->add([
           'plugin_assetsign_assetsigns_id' => $assetsigns_id,
           'price'                    => $price,
           'sale_date'                => $saleDate,
       ]);
   }

   public static function getForAssetsign(int $assetsigns_id): ?self {
       $details = new self();
       return $details->getFromDBByCrit(['plugin_assetsign_assetsigns_id' => $assetsigns_id]) ? $details : null;
   }

    /**
     * Cree la ligne si elle n'existe pas encore (cas d'une Vente declenchee
     * automatiquement par changement d'Etat, cf. Assetsign::handleStateBasedTrigger()
     * — aucun prix connu au moment de la creation), sinon la met a jour.
     */
   public static function upsertForAssetsign(int $assetsigns_id, float $price, string $saleDate): void {
       $existing = self::getForAssetsign($assetsigns_id);
      if ($existing !== null) {
          $existing->update(['id' => $existing->getID(), 'price' => $price, 'sale_date' => $saleDate]);
          return;
      }
       self::createForAssetsign($assetsigns_id, $price, $saleDate);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `price` decimal(10,2) NOT NULL DEFAULT 0.00,
                `sale_date` date DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_assetsigns_id`),
                CONSTRAINT `fk_vd_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
