<?php

namespace GlpiPlugin\Assetsign\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Assetsign\Arrival;
use GlpiPlugin\Assetsign\Compat\GlpiVersion;

if (GlpiVersion::isAtLeast12()) {
   abstract class ArrivalBase extends CommonDBTM
   {
      public static string $rightname = Arrival::RIGHTNAME;
   }
} else {
   abstract class ArrivalBase extends CommonDBTM
   {
      public static $rightname = Arrival::RIGHTNAME;
   }
}
