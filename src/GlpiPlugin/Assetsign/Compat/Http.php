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

       return (string) \Toolbox::getURLContent($url, $error);
   }
}
