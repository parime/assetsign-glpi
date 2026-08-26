<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Saisie manuelle de la valeur résiduelle d'un matériel (V2, cf. ROADMAP.md —
 * "Valeur résiduelle (linéaire / durée personnalisable / saisie manuelle)",
 * issue #77) : table dédiée 1-vers-1 avec N'IMPORTE QUEL matériel géré par le
 * plugin (`itemtype`/`items_id`, comme `Movement` — PAS comme `VenteDetails`,
 * qui référence une `Assetsign`), parce qu'un type natif GLPI (Computer,
 * Monitor...) ne peut pas recevoir de colonne supplémentaire. Une saisie
 * manuelle présente l'emporte TOUJOURS sur le calcul automatique
 * (PassportEvent::getResidualValue()) — jamais un simple repli en cas
 * d'échec du calcul, cf. docs/design/ADR-passeport-v1.md section 2.5 (même
 * principe déjà tranché pour le passeport environnemental V3 : la saisie
 * manuelle reste un vrai choix, pas un dégradé).
 * Pas de front dédié : Api\ResidualValueFormController (front/residualvalue.form.php).
 */
class ResidualValue extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public static function getForItem(string $itemtype, int $items_id): ?self {
       $residual = new self();
       return $residual->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $items_id]) ? $residual : null;
   }

    /**
     * Crée ou met à jour la ligne pour ce matériel. `$manualValue` à null
     * repasse le matériel en calcul automatique (jamais une suppression de
     * ligne : conserver la ligne, même vide, évite de recréer un id à chaque
     * aller-retour manuel/automatique — sans conséquence, `getForItem()` ne
     * lit que `manual_value`).
     */
   public static function upsertForItem(string $itemtype, int $items_id, ?float $manualValue): void {
       $existing = self::getForItem($itemtype, $items_id);
      if ($existing !== null) {
          $existing->update(['id' => $existing->getID(), 'manual_value' => $manualValue]);
          return;
      }
      if ($manualValue === null) {
          return; // Rien a effacer, rien a creer : deja en calcul automatique.
      }
       (new self())->add([
           'itemtype'     => $itemtype,
           'items_id'     => $items_id,
           'manual_value' => $manualValue,
       ]);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `manual_value` decimal(10,2) DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
