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

use GlpiPlugin\Assetsign\PhysicalInventory;

// Issue #368 : scan par la camera (appel AJAX de js/inventory/scan.js). Reponse JSON ; le jeton
// CSRF a usage unique est renouvele a chaque appel (en-tete X-Assetsign-Csrf-Token).
header('Content-Type: application/json');

Session::checkRight(PhysicalInventory::RIGHTNAME, UPDATE);
header('X-Assetsign-Csrf-Token: ' . Session::getNewCSRFToken());

$id = (int) ($_POST['id'] ?? 0);
$campaign = new PhysicalInventory();
if (!$campaign->getFromDB($id) || !$campaign->can($id, UPDATE)) {
   echo json_encode(['success' => false, 'result' => 'denied', 'status' => '', 'message' => __('Campagne introuvable.', 'assetsign')]);
   return;
}

$result = $campaign->recordScan(
    (string) ($_POST['code'] ?? ''),
    (int) Session::getLoginUserID(),
    array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))
);
echo json_encode(['success' => $result['result'] === 'recorded'] + $result);
