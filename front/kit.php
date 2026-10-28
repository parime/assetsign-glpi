<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Kit;

Session::checkRight(Kit::$rightname, READ);

Html::header(Kit::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, Kit::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Kit::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => Kit::getFormURL()]);
}

Search::show(Kit::class);

Html::footer();
