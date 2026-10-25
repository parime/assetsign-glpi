<?php

namespace GlpiPlugin\Assetsign\Compat;

/**
 * Requete HTTP sortante compatible GLPI 11 et 12 : GLPI 12 a supprime
 * Toolbox::getURLContent() au profit de Glpi\Toolbox\HttpClient, absent de
 * GLPI 11. Les deux respectent la configuration proxy de GLPI.
 */
final class Http
{
    /**
     * @return string Le corps de la reponse, ou '' en cas d'echec (meme contrat
     *                que l'ancien Toolbox::getURLContent()), $error decrivant alors la cause.
     */
   public static function getContent(string $url, ?string &$error = null): string {
      if (class_exists(\Glpi\Toolbox\HttpClient::class)) {
         try {
             return (new \Glpi\Toolbox\HttpClient())
                 ->get($url, ['headers' => ['User-Agent' => 'GLPI-assetsign'], 'timeout' => 10])
                 ->getContent();
         } catch (\Throwable $e) {
             $error = $e->getMessage();
             return '';
         }
      }

       // Delai TOTAL (le coeur ne fixe qu'un delai de connexion) : un hote qui
       // accepte la connexion sans jamais repondre bloquerait sinon la page.
       return (string) \Toolbox::getURLContent($url, $error, 0, [CURLOPT_TIMEOUT => 10]);
   }

    /**
     * Envoie un corps JSON en POST (issue #231, webhooks de messagerie), en respectant le proxy de
     * GLPI. Vrai si le serveur a repondu par un code 2xx ; sinon faux, $error decrivant la cause.
     *
     * @param array<string, mixed> $payload
     */
   public static function postJson(string $url, array $payload, ?string &$error = null): bool {
      try {
         if (class_exists(\Glpi\Toolbox\HttpClient::class)) {
             $status = (new \Glpi\Toolbox\HttpClient())
                 ->post($url, ['json' => $payload, 'timeout' => 5])
                 ->getStatusCode();
         } else {
             $status = \Toolbox::getGuzzleClient(['timeout' => 5])
                 ->post($url, ['json' => $payload, 'http_errors' => false])
                 ->getStatusCode();
         }
      } catch (\Throwable $e) {
          $error = $e->getMessage();
          return false;
      }
      if ($status < 200 || $status >= 300) {
          $error = 'HTTP ' . $status;
          return false;
      }
       return true;
   }
}
