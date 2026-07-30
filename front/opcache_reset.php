<?php

/**
 * Point d'entree interne, appele automatiquement par plugin_remise_install()
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
 * Restreint aux requetes locales (127.0.0.1/::1) : ce endpoint ne fait rien
 * d'autre que vider un cache de compilation (aucune donnee exposee), mais
 * autant eviter qu'un appel externe puisse le declencher a volonte. Exempte
 * de connexion GLPI (Firewall::STRATEGY_NO_CHECK, cf. setup.php) puisqu'il
 * est appele sans session, depuis un script CLI.
 */

header('Content-Type: text/plain');

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
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
