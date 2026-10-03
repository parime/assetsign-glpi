<?php

namespace GlpiPlugin\Assetsign\Compat;

/**
 * Compatibilite GLPI 11 et GLPI 12 dans un seul code source.
 *
 * GLPI 12 a type la plupart des proprietes de ses classes de base
 * (CommonGLPI::$rightname devient `string`...), alors que GLPI 11 les laisse
 * sans type. PHP exige qu'une sous-classe reprenne EXACTEMENT le type du parent :
 * aucune declaration unique ne passe sur les deux versions (erreur fatale dans
 * les deux sens). Les traits de ce dossier existent donc en deux variantes
 * (compat/glpi11, compat/glpi12 a la racine du plugin), chargees selon la
 * version reellement installee.
 */
final class GlpiVersion
{
   public static function isAtLeast12(): bool {
       // '12.0.0-dev' (pas '12.0.0') : inclut les preversions (alpha/beta/rc) de
       // GLPI 12, qui portent deja les changements d'API.
       return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '12.0.0-dev', '>=');
   }

    /** Chemin absolu du dossier de variantes adapte a la version de GLPI installee. */
   public static function compatDir(): string {
       return dirname(__DIR__, 4) . '/compat/' . (self::isAtLeast12() ? 'glpi12' : 'glpi11');
   }
}
