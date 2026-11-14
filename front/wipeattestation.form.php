<?php

/**
 * Issue #322 : attestation d'effacement des données. Création (depuis l'onglet du matériel),
 * consultation et annulation (motif obligatoire).
 */

use GlpiPlugin\Assetsign\Api\WipeAttestationFormController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\WipeAttestation;

if (isset($_POST['create'])) {
    Session::checkRight(WipeAttestation::$rightname, CREATE);
   try {
       $attestation = (new WipeAttestationFormController())->create($_POST, $_FILES);
       Session::addMessageAfterRedirect(__('Attestation d\'effacement enregistrée et signée.', 'assetsign'));
       Html::redirect(WipeAttestation::getFormURLWithID($attestation->getID()));
   } catch (\InvalidArgumentException $e) {
       throw new \Glpi\Exception\Http\NotFoundHttpException();
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
    Html::back();
}

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0 && $_POST === []) {
    Assetsign::redirectToListWithHint(WipeAttestation::class, __('Une attestation d\'effacement se crée depuis l\'onglet « Effacement des données » du matériel concerné.', 'assetsign'));
}

$attestation = new WipeAttestation();
if (!$attestation->getFromDB($id) || !$attestation->can($id, READ)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (isset($_POST['cancel_attestation'])) {
    $attestation->check($id, UPDATE);
   try {
       $attestation->cancel((string) ($_POST['cancel_reason'] ?? ''), (int) Session::getLoginUserID());
       Session::addMessageAfterRedirect(__('Attestation annulée : elle ne compte plus pour la sortie du matériel.', 'assetsign'));
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
    Html::back();
}

Html::header(WipeAttestation::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Assetsign::class, WipeAttestation::class);
$attestation->showForm($id);
Html::footer();
