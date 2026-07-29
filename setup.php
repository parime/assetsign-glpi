<?php

/**
 * Plugin remise - Gestion des remises de materiel avec signature electronique
 *
 * Declaration du plugin aupres de GLPI : version, prerequis, hooks.
 * L'installation/desinstallation et les fonctions procedurales de hook
 * vivent dans hook.php ; la logique metier vit dans src/GlpiPlugin/Remise/.
 */

use Glpi\Plugin\Hooks;

// GLPI n'autoload que ses propres dependances : chaque plugin doit charger
// les siennes lui-meme (ici Dompdf, installe via `composer install` dans ce dossier).
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

define('PLUGIN_REMISE_VERSION', '1.0.3');
define('PLUGIN_REMISE_MIN_GLPI', '11.0.0');
define('PLUGIN_REMISE_MAX_GLPI', '11.9.99');
define('PLUGIN_REMISE_MIN_PHP', '8.3.0');

// Types d'actifs geres par defaut (surchargeable via la configuration)
const PLUGIN_REMISE_DEFAULT_ITEMTYPES = ['Computer', 'Monitor', 'Peripheral', 'Phone'];

function plugin_init_remise(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['remise'] = true;

    // --- Declenchement du workflow : affectation / restitution / echange -------------
    // Types geres en dur (Computer, Monitor, Peripheral, Phone) + tous les actifs
    // personnalises actifs a cet instant (Glpi\Asset\AssetDefinitionManager) : comme
    // plugin_init_remise() est rejoue a chaque requete, un actif personnalise cree ou
    // active apres coup est pris en compte au prochain chargement de page, sans avoir
    // a modifier ni reinstaller le plugin. Voir Config::getAllManageableItemtypes().
    $manageableItemtypes = \GlpiPlugin\Remise\Config::getAllManageableItemtypes();

    $itemHooks = array_fill_keys($manageableItemtypes, 'plugin_remise_item_assignment');
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['remise']    = $itemHooks;
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['remise'] = $itemHooks;

    $purgeHooks = array_fill_keys($manageableItemtypes, 'plugin_remise_item_pre_purge');
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['remise'] = $purgeHooks;

    $PLUGIN_HOOKS['use_massive_action']['remise'] = true;

    // --- Accès direct à la configuration depuis Configuration > Plugins --------------
    // Rend le nom du plugin cliquable dans la liste des plugins (comme les plugins
    // officiels), lien direct vers la configuration sans passer par le menu Administration.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['remise'] = 'front/config.php';

    // --- Accès à la page de signature -----------------------------------------------
    // Connexion GLPI obligatoire (choix du projet) : c'est deja le comportement PAR
    // DEFAUT du pare-feu GLPI 11 (Glpi\Http\Firewall) pour tout script legacy
    // front/*.php, y compris pour les plugins. La ligne ci-dessous est volontairement
    // explicite (STRATEGY_AUTHENTICATED) pour documenter que c'est un choix assume et
    // non un oubli — la seule fois ou l'on aurait autrement besoin de la declarer
    // c'est pour l'inverse (STRATEGY_NO_CHECK), qui n'est plus utilise ici.
    // Le controle d'identite fin (seul le beneficiaire peut voir/signer SON document,
    // pas n'importe quel utilisateur connecte) est fait dans Api\SignController.
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'remise',
        '#^/front/sign\.php#',
        \Glpi\Http\Firewall::STRATEGY_AUTHENTICATED
    );

    // --- Enregistrement des classes -------------------------------------------------
    Plugin::registerClass(\GlpiPlugin\Remise\Remise::class, [
        'addtabon' => $manageableItemtypes,
    ]);
    Plugin::registerClass(\GlpiPlugin\Remise\Config::class, [
        'addtabon' => ['Entity'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Remise\Template::class);
    // Accessory est un CommonDropdown standard : aucun attribut de registerClass
    // requis, son comportement de liste deroulante vient de sa classe parente.
    Plugin::registerClass(\GlpiPlugin\Remise\Accessory::class);

    // --- Notifications ------------------------------------------------------------
    // Rien a enregistrer explicitement : pour un itemtype namespace (GlpiPlugin\Remise\Remise),
    // GLPI resout automatiquement la classe cible par convention de nom
    // (NotificationTarget::getInstanceClass) vers GlpiPlugin\Remise\NotificationTargetRemise.

    // --- Widgets de tableau de bord --------------------------------------------------
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['remise'] = 'plugin_remise_dashboard_cards';

    // --- Intitulés (Configuration > Intitulés) ----------------------------------------
    // Gabarits de remise est une liste deroulante classique : au lieu de l'ajouter au
    // menu Administration, on l'enregistre ici pour qu'elle apparaisse dans la page
    // Intitulés comme n'importe quelle liste deroulante de GLPI (voir
    // Plugin::getDropdowns(), qui appelle automatiquement plugin_remise_getDropdown()
    // pour chaque plugin actif — aucune entree $PLUGIN_HOOKS n'est necessaire pour ce
    // hook "auto", le nom de fonction est resolu par convention).

    // --- Menu d'administration --------------------------------------------------------
    // Aucune entree de menu laterale pour ce plugin : chaque destination a deja un
    // acces plus direct et plus decouvrable —
    //   - Remise (liste des feuilles de remise) : onglet sur chaque materiel geré
    //     (Computer/Monitor/Peripheral/Phone) + liens directs depuis les widgets de
    //     tableau de bord (Hooks::DASHBOARD_CARDS ci-dessus) ;
    //   - Config : icone "Configurer" + nom cliquable sur la ligne du plugin dans
    //     Configuration > Plugins (Hooks::CONFIG_PAGE plus haut) ;
    //   - Template : page Configuration > Intitulés (ci-dessus).
    // Pas d'entree Hooks::MENU_TOADD ⇒ le plugin n'ajoute plus rien a la barre laterale.

    if (Plugin::isPluginActive('remise')) {
        $PLUGIN_HOOKS['add_javascript']['remise'] = [];
        $PLUGIN_HOOKS['add_css']['remise']        = ['css/remise.css'];
    }
}

function plugin_version_remise(): array
{
    return [
        'name'           => 'Remise & Signature',
        'version'        => PLUGIN_REMISE_VERSION,
        'author'         => 'Vincent Guillotte',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://www.consertotech.pro',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_REMISE_MIN_GLPI,
                'max' => PLUGIN_REMISE_MAX_GLPI,
            ],
            'php' => [
                'min' => PLUGIN_REMISE_MIN_PHP,
            ],
        ],
    ];
}

function plugin_remise_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_REMISE_MIN_PHP, '<')) {
        echo 'Ce plugin necessite PHP ' . PLUGIN_REMISE_MIN_PHP . ' ou superieur.';
        return false;
    }

    if (!is_dir(__DIR__ . '/vendor')) {
        echo 'Dependances manquantes : executez "composer install" dans le dossier du plugin.';
        return false;
    }

    return true;
}

function plugin_remise_check_config(bool $verbose = false): bool
{
    if ($verbose) {
        echo __('Aucune verification de configuration bloquante.', 'remise');
    }
    return true;
}
