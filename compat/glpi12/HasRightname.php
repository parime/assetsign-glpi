<?php

namespace GlpiPlugin\Assetsign\Compat;

/**
 * Variante GLPI 12 : CommonGLPI::$rightname y est typee `string`. Charge par
 * src/GlpiPlugin/Assetsign/Compat/HasRightname.php, jamais directement.
 */
trait HasRightname
{
   public static string $rightname = self::RIGHTNAME;
}
