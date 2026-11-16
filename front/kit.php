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
use GlpiPlugin\Assetsign\Kit;

Session::checkRight(Kit::$rightname, READ);

Html::header(Kit::getTypeName(2), $_SERVER['PHP_SELF'], 'config', \CommonDropdown::class, Kit::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Kit::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => Kit::getFormURL()]);
}

Search::show(Kit::class);

Html::footer();
