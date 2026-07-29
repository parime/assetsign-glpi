<?php

/**
 * Fonctions procedurales appelees directement par le coeur de GLPI.
 * Volontairement minces : elles delegue immediatement aux classes namespacees
 * dans src/GlpiPlugin/Remise/ qui portent la vraie logique metier.
 */

use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\Template;
use GlpiPlugin\Remise\Accessory;
use GlpiPlugin\Remise\RemiseAccessory;
use GlpiPlugin\Remise\Token;
use GlpiPlugin\Remise\Signature;
use GlpiPlugin\Remise\Reminder;
use GlpiPlugin\Remise\Profile;
use GlpiPlugin\Remise\NotificationTargetRemise;
use GlpiPlugin\Remise\Dashboard\CardProvider;

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
function plugin_remise_item_assignment(CommonDBTM $item): void
{
    try {
        Remise::handleItemAssignment($item);
    } catch (\Throwable $e) {
        \Toolbox::logInFile(
            'remise',
            sprintf(
                "Echec du declenchement remise pour %s #%d : %s\n%s",
                $item->getType(),
                $item->getID(),
                $e->getMessage(),
                $e->getTraceAsString()
            ),
            true
        );
    }
}

function plugin_remise_item_pre_purge(CommonDBTM $item): void
{
    try {
        Remise::archiveForPurgedItem($item);
    } catch (\Throwable $e) {
        \Toolbox::logInFile(
            'remise',
            sprintf(
                "Echec de l'archivage des remises pour %s #%d : %s\n%s",
                $item->getType(),
                $item->getID(),
                $e->getMessage(),
                $e->getTraceAsString()
            ),
            true
        );
    }
}

function plugin_remise_dashboard_cards(): array
{
    $group = __('Remise & signature', 'remise');

    return [
        'remise_pending' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Remises en attente de signature', 'remise'),
            'provider'   => CardProvider::class . '::pending',
            'filters'    => [],
        ],
        'remise_signed' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Remises signées', 'remise'),
            'provider'   => CardProvider::class . '::signed',
            'filters'    => [],
        ],
        'remise_expired' => [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Remises expirées', 'remise'),
            'provider'   => CardProvider::class . '::expired',
            'filters'    => [],
        ],
    ];
}

// ----------------------------------------------------------------------------------
// Intitulés (Configuration > Intitulés)
// ----------------------------------------------------------------------------------

/**
 * Appelee par Plugin::getDropdowns() (hook AUTO_GET_DROPDOWN) : ajoute Gabarits de
 * remise a la page Configuration > Intitulés plutot que dans le menu Administration,
 * comme n'importe quelle autre liste deroulante de GLPI.
 */
function plugin_remise_getDropdown(): array
{
    return [
        Template::class  => Template::getTypeName(2),
        Accessory::class => Accessory::getTypeName(2),
    ];
}

// ----------------------------------------------------------------------------------
// Installation / desinstallation
// ----------------------------------------------------------------------------------

function plugin_remise_install(): bool
{
    $migration = new Migration((int) str_replace('.', '', PLUGIN_REMISE_VERSION));

    Config::install($migration);
    Template::install($migration);
    Accessory::install($migration);
    Remise::install($migration);
    RemiseAccessory::install($migration);
    Token::install($migration);
    Signature::install($migration);
    Reminder::install($migration);
    Profile::install($migration);

    $migration->executeMigration();

    NotificationTargetRemise::install();

    CronTask::register(
        Remise::class,
        'remiseReminders',
        HOUR_TIMESTAMP,
        [
            'comment' => 'Envoie les relances de signature dues (J+3, J+7, puis hebdomadaire)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        Remise::class,
        'remiseExpire',
        DAY_TIMESTAMP,
        [
            'comment' => 'Marque comme expirees les remises dont le delai de signature est depasse',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        Remise::class,
        'remiseExpiryWarning',
        DAY_TIMESTAMP,
        [
            'comment' => 'Alerte le technicien des remises sur le point d\'expirer (avant que ce soit trop tard pour agir)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );
    CronTask::register(
        Token::class,
        'remiseCleanupTokens',
        DAY_TIMESTAMP,
        [
            'comment' => 'Purge les tokens de signature invalides ou perimes depuis plus de ' . Token::CLEANUP_RETENTION_DAYS . ' jours',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    return true;
}

function plugin_remise_uninstall(): bool
{
    $migration = new Migration((int) str_replace('.', '', PLUGIN_REMISE_VERSION));

    foreach ([
        'glpi_plugin_remise_reminders',
        'glpi_plugin_remise_signatures',
        'glpi_plugin_remise_tokens',
        'glpi_plugin_remise_remiseaccessories',
        'glpi_plugin_remise_remises',
        'glpi_plugin_remise_accessories',
        'glpi_plugin_remise_templates',
        'glpi_plugin_remise_configs',
    ] as $table) {
        $migration->dropTable($table);
    }

    Profile::uninstall();
    NotificationTargetRemise::uninstall();

    // Retire toutes les taches planifiees enregistrees par ce plugin
    CronTask::Unregister('remise');

    return true;
}
