<?php

use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;

$item = new MaintenanceChecklistItem();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $item->add($_POST);
    Html::back();
} else if (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else if (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(MaintenanceChecklistItem::getSearchURL());
} else {
    Session::checkRight(MaintenanceChecklistItem::$rightname, READ);
    Html::header(MaintenanceChecklistItem::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Maintenance::class, MaintenanceChecklistItem::class);
    $id = (int) ($_GET['id'] ?? 0);
   if ($id > 0) {
       $item->getFromDB($id);
   }
    $item->showForm($id);
    Html::footer();
}
