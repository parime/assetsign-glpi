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

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\DamageMarker;

header('Content-Type: application/json');

Session::checkRight(Assetsign::$rightname, UPDATE);

$assetsignsId = (int) ($_POST['assetsigns_id'] ?? 0);
$assetsign = new Assetsign();

if (!$assetsign->getFromDB($assetsignsId) || !$assetsign->can($assetsignsId, UPDATE) || !$assetsign->isStillEditable()) {
    echo json_encode(['success' => false, 'error' => __('Cette fiche ne peut plus être modifiée.', 'assetsign')]);
    exit;
}

// Jeton CSRF a usage unique (cf. TROUBLESHOOTING.md) : sans rotation, ajouter/modifier plus
// d'un repere par chargement de page echouerait en 403 des le 2e appel.
header('X-Assetsign-Csrf-Token: ' . Session::getNewCSRFToken());

// Logique d'ajout/modification/suppression partagee avec les actions de
// repere de front/sign.php (cf. DamageMarker::handleMutationRequest()) :
// seule l'autorisation ci-dessus (droit RIGHT_ASSETSIGN) differe entre les deux.
echo json_encode(DamageMarker::handleMutationRequest($assetsign, $_POST));
