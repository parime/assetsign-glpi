<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Assetsign\Compat\GlpiVersion;
use GlpiPlugin\Assetsign\PhysicalInventory;

/*
 * Parent of \GlpiPlugin\Assetsign\PhysicalInventory (see CampaignBase for the reason).
 */
if (GlpiVersion::isAtLeast12()) {
   abstract class PhysicalInventoryBase extends CommonDBTM
   {
      public static string $rightname = PhysicalInventory::RIGHTNAME;
   }
} else {
   abstract class PhysicalInventoryBase extends CommonDBTM
   {
      public static $rightname = PhysicalInventory::RIGHTNAME;
   }
}
