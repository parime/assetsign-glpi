<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Journal des echecs de creation automatique d'une fiche de remise : soit
 * createAssetsign() echouant a l'insertion en base (rare, ex. probleme DB), soit
 * une exception levee pendant launchWorkflow() (generation PDF, jeton,
 * notification) et interceptee par le garde-fou de hook.php
 * (plugin_assetsign_item_assignment()) — ces echecs sont deja isoles pour ne
 * JAMAIS faire planter la sauvegarde du materiel lui-meme (cf.
 * TROUBLESHOOTING.md), mais restaient jusqu'ici invisibles sans consulter
 * files/_log/assetsign.log a la main : aucune trace en base, donc aucun moyen de
 * les compter/afficher. Compte affiche sur une carte dediee du tableau de
 * bord (cf. Dashboard\CardProvider::failures()) plutot qu'une notification
 * e-mail, a dessein (demande explicite) — un echec de CE mecanisme est
 * justement le moment ou compter sur un e-mail serait le moins fiable.
 */
class CreationFailure extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

    /**
     * Fenetre glissante prise en compte par countRecent() (carte de tableau de
     * bord) : sans elle, un total cumule depuis toujours deviendrait illisible
     * ("47 echecs" - remonte a quand ?) sans qu'aucun mecanisme de purge
     * n'existe pour cette table (contrairement a Token::CLEANUP_RETENTION_DAYS,
     * volontairement PAS reproduit ici pour rester un simple ajout au tableau
     * de bord, sans nouvelle tache cron - demande explicite). Les lignes plus
     * anciennes restent en base (utile pour un audit ponctuel via SQL direct),
     * seul le compte affiche est filtre.
     */
   public const RECENT_WINDOW_DAYS = 30;

    /** @param int|null $assetsignType Assetsign::TYPE_* attendu, ou null si inconnu a ce stade (ex: exception generique interceptee dans hook.php). */
   public static function record(string $itemtype, int $items_id, int $entities_id, ?int $assetsignType, string $reason): void {
       global $DB;
       $DB->insert(self::getTable(), [
           'entities_id'  => $entities_id,
           'itemtype'     => $itemtype,
           'items_id'     => $items_id,
           'assetsign_type'  => $assetsignType,
           'reason'       => $reason,
       ]);
   }

   public static function countRecent(): int {
       global $DB;

       $count = $DB->request([
           'COUNT' => 'cpt',
           'FROM'  => self::getTable(),
           'WHERE' => ['date_creation' => ['>', date('Y-m-d H:i:s', strtotime('-' . self::RECENT_WINDOW_DAYS . ' days'))]],
       ])->current()['cpt'];

       return (int) $count;
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL,
                `assetsign_type` tinyint unsigned DEFAULT NULL,
                `reason` text,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
