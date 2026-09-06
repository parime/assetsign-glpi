<?php

namespace GlpiPlugin\Assetsign\Security;

/**
 * Logique d'autorisation de front/opcache_reset.php, extraite pour la rendre testable en
 * PHPUnit sans dependre de $_SERVER/$_GET ni d'une vraie installation GLPI — voir le
 * commentaire de ce front pour le contexte complet (endpoint interne, appele par
 * plugin_assetsign_install() juste apres une mise a jour, protege par jeton partage suite a
 * la revue de securite marketplace GLPI #98).
 *
 * Defense en profondeur a deux niveaux, tous deux necessaires : l'IP source (contournable
 * seule derriere un reverse-proxy en mode loopback, cf. #98) et un jeton partage compare en
 * temps constant (hash_equals(), jamais ===, pour eviter une attaque par timing sur un
 * endpoint public sans session).
 */
final class OpcacheResetGuard
{
   private const ALLOWED_REMOTE_ADDRS = ['127.0.0.1', '::1'];

   public function isAuthorized(string $remoteAddr, ?string $providedToken, string $expectedToken): bool {
      if (!in_array($remoteAddr, self::ALLOWED_REMOTE_ADDRS, true)) {
          return false;
      }

      if ($expectedToken === '' || $providedToken === null || $providedToken === '') {
          return false;
      }

       return hash_equals($expectedToken, $providedToken);
   }
}
