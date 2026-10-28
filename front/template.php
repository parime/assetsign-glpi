<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Template;

Session::checkRight(Template::$rightname, READ);

Html::header(Template::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, Template::class);

// Search::show() seul ne genere aucun lien de creation (cf. TROUBLESHOOTING.md,
// meme piege que sur les autres intitules de ce plugin).
if (Template::canCreate()) {
    TemplateRenderer::getInstance()->display('@assetsign/components/add_button.html.twig', ['url' => Template::getFormURL()]);
}

Search::show(Template::class);

Html::footer();
