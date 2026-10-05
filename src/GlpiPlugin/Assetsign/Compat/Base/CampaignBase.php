<?php

namespace GlpiPlugin\Assetsign\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\Compat\GlpiVersion;

/*
 * Parent of \GlpiPlugin\Assetsign\Campaign.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
   abstract class CampaignBase extends CommonDBTM
   {
      public static string $rightname = Campaign::RIGHTNAME;
   }
} else {
   abstract class CampaignBase extends CommonDBTM
   {
      public static $rightname = Campaign::RIGHTNAME;
   }
}
