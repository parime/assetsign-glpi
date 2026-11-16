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

use GlpiPlugin\Assetsign\Maintenance;

Session::checkRight(Maintenance::$rightname, READ);

Html::header(Maintenance::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Menu::class, Maintenance::class);
\GlpiPlugin\Assetsign\MenuSection::show(Maintenance::class);

Maintenance::showCreateForm();
Search::show(Maintenance::class);

Html::footer();
