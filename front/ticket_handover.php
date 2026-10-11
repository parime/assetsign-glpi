<?php

use GlpiPlugin\Assetsign\Assetsign;

// Issue #183 : remise d'un materiel au demandeur d'un ticket, depuis l'onglet « Remise de materiel ».
// Cree la feuille en brouillon rattachee au ticket, puis ouvre la fiche pour la completer et l'envoyer.
Session::checkRight(Assetsign::$rightname, UPDATE);

$ticket = new Ticket();
if (!isset($_POST['handover']) || !$ticket->getFromDB((int) ($_POST['tickets_id'] ?? 0)) || !$ticket->canUpdateItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$itemtype = '';
$items_id = 0;
if (!empty($_POST['available_item']) && str_contains((string) $_POST['available_item'], ':')) {
    [$itemtype, $id] = explode(':', (string) $_POST['available_item'], 2);
    $items_id = (int) $id;
}

try {
    $sheet = Assetsign::createFromTicket($ticket, $itemtype, $items_id, (int) ($_POST['users_id'] ?? 0));
    Session::addMessageAfterRedirect(__('Feuille préparée en brouillon et rattachée au ticket : complétez-la puis envoyez-la.', 'assetsign'));
    Html::redirect(Assetsign::getFormURLWithID($sheet->getID()));
} catch (\RuntimeException $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    Html::back();
}
