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

    // Marqueurs d'etat des lieux : deposes cote client avant meme la creation
    // de la fiche (cf. public/js/sign/damage-annotation-local.js), soumis en
    // JSON dans un champ cache plutot qu'en tableau POST classique (forme
    // variable, view_index/x/y/description/severity par marqueur). Decodage
    // defensif : une valeur absente/invalide devient simplement aucun marqueur,
    // jamais une fiche non creee pour un probleme sur ce point secondaire.
    $damageMarkers = json_decode((string) ($_POST['damage_markers'] ?? '[]'), true);
    if (!is_array($damageMarkers)) {
        $damageMarkers = [];
    }

    Maintenance::createWithChecklist($itemtype, $items_id, (int) $target->fields['entities_id'], $checklist, (string) ($_POST['comment'] ?? ''), $damageMarkers);

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
