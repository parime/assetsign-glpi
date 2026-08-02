<?php

use GlpiPlugin\Remise\Remise;

// Creation manuelle (Don, Vente...) : n'a pas encore d'id de remise existante,
// doit donc etre traitee AVANT la recherche par id ci-dessous (qui echouerait
// sinon avec displayNotFoundError() pour id=0).
if (isset($_POST['create_manual'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
   try {
       Remise::createManual(
           (string) ($_POST['itemtype'] ?? ''),
           (int) ($_POST['items_id'] ?? 0),
           (int) ($_POST['type'] ?? -1),
           (int) ($_POST['users_id'] ?? 0),
           [
               'price'             => $_POST['price'] ?? 0,
               'sale_date'         => $_POST['sale_date'] ?? date('Y-m-d'),
               'beneficiary_type'  => (int) ($_POST['beneficiary_type'] ?? 0),
               'external_name'     => (string) ($_POST['external_name'] ?? ''),
               'external_contact'  => (string) ($_POST['external_contact'] ?? ''),
           ]
       );
       Session::addMessageAfterRedirect(__('Fiche créée.', 'remise'));
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
       $remise->sendReminderNow();
       Session::addMessageAfterRedirect('Relance envoyée.');
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['cancel_request'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
   try {
       $remise->cancelRequest();
       Session::addMessageAfterRedirect(__('Demande annulée.', 'remise'));
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

if (isset($_POST['add_accessory'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $remise->addAccessory(
        (int) ($_POST['plugin_remise_accessories_id'] ?? 0),
        (int) ($_POST['quantity'] ?? 1),
        (string) ($_POST['comment'] ?? '')
    );
    Html::back();
}

if (isset($_POST['remove_accessory'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $remise->removeAccessory((int) ($_POST['plugin_remise_accessories_id'] ?? 0));
    Html::back();
}

if (isset($_POST['update_observations'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $remise->updateObservations((string) ($_POST['observations'] ?? ''));
    Html::back();
}

if (isset($_POST['update_vente_details'])) {
    Session::checkRight(Remise::$rightname, UPDATE);
    $remise->updateVenteDetails((float) ($_POST['price'] ?? 0), (string) ($_POST['sale_date'] ?? date('Y-m-d')));
    Html::back();
}

Session::checkRight(Remise::$rightname, READ);

Html::header(Remise::getTypeName(1), $_SERVER['PHP_SELF'], 'admin', Remise::class);

$remise->showForm($id);

Html::footer();
