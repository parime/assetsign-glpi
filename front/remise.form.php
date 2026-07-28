<?php

use GlpiPlugin\Remise\Remise;

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

Session::checkRight(Remise::$rightname, READ);

Html::header(Remise::getTypeName(1), $_SERVER['PHP_SELF'], 'admin', Remise::class);

$remise->showForm($id);

Html::footer();
