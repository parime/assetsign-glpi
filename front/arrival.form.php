<?php

/**
 * Issue #221 : préparation manuelle de l'arrivée d'un salarié depuis l'onglet Assetsign de son
 * compte utilisateur (POST « prepare »). Le dossier vit dans son ticket « Arrivée de … » : cette
 * page n'a pas d'écran propre, elle redirige vers le ticket.
 */

use GlpiPlugin\Assetsign\Arrival;
use GlpiPlugin\Assetsign\ArrivalLogic;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

Session::checkLoginUser();

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

Html::redirect($CFG_GLPI['root_doc'] . '/front/central.php');
