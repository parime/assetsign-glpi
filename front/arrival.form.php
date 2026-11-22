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
 * Issue #221 : préparation manuelle de l'arrivée d'un salarié depuis l'onglet Assetsign de son
 * compte utilisateur (POST « prepare »). Le dossier vit dans son ticket « Arrivée de … » : cette
 * page n'a pas d'écran propre, elle redirige vers le ticket.
 */

use GlpiPlugin\Assetsign\Arrival;
use GlpiPlugin\Assetsign\ArrivalLogic;
use GlpiPlugin\Assetsign\Profile;

Session::checkLoginUser();
// Issue #392 : page d'action, appelée par un formulaire en POST. Ouverte directement (lien,
// favori, parcours des pages), elle répond 400 au lieu d'afficher l'accueil sous son adresse.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

if (isset($_POST['prepare'])) {
   Session::checkRight(Profile::RIGHT_ASSETSIGN, UPDATE);
   // Le dossier et son ticket sont créés dans l'entité par défaut de la personne : une entité hors
   // du périmètre du technicien est refusée, comme pour le départ.
   $newcomer = new User();
   if (!$newcomer->getFromDB((int) ($_POST['users_id'] ?? 0)) || !Session::haveAccessToEntity((int) $newcomer->fields['entities_id'])) {
      throw new \Glpi\Exception\Http\AccessDeniedHttpException();
   }
   $alreadyOpen = Arrival::findOpenFor($newcomer->getID()) !== null;
   $arrival = Arrival::prepareFor($newcomer->getID(), ArrivalLogic::TRIGGER_MANUAL);
   $ticketsId = $arrival !== null ? (int) $arrival->fields['tickets_id'] : 0;
   if ($ticketsId <= 0) {
      Session::addMessageAfterRedirect(__('Le ticket d\'arrivée n\'a pas pu être créé. Vérifiez vos droits de création de tickets dans l\'entité de la personne.', 'assetsign'), false, ERROR);
      Html::back();
   }
   Session::addMessageAfterRedirect($alreadyOpen
      ? __('Une arrivée est déjà en cours pour cette personne : voici son ticket.', 'assetsign')
      : __('Arrivée préparée : le ticket liste les étapes, le matériel se remet depuis son onglet « Remise de matériel ».', 'assetsign'));
   Html::redirect(Ticket::getFormURLWithID($ticketsId));
}

throw new \Glpi\Exception\Http\BadRequestHttpException();
