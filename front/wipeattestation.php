<?php

/**
 * Issue #322 : attestations d'effacement des données, et rattrapage des matériels sortis (don,
 * vente, destruction) sans attestation.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\MenuSection;
use GlpiPlugin\Assetsign\WipeAttestation;

Session::checkRight(WipeAttestation::$rightname, READ);

Html::header(WipeAttestation::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, WipeAttestation::class);
MenuSection::showSection(Assetsign::class, WipeAttestation::class);

TemplateRenderer::getInstance()->display('@assetsign/wipeattestation_list.html.twig', [
    'exits' => WipeAttestation::getExitsWithoutAttestation(),
    'limit' => WipeAttestation::CATCH_UP_LIMIT,
]);
Search::show(WipeAttestation::class);

Html::footer();
