<?php

/**
 * Issue #157 : dossier de départ d'un salarié — consultation, signature groupée de la restitution
 * de tout son matériel (par la personne elle-même, ou sur place sur l'écran du technicien),
 * relance, annulation, PDF récapitulatif. Préparation manuelle depuis l'onglet Assetsign du compte
 * utilisateur (POST « prepare »).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Departure;
use GlpiPlugin\Assetsign\DepartureLogic;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

Session::checkLoginUser();

$usersId = (int) Session::getLoginUserID();
$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/departure.form.php';

if (isset($_POST['prepare'])) {
   Session::checkRight(Profile::RIGHT_ASSETSIGN, UPDATE);
   // Le dossier est cree dans l'entite par defaut de la personne (Departure::prepareFor()) : une
   // entite hors du perimetre du technicien est refusee, comme partout ailleurs dans le plugin.
   $leavingUser = new User();
   if (!$leavingUser->getFromDB((int) ($_POST['users_id'] ?? 0)) || !Session::haveAccessToEntity((int) $leavingUser->fields['entities_id'])) {
      throw new \Glpi\Exception\Http\AccessDeniedHttpException();
   }
   // Messages techniques de Document::add() (« Création du répertoire PDF/… », un par PDF
   // généré) retirés : seul le message de synthèse ci-dessous a du sens pour l'utilisateur.
   $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
   $departure = Departure::prepareFor((int) ($_POST['users_id'] ?? 0), DepartureLogic::TRIGGER_MANUAL);
   $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
   if ($departure === null) {
      Session::addMessageAfterRedirect(__('Aucun matériel géré par assetsign n\'est affecté à cette personne : rien à restituer.', 'assetsign'), false, WARNING);
      Html::back();
   }
   Session::addMessageAfterRedirect(sprintf(
      __('Départ préparé : %d matériel(s) à restituer, l\'utilisateur est invité à signer.', 'assetsign'),
      count($departure->getChildren())
   ));
   Html::redirect($selfUrl . '?id=' . $departure->getID());
}

$departure = new Departure();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0 && $_POST === []) {
   \GlpiPlugin\Assetsign\Assetsign::redirectToListWithHint(Departure::class, __('Un départ se prépare depuis la fiche de la personne, onglet Attributions (bouton « Départ de la personne »).', 'assetsign'));
}
if ($id <= 0 || !$departure->getFromDB($id)) {
   throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$isLeavingUser = (int) $departure->fields['users_id'] === $usersId;
// can() et non le seul droit global : il verifie aussi l'entite du dossier, sinon tout gestionnaire
// pourrait consulter, relancer, annuler ou faire signer sur place le depart d'une autre entite.
$canRead = $departure->can($id, READ);
$canManage = $departure->can($id, UPDATE);
if (!$isLeavingUser && !$canRead) {
   throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$config = Config::getForEntity((int) $departure->fields['entities_id']);
$canSignInPerson = $canManage && !$isLeavingUser && !empty($config->fields['enable_in_person_signature']);

if (isset($_GET['pdf'])) {
   $document = new Document();
   if (!$document->getFromDB((int) $departure->fields['document_id_summary'])) {
      throw new \Glpi\Exception\Http\NotFoundHttpException();
   }
   $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
   header('Content-Type: application/pdf');
   header('Content-Disposition: inline; filename="' . basename((string) $document->fields['filename']) . '"');
   readfile($path);
   exit;
}

if (isset($_POST['sign'])) {
   try {
      if (empty($_POST['consent'])) {
         throw new \RuntimeException(__('Merci de cocher la case de confirmation avant de signer.', 'assetsign'));
      }
      $witness = null;
      if (!$isLeavingUser) {
         if (!$canSignInPerson) {
            throw new \RuntimeException(__('Vous n\'êtes pas autorisé à signer ce départ.', 'assetsign'));
         }
         $witness = $usersId;
      }
      $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
      $departure->sign((string) ($_POST['signature'] ?? ''), [
         'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
         'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
      ], $witness);
      $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      Session::addMessageAfterRedirect(__('Restitution signée. Merci !', 'assetsign'));
   } catch (\RuntimeException $e) {
      Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
   Html::redirect($selfUrl . '?id=' . $id);
}

if (isset($_POST['remind']) || isset($_POST['cancel'])) {
   if (!$canManage) {
      throw new \Glpi\Exception\Http\AccessDeniedHttpException();
   }
   try {
      if (isset($_POST['remind'])) {
         $departure->sendReminderNow();
         Session::addMessageAfterRedirect(__('Relance envoyée.', 'assetsign'));
      } else {
         $departure->cancel();
         Session::addMessageAfterRedirect(__('Départ annulé, ses fiches en attente aussi.', 'assetsign'));
      }
   } catch (\RuntimeException $e) {
      Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
   Html::redirect($selfUrl . '?id=' . $id);
}

$title = Departure::getTypeName(1);
$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
if ($isHelpdesk) {
   Html::helpHeader($title);
} else {
   Html::header($title, $_SERVER['PHP_SELF'], 'tools', Departure::class);
}

$lines = $departure->getItemLines();
TemplateRenderer::getInstance()->display('@assetsign/departure_form.html.twig', [
   'departure'          => $departure->fields,
   'user_name'          => getUserName((int) $departure->fields['users_id']),
   'tech_name'          => getUserName((int) $departure->fields['users_id_tech']),
   'trigger_label'      => Departure::getTriggerLabels()[(int) $departure->fields['trigger_type']] ?? '',
   'status_label'       => Departure::getStatusLabels()[(int) $departure->fields['status']] ?? '',
   'is_open'            => $departure->isOpen(),
   'lines'              => $lines,
   'signed_count'       => count(array_filter($lines, static fn (array $l): bool => $l['status'] === \GlpiPlugin\Assetsign\Assetsign::STATUS_SIGNED)),
   'is_leaving_user'    => $isLeavingUser,
   'can_manage'         => $canManage,
   'can_sign_in_person' => $canSignInPerson,
   'show_item_links'    => $canRead,
   'summary_url'        => (int) $departure->fields['document_id_summary'] > 0 ? $selfUrl . '?id=' . $id . '&pdf=1' : null,
   'assetsign_url'      => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/assetsign.form.php?id=',
   'action'             => $selfUrl,
   'list_url'           => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/departure.php',
   'csrf_token'         => Session::getNewCSRFToken(),
]);

if ($isHelpdesk) {
   Html::helpFooter();
} else {
   Html::footer();
}
