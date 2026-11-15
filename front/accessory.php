<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Accessory;

Session::checkRight(Accessory::$rightname, READ);

Html::header(Accessory::getTypeName(2), $_SERVER['PHP_SELF'], 'config', \CommonDropdown::class, Accessory::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Accessory::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => Accessory::getFormURL()]);
}

Search::show(Accessory::class);

Html::footer();
