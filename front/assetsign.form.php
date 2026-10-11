<?php

use GlpiPlugin\Assetsign\Api\AssetsignFormController;
use GlpiPlugin\Assetsign\Assetsign;

$controller = new AssetsignFormController();

// Creation manuelle (Don, Vente...) : n'a pas encore d'id de remise existante,
// doit donc etre traitee AVANT la recherche par id ci-dessous (qui echouerait
// sinon avec displayNotFoundError() pour id=0).
if (isset($_POST['create_manual'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->createManual($_POST, $_FILES));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$assetsign = new Assetsign();

if ($id <= 0 && $_POST === []) {
    Assetsign::redirectToListWithHint(Assetsign::class, __('Une feuille se crée depuis le matériel : en lui attribuant un utilisateur, ou depuis son onglet Attributions (don, vente, destruction).', 'assetsign'));
}

if (!$assetsign->getFromDB($id) || !$assetsign->can($id, READ)) {
    Html::displayNotFoundError();
}

// Issue #163 : apercu du brouillon tel qu'il partira (PDF genere a la volee, rien n'est enregistre).
if (isset($_GET['preview_draft'])) {
    Session::checkRight(Assetsign::$rightname, READ);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="apercu-feuille-' . $assetsign->getID() . '.pdf"');
    echo $assetsign->renderDraftPreviewPdf();
    return;
}

if (isset($_POST['update_draft'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->updateDraft($assetsign, $_POST));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['relance'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->sendReminder($assetsign));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

// Signature sur place (issue #152) : emet un jeton lie au technicien connecte et ouvre la page
// de signature sur son ecran, qu'il remet a la personne qui signe. Redirection HORS du try :
// seul l'echec de startInPersonSignature() doit ramener a la fiche avec un message.
if (isset($_POST['sign_in_person'])) {
    global $CFG_GLPI;

    Session::checkRight(Assetsign::$rightname, UPDATE);
    $inPersonToken = null;
   try {
       $inPersonToken = $assetsign->startInPersonSignature((int) Session::getLoginUserID());
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
       Html::back();
   }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetsign/front/sign.php?t=' . urlencode($inPersonToken));
}

if (isset($_POST['cancel_request'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->cancelRequest($assetsign));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['delegate_signature'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->delegateSignature($assetsign, $_POST));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['revoke_delegation'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->revokeDelegation($assetsign));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['add_accessory'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->addAccessory($assetsign, $_POST);
    $controller->reopenConfirmationIfAsked($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['remove_accessory'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->removeAccessory($assetsign, $_POST);
    Html::back();
}

// Issue #170 : envoi ou abandon d'un brouillon (fenetre de confirmation, onglet du materiel, fiche).
if (isset($_POST['send_draft'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->sendDraft($assetsign, $_POST));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['discard_draft'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->discardDraft($assetsign));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

// Issue #166 : objets du parc GLPI remis avec le materiel.
if (isset($_POST['add_inventoried_item'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    [$message, $isError] = $controller->addInventoriedItem($assetsign, $_POST);
    Session::addMessageAfterRedirect($message, false, $isError ? ERROR : INFO);
    $controller->reopenConfirmationIfAsked($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['add_connected_items'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    Session::addMessageAfterRedirect($controller->addConnectedItems($assetsign));
    Html::back();
}

if (isset($_POST['remove_inventoried_item'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->removeInventoriedItem($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['update_observations'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->updateObservations($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['update_vente_details'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->updateVenteDetails($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['update_don_details'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->updateDonDetails($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['update_destruction_details'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->updateDestructionDetails($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['update_kit'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->updateKit($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['submit_checklist'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->updateChecklist($assetsign, $_POST);
    Html::back();
}

Session::checkRight(Assetsign::$rightname, READ);

Html::header(Assetsign::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Assetsign::class);

$assetsign->showForm($id);

Html::footer();
