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

define('PLUGIN_REMISE_VERSION', '1.9.0');
define('PLUGIN_REMISE_MIN_GLPI', '11.0.0');
define('PLUGIN_REMISE_MAX_GLPI', '11.9.99');
define('PLUGIN_REMISE_MIN_PHP', '8.3.0');

// Types d'actifs geres par defaut (surchargeable via la configuration)
const PLUGIN_REMISE_DEFAULT_ITEMTYPES = ['Computer', 'Monitor', 'Peripheral', 'Phone'];

function plugin_init_remise(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['remise'] = true;

    // --- Types de fiche geres par le plugin ------------------------------------------
    // Enregistres a chaque requete (comme le reste de cette fonction) : ajouter un
    // futur type (Don, Vente...) se fera ici, sans toucher a Remise.php/Template.php/
    // HandoverPdfBuilder qui consultent tous le registre (voir
    // GlpiPlugin\Remise\Workflow\WorkflowTypeRegistry).
    \GlpiPlugin\Remise\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Remise\Workflow\HandoverType());
    \GlpiPlugin\Remise\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Remise\Workflow\ReturnType());
    \GlpiPlugin\Remise\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Remise\Workflow\DonType());
    \GlpiPlugin\Remise\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Remise\Workflow\VenteType());

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

    // --- Vidage automatique d'OPcache apres mise a jour --------------------------------
    // front/opcache_reset.php (appele automatiquement par plugin_remise_install(),
    // cf. hook.php) restreint lui-meme l'acces a 127.0.0.1/::1 : aucune session
    // GLPI n'existe a ce moment (script CLI), STRATEGY_NO_CHECK est donc necessaire
    // ici — le controle de securite reste fait par le endpoint lui-meme.
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'remise',
        '#^/front/opcache_reset\.php#',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK
    );

    // --- Enregistrement des classes -------------------------------------------------
    Plugin::registerClass(\GlpiPlugin\Remise\Remise::class, [
        'addtabon' => $manageableItemtypes,
    ]);
    Plugin::registerClass(\GlpiPlugin\Remise\Config::class, [
        'addtabon' => ['Entity'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Remise\Template::class);
    // Accessory/MaintenanceChecklistItem sont des CommonDropdown standards :
    // aucun attribut de registerClass requis, leur comportement de liste
    // deroulante vient de leur classe parente.
    Plugin::registerClass(\GlpiPlugin\Remise\Accessory::class);
    Plugin::registerClass(\GlpiPlugin\Remise\MaintenanceChecklistItem::class);
    // Maintenance : sous-systeme volontairement separe du moteur Remise (cf.
    // Maintenance.php) — possede neanmoins son propre onglet sur les memes
    // materiels geres, comme Remise, pour rester decouvrable depuis la fiche
    // du materiel en plus de son propre menu (ci-dessous).
    Plugin::registerClass(\GlpiPlugin\Remise\Maintenance::class, [
        'addtabon' => $manageableItemtypes,
    ]);

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
    // "Gestion des fiches" (Search::show(Remise::class), cf. front/remise.php) : vue
    // transverse de toutes les remises/restitutions, tous materiels et beneficiaires
    // confondus, avec telechargement direct des PDF et annulation (cf. Remise::
    // rawSearchOptions()/cancelRequest()) — un onglet par materiel ne suffit plus des
    // qu'il faut suivre l'ensemble des fiches en attente sans savoir a l'avance sur
    // quel materiel chercher.
    // "Fiches de maintenance" (Search::show(Maintenance::class), cf. front/maintenance.php) :
    // volontairement une entree de menu SEPAREE de "Gestion des fiches" (pas fusionnee),
    // le sous-systeme de maintenance etant lui-meme structurellement independant du
    // moteur de fiches signees (cf. Maintenance.php) — un tableau melant les deux
    // brouillerait deux notions de statut/cycle de vie totalement differentes.
    //   - Config : icone "Configurer" + nom cliquable sur la ligne du plugin dans
    //     Configuration > Plugins (Hooks::CONFIG_PAGE plus haut) ;
    //   - Template/Points de controle de maintenance : page Configuration > Intitulés (ci-dessus).
    // Format attendu par le coeur GLPI : une LISTE PLATE de classes par categorie
    // (pas le format documente ['types'=>[...],'icon'=>'...']) — cf. README, section
    // Notes techniques, piege deja rencontre et documente.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['remise'] = [
        'tools' => [\GlpiPlugin\Remise\Remise::class, \GlpiPlugin\Remise\Maintenance::class],
    ];

    if (Plugin::isPluginActive('remise')) {
        $PLUGIN_HOOKS['add_css']['remise'] = ['css/remise.css'];
    }
}

function plugin_version_remise(): array {
    return [
        'name'           => 'Remise & Signature',
        'version'        => PLUGIN_REMISE_VERSION,
        'author'         => 'Vincent Guillotte',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://github.com/parime/remise-glpi',
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

function plugin_remise_check_prerequisites(): bool {
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

function plugin_remise_check_config(bool $verbose = false): bool {
   if ($verbose) {
       echo __('Aucune verification de configuration bloquante.', 'remise');
   }
    return true;
}
