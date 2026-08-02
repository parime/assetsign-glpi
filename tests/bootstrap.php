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
 * TESTS (jamais une instance de production), avec le plugin remise installe
 * et actif. La plupart des tests ecrivent en base — ils sont enveloppes dans
 * une transaction annulee en tearDown (cf. RemiseTestCase), mais ce n'est pas
 * une garantie absolue (voir avertissement dans ARCHITECTURE.md, section Tests automatises).
 *
 * Variable d'environnement GLPI_ROOT_DIR : chemin absolu vers la racine GLPI
 * (le dossier contenant vendor/, src/, bin/console...). Par defaut, suppose
 * que ce plugin est installe dans <glpi>/plugins/remise/, donc trois niveaux
 * au-dessus de ce fichier (tests/ -> remise/ -> plugins/ -> <glpi>/).
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
$kernel->boot();

if (!\Plugin::isPluginActive('remise')) {
    fwrite(
        STDERR,
        "Le plugin 'remise' n'est pas installe/actif sur cette instance GLPI de test.\n" .
        "Installez-le et activez-le avant de lancer les tests (bin/console plugin:install remise ; plugin:activate remise).\n"
    );
    exit(1);
}
