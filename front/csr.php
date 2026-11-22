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
 * Issue #142 : tableau de bord RSE du parc (entités actives) : empreinte de fabrication, impact
 * évité par le réemploi, âge et durée de vie, filières de fin de vie. Uniquement des données
 * réellement saisies, avec leur taux de couverture.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\CsrDashboard;
use GlpiPlugin\Assetsign\Dashboard\CsrIndicators;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);

Html::header(CsrDashboard::getTypeName(), $_SERVER['PHP_SELF'], 'assets', \GlpiPlugin\Assetsign\Menu::class, CsrDashboard::class);
\GlpiPlugin\Assetsign\MenuSection::show(\GlpiPlugin\Assetsign\CsrDashboard::class);

$config = Config::getForEntity((int) Session::getActiveEntity());
TemplateRenderer::getInstance()->display('@assetsign/csr_dashboard.html.twig', [
   'summary'             => CsrIndicators::collect(),
   'months'              => CsrIndicators::END_OF_LIFE_MONTHS,
   'environmental_on'    => !empty($config->fields['enable_environmental_passport']),
   'reuse_benefit_on'    => !empty($config->fields['enable_reuse_benefit']),
   'can_configure'       => Session::haveRight(Profile::RIGHT_CONFIG, UPDATE),
   'config_url'          => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/config.php',
]);

Html::footer();
