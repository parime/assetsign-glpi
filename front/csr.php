<?php

/**
 * Issue #142 : tableau de bord RSE du parc (entités actives) — empreinte de fabrication, impact
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

Html::header(CsrDashboard::getTypeName(), $_SERVER['PHP_SELF'], 'tools', CsrDashboard::class);

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
