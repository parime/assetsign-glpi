<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;

Session::checkRight(MaintenanceChecklistItem::$rightname, READ);

Html::header(MaintenanceChecklistItem::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Maintenance::class, MaintenanceChecklistItem::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (MaintenanceChecklistItem::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => MaintenanceChecklistItem::getFormURL()]);
}

Search::show(MaintenanceChecklistItem::class);

Html::footer();
