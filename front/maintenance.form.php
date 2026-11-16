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

Html::header(Maintenance::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Menu::class, Maintenance::class);

$maintenance->showForm($id);

Html::footer();
