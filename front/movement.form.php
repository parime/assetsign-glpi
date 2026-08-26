<?php

use GlpiPlugin\Assetsign\Api\MovementFormController;
use GlpiPlugin\Assetsign\Movement;

// Création : n'a pas encore d'id de mouvement existant, doit donc être traitée
// AVANT la recherche par id ci-dessous (qui échouerait sinon avec
// displayNotFoundError() pour id=0) - même ordre que front/assetsign.form.php/
// front/maintenance.form.php.
if (isset($_POST['create'])) {
    Session::checkRight(Movement::$rightname, CREATE);
   try {
       (new MovementFormController())->create($_POST);
       Session::addMessageAfterRedirect(__('Mouvement créé.', 'assetsign'));
   } catch (\InvalidArgumentException $e) {
       Html::displayNotFoundError();
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$movement = new Movement();

if (!$movement->getFromDB($id) || !$movement->can($id, READ)) {
    Html::displayNotFoundError();
}

if (isset($_POST['mark_in_transit'])) {
    Session::checkRight(Movement::$rightname, UPDATE);
    $movement->markInTransit();
    Html::back();
}

if (isset($_POST['mark_completed'])) {
    Session::checkRight(Movement::$rightname, UPDATE);
    // 'T' -> ' ' : même normalisation que MovementFormController::create(), le champ
    // <input type="datetime-local"> soumet un séparateur ISO 8601.
    $dateTo = str_replace('T', ' ', (string) ($_POST['date_to'] ?? ''));
    $movement->markCompleted($dateTo ?: null);
    Html::back();
}

if (isset($_POST['cancel_movement'])) {
    Session::checkRight(Movement::$rightname, UPDATE);
    $movement->cancel();
    Html::back();
}

if (isset($_POST['attach_document'])) {
    Session::checkRight(Movement::$rightname, UPDATE);
    $movement->attachDocument($_FILES['document_file'] ?? []);
    Html::back();
}

Session::checkRight(Movement::$rightname, READ);

Html::header(Movement::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Movement::class);

$movement->showForm($id);

Html::footer();
