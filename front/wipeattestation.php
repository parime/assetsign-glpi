<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

/**
 * Issue #322 : attestations d'effacement des données, et rattrapage des matériels sortis (don,
 * vente, destruction) sans attestation.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\MenuSection;
use GlpiPlugin\Assetsign\WipeAttestation;

Session::checkRight(WipeAttestation::$rightname, READ);

Html::header(WipeAttestation::getTypeName(2), $_SERVER['PHP_SELF'], 'assets', \GlpiPlugin\Assetsign\Menu::class, WipeAttestation::class);
MenuSection::show(WipeAttestation::class);

TemplateRenderer::getInstance()->display('@assetsign/wipeattestation_list.html.twig', [
    'exits' => WipeAttestation::getExitsWithoutAttestation(),
    'limit' => WipeAttestation::CATCH_UP_LIMIT,
]);
Search::show(WipeAttestation::class);

Html::footer();
