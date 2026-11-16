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

use GlpiPlugin\Assetsign\Movement;

Session::checkRight(Movement::$rightname, READ);

Html::header(Movement::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Menu::class, \GlpiPlugin\Assetsign\Movement::class);
\GlpiPlugin\Assetsign\MenuSection::show(\GlpiPlugin\Assetsign\Movement::class);

Movement::showCreateForm();
Search::show(Movement::class);

Html::footer();
