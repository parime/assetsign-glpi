<?php

use GlpiPlugin\Assetsign\Accessory;
use GlpiPlugin\Assetsign\Assetsign;

$item = new Accessory();

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
    Html::redirect(Accessory::getSearchURL());
} else {
    Session::checkRight(Accessory::$rightname, READ);
    Html::header(Accessory::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, Accessory::class);
    $id = (int) ($_GET['id'] ?? 0);
   if ($id > 0) {
       $item->getFromDB($id);
   }
    $item->showForm($id);
    Html::footer();
}
