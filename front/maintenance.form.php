<?php

use GlpiPlugin\Assetsign\Api\MaintenanceFormController;
use GlpiPlugin\Assetsign\Maintenance;

if (isset($_POST['create'])) {
    Session::checkRight(Maintenance::$rightname, CREATE);

   try {
       (new MaintenanceFormController())->createWithChecklist($_POST, $_FILES);
       Session::addMessageAfterRedirect((int) ($_POST['kind'] ?? 0) === Maintenance::KIND_PREPARATION
           ? __('Fiche de préparation de poste créée.', 'assetsign')
           : __('Fiche de maintenance créée.', 'assetsign'));
   } catch (\InvalidArgumentException $e) {
       throw new \Glpi\Exception\Http\NotFoundHttpException();
   } catch (\Throwable $e) {
       Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$maintenance = new Maintenance();

if ($id <= 0 && $_POST === []) {
    \GlpiPlugin\Assetsign\Assetsign::redirectToListWithHint(Maintenance::class, __('Une fiche de maintenance se crée depuis l\'onglet Maintenance du matériel concerné.', 'assetsign'));
}

if (!$maintenance->getFromDB($id) || !$maintenance->can($id, READ)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(Maintenance::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Maintenance::class);

$maintenance->showForm($id);

Html::footer();
