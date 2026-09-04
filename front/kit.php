<?php

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Kit;

Session::checkRight(Kit::$rightname, READ);

Html::header(Kit::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, Kit::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Kit::canCreate()) {
    echo "<div class='mb-3'>";
    echo "<a class='btn btn-primary' href='" . htmlspecialchars(Kit::getFormURL()) . "'>";
    echo "<i class='ti ti-plus'></i> " . __('Add');
    echo "</a>";
    echo "</div>";
}

Search::show(Kit::class);

Html::footer();
