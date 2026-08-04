<?php

use GlpiPlugin\Remise\Config;

Session::checkRight(Config::$rightname, READ);

if (isset($_POST['update'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    // Pas de Session::checkCSRF() ici : le pare-feu GLPI (CheckCsrfListener) valide
    // deja le jeton de maniere centrale pour toute requete POST, et consomme le
    // jeton a usage unique — le revalider ici echouerait a tort.
    $entities_id = (int) ($_POST['entities_id'] ?? 0);

    $input = $_POST;
    // Par defaut, ne touche pas au logo existant (le champ de televersement est
    // vide a chaque affichage du formulaire, l'absence de nouveau fichier ne
    // doit donc jamais etre interpretee comme "retirer le logo").
    $input['logo_documents_id'] = (int) Config::getForEntity($entities_id)->fields['logo_documents_id'];

   if (!empty($_POST['remove_logo'])) {
       $input['logo_documents_id'] = 0;
   } else if (!empty($_FILES['logo_file']['name'] ?? '')) {
       $newLogoId = Config::uploadLogo($_FILES['logo_file'], $entities_id);
      if ($newLogoId > 0) {
          $input['logo_documents_id'] = $newLogoId;
      }
   }

    Config::upsertForEntity($entities_id, $input);
    Session::addMessageAfterRedirect(__('Configuration enregistrée.', 'remise'));
    Html::back();
}

if (isset($_POST['send_test_email'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    $entities_id = (int) ($_POST['entities_id'] ?? 0);
    $error = Config::sendTestEmail($entities_id);
   if ($error === '') {
       Session::addMessageAfterRedirect(__('E-mail de test envoyé.', 'remise'));
   } else {
       Session::addMessageAfterRedirect($error, false, ERROR);
   }
    Html::back();
}

// Page autonome accessible depuis le menu d'administration du plugin (en plus
// de l'onglet "Remise & signature" sur la fiche d'une entite) : par defaut,
// affiche/edite la configuration de l'entite active de la session.
$entities_id = (int) ($_GET['entities_id'] ?? Session::getActiveEntity());

Html::header(Config::getTypeName(), $_SERVER['PHP_SELF'], 'admin', Config::class);

Config::showConfigForm($entities_id);

Html::footer();
