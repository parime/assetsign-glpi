<?php

/**
 * Issue #158 : réponse d'une personne à son attestation de détention — confirmer et signer, ou
 * signaler un écart (ticket ouvert à son nom). Consultation aussi pour les gestionnaires.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Attestation;
use GlpiPlugin\Assetsign\CampaignLogic;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

Session::checkLoginUser();

$usersId = (int) Session::getLoginUserID();
$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/attestation.form.php';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$attestation = new Attestation();
if ($id <= 0 || !$attestation->getFromDB($id)) {
    Html::displayNotFoundError();
}
$isHolder = (int) $attestation->fields['users_id'] === $usersId;
$canRead = Session::haveRight(Profile::RIGHT_ASSETSIGN, READ);
if (!$isHolder && !$canRead) {
    Html::displayRightError();
}

if (isset($_GET['pdf'])) {
    $document = new Document();
   if (!$document->getFromDB((int) $attestation->fields['documents_id'])) {
       Html::displayNotFoundError();
   }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename((string) $document->fields['filename']) . '"');
    readfile(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
    exit;
}

if ($isHolder && (isset($_POST['confirm']) || isset($_POST['report']))) {
   try {
      if (isset($_POST['confirm'])) {
         if (empty($_POST['consent'])) {
            throw new \RuntimeException(__('Merci de cocher la case de confirmation avant de signer.', 'assetsign'));
         }
          $attestation->confirm((string) ($_POST['signature'] ?? ''), [
              'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
              'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
          ]);
          Session::addMessageAfterRedirect(__('Attestation signée. Merci !', 'assetsign'));
      } else {
          $attestation->reportDiscrepancy(
              CampaignLogic::sanitizeGaps($_POST['gaps'] ?? []),
              (string) ($_POST['comment'] ?? '')
          );
          Session::addMessageAfterRedirect(__('Écart signalé : un ticket a été ouvert, le gestionnaire du parc va vous recontacter.', 'assetsign'));
      }
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
   }
    Html::redirect($selfUrl . '?id=' . $id);
}

$title = Attestation::getTypeName(1);
$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
if ($isHelpdesk) {
    Html::helpHeader($title);
} else {
    Html::header($title, $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Campaign::class);
}

$campaign = $attestation->getCampaign();
TemplateRenderer::getInstance()->display('@assetsign/attestation_form.html.twig', [
    'attestation'   => $attestation->fields,
    'user_name'     => getUserName((int) $attestation->fields['users_id']),
    'campaign_name' => $campaign !== null ? (string) $campaign->fields['name'] : '',
    'deadline'      => $campaign !== null ? $campaign->fields['date_deadline'] : null,
    'items'         => $attestation->getItems(),
    'status_label'  => Attestation::getStatusLabels()[(int) $attestation->fields['status']] ?? '',
    'gap_labels'    => Attestation::getGapLabels(),
    'gaps'          => CampaignLogic::sanitizeGaps(json_decode((string) ($attestation->fields['gaps'] ?? ''), true)),
    'can_answer'    => $isHolder && $attestation->isAnswerable(),
    'pdf_url'       => (int) $attestation->fields['documents_id'] > 0 ? $selfUrl . '?id=' . $id . '&pdf=1' : null,
    'ticket_url'    => (int) $attestation->fields['tickets_id'] > 0 ? $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int) $attestation->fields['tickets_id'] : null,
    'action'        => $selfUrl,
    'csrf_token'    => Session::getNewCSRFToken(),
]);

if ($isHelpdesk) {
    Html::helpFooter();
} else {
    Html::footer();
}
