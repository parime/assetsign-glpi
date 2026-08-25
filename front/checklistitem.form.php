<?php

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ChecklistItem;

$item = new ChecklistItem();

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
    Html::redirect(ChecklistItem::getSearchURL());
} else {
    Session::checkRight(ChecklistItem::$rightname, READ);
    Html::header(ChecklistItem::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, ChecklistItem::class);
    $id = (int) ($_GET['id'] ?? 0);
   if ($id > 0) {
       $item->getFromDB($id);
   }
    $item->showForm($id);
    Html::footer();
}
