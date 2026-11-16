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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\ChecklistItem;

Session::checkRight(ChecklistItem::$rightname, READ);

Html::header(ChecklistItem::getTypeName(2), $_SERVER['PHP_SELF'], 'config', \CommonDropdown::class, ChecklistItem::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (ChecklistItem::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => ChecklistItem::getFormURL()]);
}

Search::show(ChecklistItem::class);

Html::footer();
