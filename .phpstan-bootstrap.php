<?php

/**
 * Constantes definies par le coeur GLPI a l'execution reelle (front-controller),
 * mais absentes du simple `require vendor/autoload.php` utilise ici comme
 * bootstrap PHPStan (on evite volontairement le vrai bootstrap GLPI, qui exige
 * une connexion DB). Valeurs arbitraires : seule leur existence compte pour
 * l'analyse statique, jamais leur contenu reel.
 */
if (!defined('GLPI_DOC_DIR')) {
    define('GLPI_DOC_DIR', '/tmp/glpi-phpstan-doc');
}
if (!defined('GLPI_TMP_DIR')) {
    define('GLPI_TMP_DIR', '/tmp/glpi-phpstan-tmp');
}
if (!defined('GLPI_PLUGIN_DOC_DIR')) {
    define('GLPI_PLUGIN_DOC_DIR', '/tmp/glpi-phpstan-plugin-doc');
}

/**
 * Autoload de GLPI lui-meme (classes globales CommonDBTM, Session, Toolbox...),
 * necessaire pour que PHPStan resolve les types du coeur GLPI utilises par ce
 * plugin. Chemin fixe dans l'image officielle glpi/glpi (utilisee en CI) ;
 * variante /var/www/html/glpi conservee pour d'autres agencements locaux.
 */
foreach (['/var/www/glpi/vendor/autoload.php', '/var/www/html/glpi/vendor/autoload.php'] as $glpiAutoload) {
    if (is_file($glpiAutoload)) {
        require_once $glpiAutoload;
        break;
    }
}
