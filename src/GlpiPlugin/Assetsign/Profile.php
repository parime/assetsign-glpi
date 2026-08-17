<?php

namespace GlpiPlugin\Assetsign;

use Migration;

/**
 * Declaration des droits du plugin dans la matrice de profils GLPI standard
 * (Administration > Profils) : rien de reinvente, ce sont des lignes
 * supplementaires dans la table native glpi_profilerights.
 */
class Profile
{
   public const RIGHT_ASSETSIGN      = 'plugin_assetsign_assetsign';
   public const RIGHT_CONFIG      = 'plugin_assetsign_config';
   public const RIGHT_TEMPLATE    = 'plugin_assetsign_template';
    // Droit dedie (pas RIGHT_ASSETSIGN) : la fiche de maintenance est un sous-systeme
    // structurellement separe du moteur de fiches signees (pas de bureaucratie
    // partagee, cycle de vie totalement different — cf. Maintenance.php), une
    // organisation peut vouloir l'ouvrir a des techniciens qui n'ont pas acces
    // aux assetsigns/signatures.
   public const RIGHT_MAINTENANCE = 'plugin_assetsign_maintenance';

   private const ALL_RIGHTS = [self::RIGHT_ASSETSIGN, self::RIGHT_CONFIG, self::RIGHT_TEMPLATE, self::RIGHT_MAINTENANCE];

    /**
     * Droits operationnels (utilisation quotidienne : consulter/creer/modifier
     * des remises et des fiches de maintenance) accordes par defaut aux profils
     * "Admin" et "Technician" — ce sont eux qui traitent les remises/restitutions/
     * maintenances au quotidien, contrairement a RIGHT_CONFIG/RIGHT_TEMPLATE
     * (parametrage du plugin) qui restent reserves a Super-Admin. Sans ce
     * pre-reglage, un technicien fraichement installe n'a acces a AUCUNE
     * fonctionnalite du plugin (verifie en conditions reelles : rights=0 sur
     * les 4 droits pour tout profil autre que Super-Admin) et un Super-Admin
     * doit deviner qu'il faut aller les accorder a la main dans Administration
     * > Profils avant que qui que ce soit d'autre ne puisse s'en servir.
     * READ+CREATE+UPDATE (pas DELETE/PURGE, plus prudent par defaut — la
     * suppression d'une fiche signee reste une action reservee a Super-Admin).
     */
   private const DEFAULT_OPERATIONAL_PROFILES = ['Admin', 'Technician'];
   private const DEFAULT_OPERATIONAL_RIGHTS   = [self::RIGHT_ASSETSIGN, self::RIGHT_MAINTENANCE];

   public static function install(Migration $migration): void {
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

       $operationalProfileNames = [];
      foreach ($DB->request(['FROM' => \Profile::getTable(), 'WHERE' => ['name' => self::DEFAULT_OPERATIONAL_PROFILES]]) as $profile) {
          $operationalProfileNames[(int) $profile['id']] = true;
      }

      foreach ($DB->request(['FROM' => \Profile::getTable()]) as $profile) {
         foreach (self::ALL_RIGHTS as $right) {
            if (!isset($existing[$profile['id'] . '|' . $right])) {
                // Uniquement a la creation de la ligne (jamais reecrit ensuite,
                // meme lors d'une montee de version) : un admin qui revoque ce
                // droit par la suite voit son choix respecte, pas ecrase au
                // prochain "Mettre a jour" du plugin.
                $isOperational = isset($operationalProfileNames[(int) $profile['id']])
                    && in_array($right, self::DEFAULT_OPERATIONAL_RIGHTS, true);

               $DB->insert(\ProfileRight::getTable(), [
                  'profiles_id' => $profile['id'],
                  'name'        => $right,
                  'rights'      => $isOperational ? (READ | CREATE | UPDATE) : 0,
               ]);
            }
         }
      }

       // Octroie tous les droits standards au profil "Super-Admin" par defaut,
       // pour que le plugin soit immediatement utilisable apres installation.
       // updateProfileRights() est deja idempotent (verifie l'existence avant
       // d'inserer/mettre a jour), contrairement a addProfileRights() ci-dessus.
       // Volontairement rejoue a chaque montee de version (contrairement au
       // pre-reglage Admin/Technician ci-dessus) : Super-Admin doit toujours
       // pouvoir tout faire, c'est le filet de securite du plugin.
       $rows = $DB->request(['FROM' => \Profile::getTable(), 'WHERE' => ['name' => 'Super-Admin']]);
      foreach ($rows as $row) {
          \ProfileRight::updateProfileRights((int) $row['id'], array_fill_keys(self::ALL_RIGHTS, ALLSTANDARDRIGHT));
      }
   }

   public static function uninstall(): void {
       \ProfileRight::deleteProfileRights(self::ALL_RIGHTS);
   }
}
