<?php

namespace GlpiPlugin\Remise\Dashboard;

use GlpiPlugin\Remise\CreationFailure;
use GlpiPlugin\Remise\Remise;

/**
 * Fonctions "provider" des cartes de tableau de bord du plugin (widgets
 * "bigNumber"), enregistrees via le hook Hooks::DASHBOARD_CARDS (cf. setup.php).
 * Chaque methode suit le contrat attendu par Glpi\Dashboard\Provider::bigNumberItem() :
 * un tableau ['number' => int, 'url' => string, 'label' => string, 'icon' => string].
 */
final class CardProvider
{
   public static function pending(array $params = []): array {
       return self::countByStatus(Remise::STATUSES_AWAITING_SIGNATURE, 'En attente de signature', $params);
   }

   public static function signed(array $params = []): array {
       return self::countByStatus([Remise::STATUS_SIGNED], 'Signées', $params);
   }

   public static function expired(array $params = []): array {
       return self::countByStatus([Remise::STATUS_EXPIRED], 'Expirées', $params);
   }

    /**
     * Echecs de creation automatique (createRemise()/launchWorkflow()) des
     * self::RECENT_WINDOW_DAYS derniers jours - cf. CreationFailure, demande
     * explicite pour rendre visible un mecanisme jusqu'ici isole en silence
     * (par design, cf. TROUBLESHOOTING.md) pour ne jamais faire planter la
     * sauvegarde du materiel, mais du coup invisible sans consulter
     * files/_log/remise.log a la main.
     */
   public static function failures(array $params = []): array {
       global $DB, $CFG_GLPI;

       // Dernier argument false (contrairement a countByStatus() ci-dessous) :
       // cette table n'a pas de colonne is_recursive (ce n'est pas un item
       // GLPI a part entiere, juste un journal d'evenements) - true y ferait
       // echouer la requete en SQL invalide.
       $where = ['date_creation' => ['>', date('Y-m-d H:i:s', strtotime('-' . CreationFailure::RECENT_WINDOW_DAYS . ' days'))]]
           + getEntitiesRestrictCriteria(CreationFailure::getTable(), '', '', false);

       $count = (int) $DB->request([
           'COUNT' => 'cpt',
           'FROM'  => CreationFailure::getTable(),
           'WHERE' => $where,
       ])->current()['cpt'];

       return [
           'number' => $count,
           // Pas de liste dediee pour ces echecs (perimetre volontairement
           // limite a une carte de tableau de bord, cf. TROUBLESHOOTING.md) :
           // renvoie vers la configuration du plugin plutot que vers la liste
           // des remises, qui ne contient justement PAS ces echecs.
           'url'    => $CFG_GLPI['root_doc'] . '/plugins/remise/front/config.php',
           'label'  => __('Échecs de création (30 derniers jours)', 'remise'),
           'icon'   => 'ti ti-alert-triangle',
       ];
   }

   private static function countByStatus(array $statuses, string $label, array $params): array {
       global $DB, $CFG_GLPI;

       // Restreint aux entites actives de la session, exactement comme le fait
       // Glpi\Dashboard\Provider::bigNumberItem() pour tout itemtype "entity
       // assign" (cf. son test `$item->isEntityAssign()`). Sans ca, ces widgets
       // comptaient TOUJOURS toutes les remises de tout GLPI, quelle que soit
       // l'entite active choisie par l'utilisateur — incoherent avec le reste
       // des widgets du tableau de bord, et avec le travail d'heritage de
       // configuration par entite fait par ailleurs dans ce plugin.
       $where = ['status' => $statuses, 'is_deleted' => 0]
           + getEntitiesRestrictCriteria(Remise::getTable(), '', '', true);

       $count = (int) $DB->request([
           'COUNT' => 'cpt',
           'FROM'  => Remise::getTable(),
           'WHERE' => $where,
       ])->current()['cpt'];

       return [
           'number' => $count,
           'url'    => $CFG_GLPI['root_doc'] . '/plugins/remise/front/remise.php',
           'label'  => __($label, 'remise'),
           'icon'   => Remise::getIcon(),
       ];
   }
}
