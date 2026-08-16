<?php

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Template;

Session::checkRight(Template::$rightname, READ);

Html::header(Template::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, Template::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Template::canCreate()) {
    echo "<div class='mb-3'>";
    echo "<a class='btn btn-primary' href='" . htmlspecialchars(Template::getFormURL()) . "'>";
    echo "<i class='ti ti-plus'></i> " . __('Ajouter');
    echo "</a>";
    echo "</div>";
}

Search::show(Template::class);

Html::footer();
