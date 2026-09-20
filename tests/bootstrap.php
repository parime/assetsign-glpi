<?php

/**
 * Bootstrap PHPUnit du plugin.
 *
 * GLPI 11 n'a plus de bootstrap "leger" (inc/includes.php ne fait plus que
 * des verifications de retrocompatibilite) : le vrai demarrage passe par le
 * noyau Symfony Glpi\Kernel\Kernel, exactement comme le fait bin/console
 * (cf. Glpi\Console\Application). On reproduit ici le meme mecanisme pour
 * disposer d'une connexion DB fonctionnelle, de l'autoload des classes
 * GLPI/plugin et des PLUGIN_HOOKS, sans dependre d'un contexte HTTP.
 *
 * ATTENTION : ces tests doivent tourner contre une instance GLPI DEDIEE AUX
 * TESTS (jamais une instance de production), avec le plugin assetsign installe
 * et actif. La plupart des tests ecrivent en base — ils sont enveloppes dans
 * une transaction annulee en tearDown (cf. AssetsignTestCase), mais ce n'est pas
 * une garantie absolue (voir avertissement dans ARCHITECTURE.md, section Tests automatises).
 *
 * Variable d'environnement GLPI_ROOT_DIR : chemin absolu vers la racine GLPI
 * (le dossier contenant vendor/, src/, bin/console...). Par defaut, suppose
 * que ce plugin est installe dans <glpi>/plugins/assetsign/, donc trois niveaux
 * au-dessus de ce fichier (tests/ -> assetsign/ -> plugins/ -> <glpi>/).
 */

$glpiRoot = getenv('GLPI_ROOT_DIR') ?: dirname(__DIR__, 3);

if (!is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "GLPI introuvable a '$glpiRoot'.\n" .
        "Definissez la variable d'environnement GLPI_ROOT_DIR vers la racine de votre installation GLPI\n" .
        "(le dossier qui contient vendor/, src/ et bin/console), par exemple :\n" .
        "  GLPI_ROOT_DIR=/var/www/glpi vendor/bin/phpunit\n"
    );
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';

// Autoload des dependances propres au plugin (Dompdf...), independant de celui de GLPI.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

$kernel = new \Glpi\Kernel\Kernel('production');
// Une simple variable locale ici ne devient jamais une vraie globale PHP (ce bootstrap est inclus
// depuis l'intérieur d'une méthode, pas exécuté au premier niveau d'un script) — du code historique
// de GLPI (ex. les chemins dépendant de isAPI()/getMainRequest(), atteints depuis des hooks
// CommonDBTM::add() comme la mise en file d'une notification) fait `global $kernel` en interne et
// ne trouve rien sans ceci, plantant avec "Call to a member function getMainRequest() on null".
// Confirmé en conditions réelles sur le plugin jumeau glpi-iso27001-management : exactement ce
// plantage sur tout test créant un User, avant l'ajout de cette ligne — même correctif préventif
// appliqué ici avant qu'un scénario de test similaire ne le déclenche dans ce plugin-ci.
$GLOBALS['kernel'] = $kernel;
$kernel->boot();

if (!\Plugin::isPluginActive('assetsign')) {
    fwrite(
        STDERR,
        "Le plugin 'assetsign' n'est pas installe/actif sur cette instance GLPI de test.\n" .
        "Installez-le et activez-le avant de lancer les tests (bin/console plugin:install assetsign ; plugin:activate assetsign).\n"
    );
    exit(1);
}
