<?php

use GlpiPlugin\Remise\DamageMarker;
use GlpiPlugin\Remise\Remise;

header('Content-Type: application/json');

Session::checkRight(Remise::$rightname, UPDATE);

$remisesId = (int) ($_POST['remises_id'] ?? 0);
$remise = new Remise();

if (!$remise->getFromDB($remisesId) || !$remise->can($remisesId, UPDATE) || !$remise->isStillEditable()) {
    echo json_encode(['success' => false, 'error' => __('Cette fiche ne peut plus être modifiée.', 'remise')]);
    exit;
}

// Jeton CSRF a usage unique (cf. README) : sans rotation, ajouter/modifier plus
// d'un repere par chargement de page echouerait en 403 des le 2e appel.
header('X-Remise-Csrf-Token: ' . Session::getNewCSRFToken());

// Logique d'ajout/modification/suppression partagee avec les actions de
// repere de front/sign.php (cf. DamageMarker::handleMutationRequest()) :
// seule l'autorisation ci-dessus (droit RIGHT_REMISE) differe entre les deux.
echo json_encode(DamageMarker::handleMutationRequest($remise, $_POST));
