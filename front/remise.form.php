<?php

use GlpiPlugin\Remise\Api\RemiseFormController;
use GlpiPlugin\Remise\Remise;

$controller = new RemiseFormController();

// Creation manuelle (Don, Vente...) : n'a pas encore d'id de remise existante,
// doit donc etre traitee AVANT la recherche par id ci-dessous (qui echouerait
// sinon avec displayNotFoundError() pour id=0).
if (isset($_POST['create_manual'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->createManual($_POST));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$remise = new Remise();

if (!$remise->getFromDB($id) || !$remise->can($id, READ)) {
    Html::displayNotFoundError();
}

if (isset($_POST['relance'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->sendReminder($remise));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['cancel_request'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
   try {
       Session::addMessageAfterRedirect($controller->cancelRequest($remise));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['add_accessory'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $controller->addAccessory($remise, $_POST);
    Html::back();
}

if (isset($_POST['remove_accessory'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $controller->removeAccessory($remise, $_POST);
    Html::back();
}

if (isset($_POST['update_observations'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $controller->updateObservations($remise, $_POST);
    Html::back();
}

if (isset($_POST['update_vente_details'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $controller->updateVenteDetails($remise, $_POST);
    Html::back();
}

Session::checkRight(Remise::$rightname, READ);

Html::header(Remise::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Remise::class);

$remise->showForm($id);

Html::footer();
