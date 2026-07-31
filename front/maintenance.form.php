<?php

use GlpiPlugin\Remise\Maintenance;

if (isset($_POST['create'])) {
    Session::checkRight(Maintenance::$rightname, CREATE);

    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $items_id = (int) ($_POST['items_id'] ?? 0);

    if (!is_subclass_of($itemtype, CommonDBTM::class)) {
        Html::displayNotFoundError();
    }

    $target = new $itemtype();
    if (!$target->getFromDB($items_id)) {
        Html::displayNotFoundError();
    }

    $checklist = is_array($_POST['checklist'] ?? null) ? $_POST['checklist'] : [];
    Maintenance::createWithChecklist($itemtype, $items_id, (int) $target->fields['entities_id'], $checklist, (string) ($_POST['comment'] ?? ''));

    Session::addMessageAfterRedirect(__('Fiche de maintenance créée.', 'remise'));
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$maintenance = new Maintenance();

if (!$maintenance->getFromDB($id) || !$maintenance->can($id, READ)) {
    Html::displayNotFoundError();
}

Html::header(Maintenance::getTypeName(1), $_SERVER['PHP_SELF'], 'admin', Maintenance::class);

$maintenance->showForm($id);

Html::footer();
