<?php

namespace GlpiPlugin\Remise\Dashboard;

use GlpiPlugin\Remise\Remise;

/**
 * Fonctions "provider" des cartes de tableau de bord du plugin (widgets
 * "bigNumber"), enregistrees via le hook Hooks::DASHBOARD_CARDS (cf. setup.php).
 * Chaque methode suit le contrat attendu par Glpi\Dashboard\Provider::bigNumberItem() :
 * un tableau ['number' => int, 'url' => string, 'label' => string, 'icon' => string].
 */
final class CardProvider
{
    public static function pending(array $params = []): array
    {
        return self::countByStatus(Remise::STATUSES_AWAITING_SIGNATURE, 'En attente de signature', $params);
    }

    public static function signed(array $params = []): array
    {
        return self::countByStatus([Remise::STATUS_SIGNED], 'Signées', $params);
    }

    public static function expired(array $params = []): array
    {
        return self::countByStatus([Remise::STATUS_EXPIRED], 'Expirées', $params);
    }

    private static function countByStatus(array $statuses, string $label, array $params): array
    {
        global $DB;

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
            'url'    => '/plugins/remise/front/remise.php',
            'label'  => __($label, 'remise'),
            'icon'   => Remise::getIcon(),
        ];
    }
}
