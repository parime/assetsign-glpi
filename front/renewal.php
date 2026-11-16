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
 * Issue #222 : renouvellement du parc : materiels en fin d'amortissement dans l'horizon choisi,
 * budget par annee, export CSV et ticket de changement groupe (creation uniquement).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Profile;
use GlpiPlugin\Assetsign\Renewal;
use GlpiPlugin\Assetsign\RenewalLogic;

global $CFG_GLPI;

Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);

$horizon = RenewalLogic::normalizeHorizon((int) ($_REQUEST['horizon'] ?? 12));
$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/renewal.php';

if (isset($_POST['create_change'])) {
   Session::checkRight(Change::$rightname, CREATE);
   $changeId = Renewal::createChange(Renewal::collect($horizon), $horizon, (int) Session::getActiveEntity());
   if ($changeId > 0) {
      Session::addMessageAfterRedirect(htmlescape(sprintf(__('Ticket de changement n°%d créé.', 'assetsign'), $changeId)));
      Html::redirect(Change::getFormURLWithID($changeId));
   }
   Session::addMessageAfterRedirect(htmlescape(__('Aucun matériel à renouveler sur cette période.', 'assetsign')), false, WARNING);
   Html::redirect($selfUrl . '?horizon=' . $horizon);
}

$rows = Renewal::collect($horizon);

if (($_GET['export'] ?? '') === 'csv') {
   header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="renouvellement-parc.csv"');
   echo Renewal::toCsv($rows);
   return;
}

Html::header(Renewal::getTypeName(), $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Menu::class, \GlpiPlugin\Assetsign\Renewal::class);
\GlpiPlugin\Assetsign\MenuSection::show(\GlpiPlugin\Assetsign\Renewal::class);

TemplateRenderer::getInstance()->display('@assetsign/renewal.html.twig', [
   'rows'          => $rows,
   'budget'        => RenewalLogic::budgetByYear($rows),
   'horizon'       => $horizon,
   'horizons'      => RenewalLogic::HORIZONS,
   'self_url'      => $selfUrl,
   'today'         => date('Y-m-d'),
   'can_change'    => Session::haveRight(Change::$rightname, CREATE),
   'config_url'    => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/config.php',
]);

Html::footer();
