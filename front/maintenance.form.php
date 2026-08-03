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
    // !can($items_id, READ) : sans ce controle, le droit generique
    // Maintenance::$rightname (verifie ci-dessus, non restreint par entite)
    // suffisait a creer une fiche pour n'importe quel materiel de n'importe
    // quelle entite de l'instance - meme faille reelle que celle corrigee
    // dans Remise::createManual(), cf. TROUBLESHOOTING.md.
   if (!$target->getFromDB($items_id) || !$target->can($items_id, READ)) {
       Html::displayNotFoundError();
   }

    $entities_id = (int) $target->fields['entities_id'];

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

    // Signature du technicien : capturee cote client (canvas, meme flux "local"
    // que l'etat des lieux visuel ci-dessus) et soumise dans ce meme POST -
    // jamais de jeton ni d'e-mail, le technicien est deja authentifie sur CETTE
    // requete. Validee par Maintenance::createWithChecklist() lui-meme (pas ici) -
    // meme convention que Remise::createManual() : le modele leve une
    // RuntimeException AVANT toute ecriture en base si la signature est
    // activee pour l'entite mais absente/trop peu tracee, capturee ci-dessous.
   try {
       Maintenance::createWithChecklist(
           $itemtype,
           $items_id,
           $entities_id,
           $checklist,
           (string) ($_POST['comment'] ?? ''),
           $damageMarkers,
           (string) ($_POST['technician_signature'] ?? '') ?: null,
           [
               'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
               'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
           ]
       );
       Session::addMessageAfterRedirect(__('Fiche de maintenance créée.', 'remise'));
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

Html::header(Maintenance::getTypeName(1), $_SERVER['PHP_SELF'], 'admin', Maintenance::class);

$maintenance->showForm($id);

Html::footer();
