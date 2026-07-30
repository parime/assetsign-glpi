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
    public const RIGHT_REMISE      = 'plugin_remise_remise';
    public const RIGHT_CONFIG      = 'plugin_remise_config';
    public const RIGHT_TEMPLATE    = 'plugin_remise_template';
    // Droit dedie (pas RIGHT_REMISE) : la fiche de maintenance est un sous-systeme
    // structurellement separe du moteur de fiches signees (pas de bureaucratie
    // partagee, cycle de vie totalement different — cf. Maintenance.php), une
    // organisation peut vouloir l'ouvrir a des techniciens qui n'ont pas acces
    // aux remises/signatures.
    public const RIGHT_MAINTENANCE = 'plugin_remise_maintenance';

    private const ALL_RIGHTS = [self::RIGHT_REMISE, self::RIGHT_CONFIG, self::RIGHT_TEMPLATE, self::RIGHT_MAINTENANCE];

    public static function install(Migration $migration): void
    {
        global $DB;

        // \ProfileRight::addProfileRights() (coeur GLPI) fait un INSERT sans
        // verification d'existence prealable pour CHAQUE profil : correct la
        // toute premiere fois, mais install() est aussi rejoue a chaque montee
        // de version du plugin (cf. Plugin::checkPluginState(), qui marque le
        // plugin NOTUPDATED des que le numero de version change, puis relance
        // son install() au clic sur "Mettre a jour"). Un second appel a
        // addProfileRights() y echoue alors avec une erreur fatale ("Duplicate
        // entry ... for key 'unicity'"), constate en conditions reelles en
        // testant une montee de version. On reconstruit donc ici la meme
        // logique (un droit par profil) mais en ne creant que les couples
        // profil/droit manquants — ce qui couvre aussi le cas d'un nouveau
        // profil cree apres la premiere installation.
        $existing = [];
        foreach ($DB->request(['FROM' => \ProfileRight::getTable(), 'WHERE' => ['name' => self::ALL_RIGHTS]]) as $row) {
            $existing[$row['profiles_id'] . '|' . $row['name']] = true;
        }

        foreach ($DB->request(['FROM' => \Profile::getTable()]) as $profile) {
            foreach (self::ALL_RIGHTS as $right) {
                if (!isset($existing[$profile['id'] . '|' . $right])) {
                    $DB->insert(\ProfileRight::getTable(), [
                        'profiles_id' => $profile['id'],
                        'name'        => $right,
                    ]);
                }
            }
        }

        // Octroie tous les droits standards au profil "Super-Admin" par defaut,
        // pour que le plugin soit immediatement utilisable apres installation.
        // updateProfileRights() est deja idempotent (verifie l'existence avant
        // d'inserer/mettre a jour), contrairement a addProfileRights() ci-dessus.
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
