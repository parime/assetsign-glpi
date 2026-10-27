<?php

/**
 * Issue #224 : historique des versions de la charte informatique de l'entite active (texte, date
 * de premiere diffusion, campagnes, nombre de signataires), pour l'auditeur.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);

$entitiesId = Session::getActiveEntity();

Html::header(__('Historique des versions de la charte', 'assetsign'), $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Assetsign::class, \GlpiPlugin\Assetsign\Campaign::class);

TemplateRenderer::getInstance()->display('@assetsign/charter_versions.html.twig', [
    // Texte assaini a l'affichage, comme sur la page de signature d'une attestation.
    'versions'      => array_map(
        static fn (array $v): array => $v + ['safe_html' => \Glpi\RichText\RichText::getSafeHtml($v['content'])],
        Campaign::charterVersions($entitiesId)
    ),
    'undistributed' => Campaign::isCurrentCharterUndistributed($entitiesId),
    'campaigns_url' => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/campaign.php',
]);

Html::footer();
