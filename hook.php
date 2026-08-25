<?php

/**
 * Fonctions procedurales appelees directement par le coeur de GLPI.
 * Volontairement minces : elles delegue immediatement aux classes namespacees
 * dans src/GlpiPlugin/Assetsign/ qui portent la vraie logique metier.
 */

use Glpi\Cache\CacheManager;
use GlpiPlugin\Assetsign\Accessory;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignAccessory;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\CreationFailure;
use GlpiPlugin\Assetsign\DamageMarker;
use GlpiPlugin\Assetsign\Dashboard\CardProvider;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;
use GlpiPlugin\Assetsign\NotificationTargetAssetsign;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\Profile;
use GlpiPlugin\Assetsign\Reminder;
use GlpiPlugin\Assetsign\Signature;
use GlpiPlugin\Assetsign\Template;
use GlpiPlugin\Assetsign\Token;
use GlpiPlugin\Assetsign\VenteDetails;

// ----------------------------------------------------------------------------------
// Callbacks de hooks (item_add / item_update / pre_item_purge)
// ----------------------------------------------------------------------------------

/**
 * Un echec cote plugin (fournisseur de signature mal configure, generation PDF
 * qui plante, etc.) ne doit JAMAIS faire echouer l'operation GLPI sous-jacente :
 * ces fonctions sont appelees de maniere synchrone par CommonDBTM::add()/
 * update() (Plugin::doHook()), une exception non rattrapee ici remonterait
 * jusque dans la sauvegarde de l'item GLPI lui-meme (ex: un technicien qui
 * reaffecte un simple ordinateur se prendrait une erreur 500 sur SA sauvegarde
 * a cause d'un probleme qui ne concerne que le plugin).
 */
function plugin_assetsign_item_assignment(CommonDBTM $item): void {
   try {
       Assetsign::handleItemAssignment($item);
   } catch (\Throwable $e) {
       \Toolbox::logInFile(
           'assetsign',
           sprintf(
               "Echec du declenchement assetsign pour %s #%d : %s\n%s",
               $item->getType(),
               $item->getID(),
               $e->getMessage(),
               $e->getTraceAsString()
           ),
           true
       );
       // Type Assetsign attendu inconnu a ce niveau (l'exception peut venir de
       // n'importe quel point de handleItemAssignment()) : null, cf.
       // CreationFailure::record(). Le fichier de log ci-dessus reste la seule
       // source de la trace complete ; cette ligne ne sert qu'a rendre
       // l'echec COMPTABLE sur la carte de tableau de bord dediee.
       CreationFailure::record(
           $item->getType(),
           $item->getID(),
           (int) ($item->fields['entities_id'] ?? 0),
           null,
           $e->getMessage()
       );
   }
}

function plugin_assetsign_item_pre_purge(CommonDBTM $item): void {
   try {
       Assetsign::archiveForPurgedItem($item);
   } catch (\Throwable $e) {
       \Toolbox::logInFile(
           'assetsign',
           sprintf(
               "Echec de l'archivage des assetsigns pour %s #%d : %s\n%s",
               $item->getType(),
               $item->getID(),
               $e->getMessage(),
               $e->getTraceAsString()
           ),
           true
       );
   }
}

/**
 * Hooks::DASHBOARD_CARDS callback (registered in setup.php).
 *
 * Plugin::doHookFunction() chains every plugin registered on this hook through the same
 * accumulator ($ret = call_user_func($function, $ret) for each one in turn, never array_merge()-
 * ing the results itself), so a callback declared with no parameter and returning only its own
 * cards silently discards every other plugin's contribution whenever it runs later in the chain
 * (confirmed live on an instance running this plugin alongside glpi-vulnerability-manager, whose
 * own cards disappeared from the "Ajouter une carte" picker because of this exact bug on this
 * plugin's side, same root cause fixed there first). $cards has to accept null, not just default
 * to an empty array: Grid.php calls Plugin::doHookFunction(Hooks::DASHBOARD_CARDS) with no $parm,
 * so the very first plugin in the chain is invoked with an explicit null argument, which does not
 * fall through to a `array $cards = []` default (PHP only applies a default when the argument is
 * omitted, not when null is passed for a non-nullable type).
 */
function plugin_assetsign_dashboard_cards(?array $cards = null): array {
    $cards ??= [];
    $group = __('Assetsign & signature', 'assetsign');

    return $cards + [
        'assetsign_pending' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Attributions en attente de signature', 'assetsign'),
            'provider'   => CardProvider::class . '::pending',
            'filters'    => [],
        ],
        'assetsign_signed' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Attributions signées', 'assetsign'),
            'provider'   => CardProvider::class . '::signed',
            'filters'    => [],
        ],
        'assetsign_expired' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Attributions expirées', 'assetsign'),
            'provider'   => CardProvider::class . '::expired',
            'filters'    => [],
        ],
        'assetsign_creation_failures' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Échecs de création (30 derniers jours)', 'assetsign'),
            'provider'   => CardProvider::class . '::failures',
            'filters'    => [],
        ],
    ];
}

// ----------------------------------------------------------------------------------
// Intitulés (Configuration > Intitulés)
// ----------------------------------------------------------------------------------

/**
 * Appelee par Plugin::getDropdowns() (hook AUTO_GET_DROPDOWN) : ajoute Gabarits de
 * assetsign a la page Configuration > Intitulés plutot que dans le menu Administration,
 * comme n'importe quelle autre liste deroulante de GLPI.
 */
function plugin_assetsign_getDropdown(): array {
    return [
        Template::class                 => Template::getTypeName(2),
        Accessory::class                => Accessory::getTypeName(2),
        MaintenanceChecklistItem::class => MaintenanceChecklistItem::getTypeName(2),
    ];
}

// ----------------------------------------------------------------------------------
// Installation / desinstallation
// ----------------------------------------------------------------------------------

function plugin_assetsign_install(): bool {
    // Jeton partage pour front/opcache_reset.php (revue de securite marketplace GLPI, low,
    // #98) : la restriction par REMOTE_ADDR==127.0.0.1/::1 seule est contournable derriere un
    // reverse-proxy en mode loopback (REMOTE_ADDR vaut alors 127.0.0.1 pour tout le trafic
    // externe), permettant un appel repete non authentifie (petit deni de service CPU). Genere
    // une seule fois, persiste dans GLPI_PLUGIN_DOC_DIR (= GLPI_VAR_DIR/_plugins, constante GLPI
    // deja dediee au stockage de documents plugin, hors racine web) plutot que dans un fichier du
    // plugin lui-meme (qui pourrait etre ecrase par une future mise a jour du code). Lu a la fois
    // par ce endpoint (verification hash_equals()) et par update.sh (meme systeme de fichiers,
    // execute sur la meme machine).
    $opcacheTokenFile = GLPI_PLUGIN_DOC_DIR . '/assetsign_opcache_token';
    if (!is_file($opcacheTokenFile)) {
        @mkdir(dirname($opcacheTokenFile), 0700, true);
        file_put_contents($opcacheTokenFile, bin2hex(random_bytes(32)));
        @chmod($opcacheTokenFile, 0600);
    }

    $migration = new Migration(str_replace('.', '', PLUGIN_ASSETSIGN_VERSION));

    Config::install($migration);
    Template::install($migration);
    Accessory::install($migration);
    CreationFailure::install($migration);
    Assetsign::install($migration);
    AssetsignAccessory::install($migration);
    VenteDetails::install($migration);
    MaintenanceChecklistItem::install($migration);
    Maintenance::install($migration);
    // Apres Maintenance : la contrainte de cle etrangere ajoutee par
    // DamageMarker::install() vers glpi_plugin_assetsign_maintenances exige que
    // cette table existe deja (piege reel rencontre en testant - l'ALTER
    // TABLE echouait silencieusement, laissant la colonne
    // plugin_assetsign_maintenances_id absente sans qu'aucune erreur ne
    // remonte jusqu'a l'ecran d'installation).
    DamageMarker::install($migration);
    PassportEvent::install($migration);
    Token::install($migration);
    Signature::install($migration);
    Reminder::install($migration);
    Profile::install($migration);

    $migration->executeMigration();

    NotificationTargetAssetsign::install();

    CronTask::register(
        Assetsign::class,
        'assetsignReminders',
        HOUR_TIMESTAMP,
        [
            'comment' => 'Envoie les relances de signature dues (J+3, J+7, puis hebdomadaire)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        Assetsign::class,
        'assetsignExpire',
        DAY_TIMESTAMP,
        [
            'comment' => 'Marque comme expirees les attributions dont le delai de signature est depasse',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        Assetsign::class,
        'assetsignExpiryWarning',
        DAY_TIMESTAMP,
        [
            'comment' => 'Alerte le technicien des attributions sur le point d\'expirer (avant que ce soit trop tard pour agir)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        Token::class,
        'assetsignCleanupTokens',
        DAY_TIMESTAMP,
        [
            'comment' => 'Purge les tokens de signature invalides ou perimes depuis plus de ' . Token::CLEANUP_RETENTION_DAYS . ' jours',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        PassportEvent::class,
        'passportAnonymize',
        DAY_TIMESTAMP,
        [
            'comment' => 'Anonymise l\'identite des beneficiaires dans le Passeport materiel au-dela du delai configure (Config::passport_retention_years)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    // Vide le cache des gabarits Twig compiles (files/_cache/.../templates/) a
    // chaque installation/mise a jour du plugin : sans ca, un fichier .twig
    // modifie continue silencieusement de servir son ancienne version compilee
    // en production (Glpi\Application\Environment::shouldExpectResourcesToChange()
    // renvoie false), meme apres un `git pull` reussi et malgre le bon numero de
    // version sur disque. Bug reel constate : plugin:install --force + cache:clear
    // manuel avaient tous les deux ete faits mais dans un contexte ou "manuel"
    // signifiait "oublie/mal cible" — l'automatiser ici retire ce risque humain.
    (new CacheManager())->resetAllCaches();

    return true;
}

function plugin_assetsign_uninstall(): bool {
    $migration = new Migration(str_replace('.', '', PLUGIN_ASSETSIGN_VERSION));

   foreach ([
        'glpi_plugin_assetsign_events',
        'glpi_plugin_assetsign_reminders',
        'glpi_plugin_assetsign_signatures',
        'glpi_plugin_assetsign_tokens',
        'glpi_plugin_assetsign_assetsignaccessories',
        'glpi_plugin_assetsign_ventedetails',
        'glpi_plugin_assetsign_damagemarkers',
        'glpi_plugin_assetsign_assetsigns',
        'glpi_plugin_assetsign_maintenancechecklistvalues',
        'glpi_plugin_assetsign_maintenances',
        'glpi_plugin_assetsign_maintenancechecklistitems',
        'glpi_plugin_assetsign_accessories',
        'glpi_plugin_assetsign_templates',
        'glpi_plugin_assetsign_configs',
        'glpi_plugin_assetsign_creationfailures',
    ] as $table) {
       $migration->dropTable($table);
   }

    Profile::uninstall();
    NotificationTargetAssetsign::uninstall();

    // Retire toutes les taches planifiees enregistrees par ce plugin
    CronTask::Unregister('assetsign');

    return true;
}
