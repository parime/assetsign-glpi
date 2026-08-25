<?php

/**
 * Plugin assetsign - Gestion des assetsigns de materiel avec signature electronique
 *
 * Declaration du plugin aupres de GLPI : version, prerequis, hooks.
 * L'installation/desinstallation et les fonctions procedurales de hook
 * vivent dans hook.php ; la logique metier vit dans src/GlpiPlugin/Assetsign/.
 */

use Glpi\Plugin\Hooks;

// GLPI n'autoload que ses propres dependances : chaque plugin doit charger
// les siennes lui-meme (ici Dompdf, installe via `composer install` dans ce dossier).
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

define('PLUGIN_ASSETSIGN_VERSION', '2.3.1');
define('PLUGIN_ASSETSIGN_MIN_GLPI', '11.0.0');
define('PLUGIN_ASSETSIGN_MAX_GLPI', '11.9.99');
define('PLUGIN_ASSETSIGN_MIN_PHP', '8.3.0');

// Types d'actifs geres par defaut (surchargeable via la configuration)
const PLUGIN_ASSETSIGN_DEFAULT_ITEMTYPES = ['Computer', 'Monitor', 'Peripheral', 'Phone'];

// Seuils "degradation complete" (100%) du score de sante (cf. ROADMAP.md,
// PassportEvent::getHealthScore()) - fixes pour l'instant, seul le POIDS de
// chaque facteur est ajustable par l'administrateur (Configuration >
// Passeport materiel). Valeurs de depart raisonnables, documentees et
// modifiables par une future PR si l'usage reel montre qu'elles sont trop
// larges/etroites - pas une science exacte, cf. sources en fin de ROADMAP.md.
const PLUGIN_ASSETSIGN_HEALTH_AGE_FULL_DEGRADATION_DAYS = 1826; // 5 ans
const PLUGIN_ASSETSIGN_HEALTH_INCIDENTS_FULL_DEGRADATION_COUNT = 5; // 5 tickets lies
const PLUGIN_ASSETSIGN_HEALTH_DAMAGE_FULL_DEGRADATION_POINTS = 6; // 6 points (1=mineur, 2=majeur)
const PLUGIN_ASSETSIGN_HEALTH_MOVEMENTS_FULL_DEGRADATION_COUNT = 5; // 5 "vies" (changements de detenteur)

function plugin_init_assetsign(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['assetsign'] = true;

    // --- Types de fiche geres par le plugin ------------------------------------------
    // Enregistres a chaque requete (comme le reste de cette fonction) : ajouter un
    // futur type (Don, Vente...) se fera ici, sans toucher a Assetsign.php/Template.php/
    // HandoverPdfBuilder qui consultent tous le registre (voir
    // GlpiPlugin\Assetsign\Workflow\WorkflowTypeRegistry).
    \GlpiPlugin\Assetsign\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Assetsign\Workflow\HandoverType());
    \GlpiPlugin\Assetsign\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Assetsign\Workflow\ReturnType());
    \GlpiPlugin\Assetsign\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Assetsign\Workflow\DonType());
    \GlpiPlugin\Assetsign\Workflow\WorkflowTypeRegistry::register(new \GlpiPlugin\Assetsign\Workflow\VenteType());

    // --- Declenchement du workflow : affectation / restitution / echange -------------
    // Types geres en dur (Computer, Monitor, Peripheral, Phone) + tous les actifs
    // personnalises actifs a cet instant (Glpi\Asset\AssetDefinitionManager) : comme
    // plugin_init_assetsign() est rejoue a chaque requete, un actif personnalise cree ou
    // active apres coup est pris en compte au prochain chargement de page, sans avoir
    // a modifier ni reinstaller le plugin. Voir Config::getAllManageableItemtypes().
    $manageableItemtypes = \GlpiPlugin\Assetsign\Config::getAllManageableItemtypes();

    $itemHooks = array_fill_keys($manageableItemtypes, 'plugin_assetsign_item_assignment');
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['assetsign']    = $itemHooks;
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['assetsign'] = $itemHooks;

    $purgeHooks = array_fill_keys($manageableItemtypes, 'plugin_assetsign_item_pre_purge');
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['assetsign'] = $purgeHooks;

    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['assetsign'] = true;

    // --- Accès direct à la configuration depuis Configuration > Plugins --------------
    // Rend le nom du plugin cliquable dans la liste des plugins (comme les plugins
    // officiels), lien direct vers la configuration sans passer par le menu Administration.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['assetsign'] = 'front/config.php';

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
        'assetsign',
        '#^/front/sign\.php#',
        \Glpi\Http\Firewall::STRATEGY_AUTHENTICATED
    );

    // --- Vidage automatique d'OPcache apres mise a jour --------------------------------
    // front/opcache_reset.php (appele automatiquement par plugin_assetsign_install(),
    // cf. hook.php) restreint lui-meme l'acces a 127.0.0.1/::1 : aucune session
    // GLPI n'existe a ce moment (script CLI), STRATEGY_NO_CHECK est donc necessaire
    // ici — le controle de securite reste fait par le endpoint lui-meme.
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'assetsign',
        '#^/front/opcache_reset\.php#',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK
    );

    // --- Enregistrement des classes -------------------------------------------------
    // 'User' en plus des materiels geres : onglet Assetsigns cote beneficiaire
    // (Assetsign::getTabNameForItem()/showForUser() filtrent alors par users_id
    // plutot que par itemtype/items_id) - retrouver d'un coup d'œil tout ce
    // qu'une personne a recu, sans avoir a savoir sur quel materiel chercher.
    Plugin::registerClass(\GlpiPlugin\Assetsign\Assetsign::class, [
        'addtabon' => array_merge($manageableItemtypes, ['User']),
    ]);
    Plugin::registerClass(\GlpiPlugin\Assetsign\Config::class, [
        'addtabon' => ['Entity'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Assetsign\Template::class);
    // Accessory/MaintenanceChecklistItem sont des CommonDropdown standards :
    // aucun attribut de registerClass requis, leur comportement de liste
    // deroulante vient de leur classe parente.
    Plugin::registerClass(\GlpiPlugin\Assetsign\Accessory::class);
    Plugin::registerClass(\GlpiPlugin\Assetsign\MaintenanceChecklistItem::class);
    // Maintenance : sous-systeme volontairement separe du moteur Assetsign (cf.
    // Maintenance.php) — possede neanmoins son propre onglet sur les memes
    // materiels geres, comme Assetsign, pour rester decouvrable depuis la fiche
    // du materiel en plus de son propre menu (ci-dessous).
    Plugin::registerClass(\GlpiPlugin\Assetsign\Maintenance::class, [
        'addtabon' => $manageableItemtypes,
    ]);
    // Passeport materiel/utilisateur : agrege Assetsign + Maintenance en lecture
    // seule (cf. PassportEvent::recordForAssetsign()/recordForMaintenance()),
    // avec deux onglets distincts sur les memes points d'accroche que Assetsign
    // ci-dessus — sur le materiel (getTabNameForItem()/showForItem(), filtre
    // par itemtype/items_id) ET sur 'User' (showForUser(), filtre par
    // users_id, vue symetrique cote beneficiaire).
    Plugin::registerClass(\GlpiPlugin\Assetsign\PassportEvent::class, [
        'addtabon' => array_merge($manageableItemtypes, ['User']),
    ]);

    // --- Notifications ------------------------------------------------------------
    // Rien a enregistrer explicitement : pour un itemtype namespace (GlpiPlugin\Assetsign\Assetsign),
    // GLPI resout automatiquement la classe cible par convention de nom
    // (NotificationTarget::getInstanceClass) vers GlpiPlugin\Assetsign\NotificationTargetAssetsign.

    // --- Widgets de tableau de bord --------------------------------------------------
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['assetsign'] = 'plugin_assetsign_dashboard_cards';

    // --- Intitulés (Configuration > Intitulés) ----------------------------------------
    // Gabarits de assetsign est une liste deroulante classique : au lieu de l'ajouter au
    // menu Administration, on l'enregistre ici pour qu'elle apparaisse dans la page
    // Intitulés comme n'importe quelle liste deroulante de GLPI (voir
    // Plugin::getDropdowns(), qui appelle automatiquement plugin_assetsign_getDropdown()
    // pour chaque plugin actif — aucune entree $PLUGIN_HOOKS n'est necessaire pour ce
    // hook "auto", le nom de fonction est resolu par convention).

    // --- Menu d'administration --------------------------------------------------------
    // "Gestion des fiches" (Search::show(Assetsign::class), cf. front/assetsign.php) : vue
    // transverse de toutes les assetsigns/restitutions, tous materiels et beneficiaires
    // confondus, avec telechargement direct des PDF et annulation (cf. Assetsign::
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
    // (pas le format documente ['types'=>[...],'icon'=>'...']) — piege deja
    // rencontre et documente dans TROUBLESHOOTING.md.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['assetsign'] = [
        'tools' => [\GlpiPlugin\Assetsign\Assetsign::class, \GlpiPlugin\Assetsign\Maintenance::class],
    ];

    if (Plugin::isPluginActive('assetsign')) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['assetsign'] = ['css/assetsign.css'];
    }
}

function plugin_version_assetsign(): array {
    return [
        'name'           => 'Assetsign & Signature',
        'version'        => PLUGIN_ASSETSIGN_VERSION,
        'author'         => 'Vincent GUILLOTTE',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/parime/assetsign-glpi',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_ASSETSIGN_MIN_GLPI,
                'max' => PLUGIN_ASSETSIGN_MAX_GLPI,
            ],
            'php' => [
                'min' => PLUGIN_ASSETSIGN_MIN_PHP,
            ],
        ],
    ];
}

function plugin_assetsign_check_prerequisites(): bool {
   if (version_compare(PHP_VERSION, PLUGIN_ASSETSIGN_MIN_PHP, '<')) {
       echo 'Ce plugin necessite PHP ' . PLUGIN_ASSETSIGN_MIN_PHP . ' ou superieur.';
       return false;
   }

   if (!is_dir(__DIR__ . '/vendor')) {
       echo 'Dependances manquantes : executez "composer install" dans le dossier du plugin.';
       return false;
   }

    return true;
}

function plugin_assetsign_check_config(bool $verbose = false): bool {
   if ($verbose) {
       echo __('Aucune verification de configuration bloquante.', 'assetsign');
   }
    return true;
}
