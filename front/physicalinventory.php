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

global $CFG_GLPI, $DB;

// Issue #368 : campagnes d'inventaire physique (scan des etiquettes QR, salle par salle).
Session::checkRight(PhysicalInventory::RIGHTNAME, READ);

$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/physicalinventory.php';
$formUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/physicalinventory.form.php';

if (isset($_POST['launch'])) {
   Session::checkRight(PhysicalInventory::RIGHTNAME, UPDATE);
   $campaign = PhysicalInventory::launch([
       'name'                 => (string) ($_POST['name'] ?? ''),
       'locations_id'         => (int) ($_POST['locations_id'] ?? 0),
       'include_sublocations' => !empty($_POST['include_sublocations']),
       'comment'              => (string) ($_POST['comment'] ?? ''),
   ], (int) Session::getLoginUserID());
   if ($campaign === null) {
      Session::addMessageAfterRedirect(htmlescape(__('Indiquez un nom et un lieu pour la campagne.', 'assetsign')), false, ERROR);
      Html::redirect($selfUrl);
   }
   Html::redirect($formUrl . '?id=' . $campaign->getID());
}

Html::header(PhysicalInventory::getTypeName(2), $_SERVER['PHP_SELF'], 'assets', \GlpiPlugin\Assetsign\Menu::class, PhysicalInventory::class);
\GlpiPlugin\Assetsign\MenuSection::show(PhysicalInventory::class);

$rows = [];
foreach ($DB->request([
        'FROM'  => PhysicalInventory::getTable(),
        'WHERE' => getEntitiesRestrictCriteria(PhysicalInventory::getTable(), '', '', true),
        'ORDER' => 'id DESC',
    ]) as $row
) {
   $campaign = new PhysicalInventory();
   $campaign->fields = $row;
   $rows[] = [
       'id'            => (int) $row['id'],
       'name'          => (string) $row['name'],
       'location'      => (string) Dropdown::getDropdownName('glpi_locations', (int) $row['locations_id']),
       'date_creation' => (string) $row['date_creation'],
       'is_open'       => $campaign->isOpen(),
       'scans'         => $campaign->countScans(),
   ];
}

TemplateRenderer::getInstance()->display('@assetsign/physicalinventory_list.html.twig', [
   'rows'       => $rows,
   'locations'  => PhysicalInventory::getVisibleLocations(),
   'can_create' => (bool) Session::haveRight(PhysicalInventory::RIGHTNAME, UPDATE),
   'self_url'   => $selfUrl,
   'form_url'   => $formUrl,
]);

Html::footer();
