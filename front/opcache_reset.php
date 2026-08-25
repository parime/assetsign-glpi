<?php

/**
 * Point d'entree interne, appele automatiquement par plugin_assetsign_install()
 * (hook.php) juste apres CacheManager::resetAllCaches() : ce dernier tourne
 * en CLI (bin/console), un SAPI/process distinct de celui qui sert les
 * vraies requetes web (Apache/PHP-FPM) — il ne peut donc PAS vider LEUR
 * OPcache, meme s'il vide bien le cache de gabarits Twig compiles sur
 * disque. opcache_reset() doit s'executer DANS le pool web pour avoir un
 * effet reel (memoire partagee entre tous les workers d'un meme hote) :
 * sans cette requete, le code PHP change (front/sign.php, par exemple)
 * pouvait rester serveur dans son ancienne version compilee jusqu'a un
 * redemarrage manuel du service — contraignant sur un serveur en production
 * avec des utilisateurs actifs.
 *
 * Protege par un jeton partage genere a l'installation (voir plugin_assetsign_install(),
 * hook.php) plutot que par la seule adresse IP source : derriere un reverse-proxy en mode
 * loopback, REMOTE_ADDR vaut 127.0.0.1 pour tout le trafic externe, rendant une restriction par
 * IP seule contournable et permettant un appel repete non authentifie (revue de securite
 * marketplace GLPI, low, #98). Le controle par IP reste en place en defense en profondeur (ce
 * endpoint ne fait rien d'autre que vider un cache de compilation, aucune donnee exposee), mais
 * n'est plus le seul rempart. Exempte de connexion GLPI (Firewall::STRATEGY_NO_CHECK, cf.
 * setup.php) puisqu'il est appele sans session, depuis un script shell (update.sh).
 */

header('Content-Type: text/plain');

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$tokenFile = GLPI_PLUGIN_DOC_DIR . '/assetsign_opcache_token';
$expectedToken = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$providedToken = $_GET['token'] ?? '';
if ($expectedToken === '' || !is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
    opcache_reset();
    echo 'opcache reset';
} else {
    echo 'opcache not active';
}
