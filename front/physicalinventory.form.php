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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\PhysicalInventory;

global $CFG_GLPI;

// Issue #368 : une campagne d'inventaire physique (scan, saisie manuelle, rapport, export CSV).
Session::checkRight(PhysicalInventory::RIGHTNAME, READ);

$id       = (int) ($_REQUEST['id'] ?? 0);
$selfUrl  = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/physicalinventory.form.php?id=' . $id;
$entities = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));

$campaign = new PhysicalInventory();
if (!$campaign->getFromDB($id)) {
   throw new \Glpi\Exception\Http\NotFoundHttpException();
}
// can() verifie aussi l'entite de la campagne.
if (!$campaign->can($id, READ)) {
   throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['scan']) || isset($_POST['close'])) {
   if (!$campaign->can($id, UPDATE)) {
      throw new \Glpi\Exception\Http\AccessDeniedHttpException();
   }
   if (isset($_POST['close'])) {
      $campaign->close();
      Session::addMessageAfterRedirect(htmlescape(__('Campagne clôturée : le rapport est figé.', 'assetsign')));
   } else {
      $result = $campaign->recordScan((string) ($_POST['code'] ?? ''), (int) Session::getLoginUserID(), $entities);
      Session::addMessageAfterRedirect(htmlescape($result['message']), false, $result['result'] === 'recorded' ? INFO : WARNING);
   }
   Html::redirect($selfUrl);
}

$report = $campaign->getReport($entities);

if (($_GET['export'] ?? '') === 'csv') {
   header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="inventaire-physique-' . $id . '.csv"');
   echo PhysicalInventory::toCsv($report['rows']);
   return;
}

Html::header(PhysicalInventory::getTypeName(2), $_SERVER['PHP_SELF'], 'assets', \GlpiPlugin\Assetsign\Menu::class, PhysicalInventory::class);
\GlpiPlugin\Assetsign\MenuSection::show(PhysicalInventory::class);

TemplateRenderer::getInstance()->display('@assetsign/physicalinventory_form.html.twig', [
   'campaign'   => [
       'id'                   => $id,
       'name'                 => (string) $campaign->fields['name'],
       'location'             => (string) Dropdown::getDropdownName('glpi_locations', (int) $campaign->fields['locations_id']),
       'include_sublocations' => !empty($campaign->fields['include_sublocations']),
       'comment'              => (string) ($campaign->fields['comment'] ?? ''),
       'date_creation'        => (string) $campaign->fields['date_creation'],
       'date_closed'          => (string) ($campaign->fields['date_closed'] ?? ''),
       'is_open'              => $campaign->isOpen(),
   ],
   'report'      => $report,
   'labels'      => PhysicalInventory::getStatusLabels(),
   'colors'      => PhysicalInventory::getStatusColors(),
   'can_scan'    => $campaign->isOpen() && (bool) $campaign->can($id, UPDATE),
   'self_url'    => $selfUrl,
   'list_url'    => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/physicalinventory.php',
   'scan_url'    => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/physicalinventoryscan.php',
   'scan_token'  => Session::getNewCSRFToken(),
]);

Html::footer();
