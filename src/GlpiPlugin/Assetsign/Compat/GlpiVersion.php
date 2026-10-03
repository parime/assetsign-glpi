<?php

namespace GlpiPlugin\Assetsign\Compat;

/**
 * Compatibilite GLPI 11 et GLPI 12 dans un seul code source.
 *
 * GLPI 12 a type la plupart des proprietes de ses classes de base
 * (CommonGLPI::$rightname devient `string`...), alors que GLPI 11 les laisse
 * sans type. PHP exige qu'une sous-classe reprenne EXACTEMENT le type du parent :
 * aucune declaration unique ne passe sur les deux versions (erreur fatale dans
 * les deux sens). Chaque classe concernee herite donc d'une classe intermediaire
 * (Compat/Base/), declaree selon la version reellement installee : typee en 12,
 * sans type en 11. Une classe et non un trait : sur PHP 8.2 a 8.4, un trait ne
 * peut pas redeclarer une propriete heritee avec une autre valeur.
 */
final class GlpiVersion
{
   public static function isAtLeast12(): bool {
       // '12.0.0-dev' (pas '12.0.0') : inclut les preversions (alpha/beta/rc) de
       // GLPI 12, qui portent deja les changements d'API.
       return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '12.0.0-dev', '>=');
   }
}
