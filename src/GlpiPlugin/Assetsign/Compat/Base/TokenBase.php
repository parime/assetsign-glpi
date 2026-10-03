<?php

namespace GlpiPlugin\Assetsign\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Assetsign\Compat\GlpiVersion;
use GlpiPlugin\Assetsign\Token;

/*
 * Parent of \GlpiPlugin\Assetsign\Token.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
   abstract class TokenBase extends CommonDBTM
   {
      public static string $rightname = Token::RIGHTNAME;
   }
} else {
   abstract class TokenBase extends CommonDBTM
   {
      public static $rightname = Token::RIGHTNAME;
   }
}
