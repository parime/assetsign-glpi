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

if (!$assetsign->getFromDB($id) || !$assetsign->can($id, READ)) {
    Html::displayNotFoundError();
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

if (isset($_POST['cancel_request'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->cancelRequest($assetsign));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['add_accessory'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->addAccessory($assetsign, $_POST);
    Html::back();
}

if (isset($_POST['remove_accessory'])) {
    Session::checkRight(Assetsign::$rightname, UPDATE);
    $controller->removeAccessory($assetsign, $_POST);
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
