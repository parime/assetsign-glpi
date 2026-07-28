<?php

namespace GlpiPlugin\Remise;

use Migration;

/**
 * Declaration des droits du plugin dans la matrice de profils GLPI standard
 * (Administration > Profils) : rien de reinvente, ce sont des lignes
 * supplementaires dans la table native glpi_profilerights.
 */
class Profile
{
    public const RIGHT_REMISE   = 'plugin_remise_remise';
    public const RIGHT_CONFIG   = 'plugin_remise_config';
    public const RIGHT_TEMPLATE = 'plugin_remise_template';

    private const ALL_RIGHTS = [self::RIGHT_REMISE, self::RIGHT_CONFIG, self::RIGHT_TEMPLATE];

    public static function install(Migration $migration): void
    {
        global $DB;

        \ProfileRight::addProfileRights(self::ALL_RIGHTS);

        // Octroie tous les droits standards au profil "Super-Admin" par defaut,
        // pour que le plugin soit immediatement utilisable apres installation.
        $rows = $DB->request(['FROM' => \Profile::getTable(), 'WHERE' => ['name' => 'Super-Admin']]);
        foreach ($rows as $row) {
            \ProfileRight::updateProfileRights((int) $row['id'], array_fill_keys(self::ALL_RIGHTS, ALLSTANDARDRIGHT));
        }
    }

    public static function uninstall(): void
    {
        \ProfileRight::deleteProfileRights(self::ALL_RIGHTS);
    }
}
