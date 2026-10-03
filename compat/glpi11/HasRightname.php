<?php

namespace GlpiPlugin\Assetsign\Compat;

/**
 * Variante GLPI 11 : CommonGLPI::$rightname n'y est pas typee. Charge par
 * src/GlpiPlugin/Assetsign/Compat/HasRightname.php, jamais directement.
 */
trait HasRightname
{
   public static $rightname = self::RIGHTNAME;
}
