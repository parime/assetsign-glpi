<?php

use GlpiPlugin\Remise\Maintenance;
use GlpiPlugin\Remise\MaintenanceChecklistItem;

Session::checkRight(MaintenanceChecklistItem::$rightname, READ);

Html::header(MaintenanceChecklistItem::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Maintenance::class, MaintenanceChecklistItem::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (MaintenanceChecklistItem::canCreate()) {
    echo "<div class='mb-3'>";
    echo "<a class='btn btn-primary' href='" . htmlspecialchars(MaintenanceChecklistItem::getFormURL()) . "'>";
    echo "<i class='ti ti-plus'></i> " . __('Ajouter');
    echo "</a>";
    echo "</div>";
}

Search::show(MaintenanceChecklistItem::class);

Html::footer();
