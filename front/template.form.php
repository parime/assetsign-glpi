<?php

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Template;

$item = new Template();

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
    Html::redirect(Template::getSearchURL());
} else {
    Session::checkRight(Template::$rightname, READ);
    Html::header(Template::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, Template::class);
    $id = (int) ($_GET['id'] ?? 0);
   if ($id > 0) {
       $item->getFromDB($id);
   }
    $item->showForm($id);
    Html::footer();
}
