<?php

use GlpiPlugin\Assetsign\Api\MaintenanceFormController;
use GlpiPlugin\Assetsign\Maintenance;

if (isset($_POST['create'])) {
    Session::checkRight(Maintenance::$rightname, CREATE);

   try {
       (new MaintenanceFormController())->createWithChecklist($_POST);
       Session::addMessageAfterRedirect(__('Fiche de maintenance créée.', 'assetsign'));
   } catch (\InvalidArgumentException $e) {
       Html::displayNotFoundError();
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$maintenance = new Maintenance();

if (!$maintenance->getFromDB($id) || !$maintenance->can($id, READ)) {
    Html::displayNotFoundError();
}

Html::header(Maintenance::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Maintenance::class);

$maintenance->showForm($id);

Html::footer();
