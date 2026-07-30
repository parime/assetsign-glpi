<?php

use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\DamageMarker;

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

if (isset($_POST['add'])) {
    $viewIndex = (int) ($_POST['view_index'] ?? -1);
    if ($viewIndex < 0 || $viewIndex >= DamageMarker::VIEW_COUNT) {
        echo json_encode(['success' => false, 'error' => 'Vue invalide.']);
        exit;
    }

    $id = DamageMarker::addMarker(
        $remisesId,
        $viewIndex,
        (float) ($_POST['x'] ?? 0),
        (float) ($_POST['y'] ?? 0),
        (string) ($_POST['description'] ?? ''),
        (int) ($_POST['severity'] ?? DamageMarker::SEVERITY_MINOR)
    );

    if ($id > 0) {
        $remise->refreshDamageAnnotationPdf();
    }

    echo json_encode(['success' => $id > 0, 'id' => $id]);
    exit;
}

if (isset($_POST['update'])) {
    $changes = [];
    if (isset($_POST['x']) && isset($_POST['y'])) {
        $changes['x_percent'] = (float) $_POST['x'];
        $changes['y_percent'] = (float) $_POST['y'];
    }
    if (isset($_POST['description'])) {
        $changes['description'] = (string) $_POST['description'];
    }
    if (isset($_POST['severity'])) {
        $changes['severity'] = (int) $_POST['severity'];
    }

    $success = DamageMarker::updateMarker((int) ($_POST['id'] ?? 0), $remisesId, $changes);
    if ($success) {
        $remise->refreshDamageAnnotationPdf();
    }
    echo json_encode(['success' => $success]);
    exit;
}

if (isset($_POST['delete'])) {
    $success = DamageMarker::deleteMarker((int) ($_POST['id'] ?? 0), $remisesId);
    if ($success) {
        $remise->refreshDamageAnnotationPdf();
    }
    echo json_encode(['success' => $success]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Action inconnue.']);
