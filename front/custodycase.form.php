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

/**
 * Issue #326 : chaîne de possession d'un matériel saisi. Ouverture (depuis l'onglet du matériel,
 * du ticket ou de l'incident de sécurité), consultation, transfert et clôture signés.
 */

use GlpiPlugin\Assetsign\Api\CustodyCaseFormController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\CustodyCase;

if (isset($_POST['open'])) {
    Session::checkRight(CustodyCase::$rightname, CREATE);
   try {
       $case = (new CustodyCaseFormController())->open($_POST, $_FILES);
       Session::addMessageAfterRedirect(__('Chaîne de possession ouverte : la saisie est signée et le PDF enregistré.', 'assetsign'));
       Html::redirect(CustodyCase::getFormURLWithID($case->getID()));
   } catch (\InvalidArgumentException $e) {
       throw new \Glpi\Exception\Http\NotFoundHttpException();
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0 && $_POST === []) {
    Assetsign::redirectToListWithHint(CustodyCase::class, __('Une chaîne de possession s\'ouvre depuis l\'onglet « Chaîne de possession » du matériel, du ticket ou de l\'incident de sécurité.', 'assetsign'));
}

$case = new CustodyCase();
if (!$case->getFromDB($id) || !$case->can($id, READ)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (isset($_POST['transfer']) || isset($_POST['close'])) {
    $case->check($id, UPDATE);
   try {
      if (isset($_POST['close'])) {
          (new CustodyCaseFormController())->close($case, $_POST);
          Session::addMessageAfterRedirect(__('Saisie clôturée : le PDF a une nouvelle version.', 'assetsign'));
      } else {
          (new CustodyCaseFormController())->transfer($case, $_POST);
          Session::addMessageAfterRedirect(__('Transfert signé : le PDF a une nouvelle version.', 'assetsign'));
      }
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
    Html::back();
}

Html::header(CustodyCase::getTypeName(1), $_SERVER['PHP_SELF'], 'assets', \GlpiPlugin\Assetsign\Menu::class, CustodyCase::class);
$case->showForm($id);
Html::footer();
