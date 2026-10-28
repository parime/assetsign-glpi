<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ChecklistItem;

Session::checkRight(ChecklistItem::$rightname, READ);

Html::header(ChecklistItem::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, ChecklistItem::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (ChecklistItem::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => ChecklistItem::getFormURL()]);
}

Search::show(ChecklistItem::class);

Html::footer();
