<?php

use GlpiPlugin\Remise\Accessory;

Session::checkRight(Accessory::$rightname, READ);

Html::header(Accessory::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Accessory::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Accessory::canCreate()) {
    echo "<div class='mb-3'>";
    echo "<a class='btn btn-primary' href='" . htmlspecialchars(Accessory::getFormURL()) . "'>";
    echo "<i class='ti ti-plus'></i> " . __('Ajouter');
    echo "</a>";
    echo "</div>";
}

Search::show(Accessory::class);

Html::footer();
