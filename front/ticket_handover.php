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
