<?php

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ChecklistItem;

Session::checkRight(ChecklistItem::$rightname, READ);

Html::header(ChecklistItem::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, ChecklistItem::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (ChecklistItem::canCreate()) {
    echo "<div class='mb-3'>";
    echo "<a class='btn btn-primary' href='" . htmlspecialchars(ChecklistItem::getFormURL()) . "'>";
    echo "<i class='ti ti-plus'></i> " . __('Ajouter');
    echo "</a>";
    echo "</div>";
}

Search::show(ChecklistItem::class);

Html::footer();
