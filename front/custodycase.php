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

/**
 * Issue #326 : liste des matériels saisis (chaînes de possession), Parc > AssetSign > Sortie et saisie.
 */

use GlpiPlugin\Assetsign\CustodyCase;
use GlpiPlugin\Assetsign\MenuSection;

Session::checkRight(CustodyCase::$rightname, READ);

Html::header(CustodyCase::getTypeName(2), $_SERVER['PHP_SELF'], 'assets', \GlpiPlugin\Assetsign\Menu::class, CustodyCase::class);
MenuSection::show(CustodyCase::class);
Search::show(CustodyCase::class);
Html::footer();
