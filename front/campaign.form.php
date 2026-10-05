<?php

/**
 * Issue #158 : création d'une campagne d'attestation de détention (formulaire sans id), suivi
 * d'une campagne (taux de réponse, écarts, non-répondants), relance, clôture, exports CSV/PDF.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Attestation;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\CampaignLogic;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);
$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/campaign.form.php';

if (isset($_POST['launch'])) {
   Session::checkRight(Profile::RIGHT_ASSETSIGN, UPDATE);
   $campaign = Campaign::launch([
      'name'          => (string) ($_POST['name'] ?? ''),
      'entities_id'   => (int) ($_POST['entities_id'] ?? Session::getActiveEntity()),
      'is_recursive'  => !empty($_POST['is_recursive']),
      'groups_id'     => (int) ($_POST['groups_id'] ?? 0),
      'profiles_id'   => (int) ($_POST['profiles_id'] ?? 0),
      'date_deadline' => (string) ($_POST['date_deadline'] ?? ''),
   ]);
   if ($campaign === null) {
      Session::addMessageAfterRedirect(__('La campagne n\'a pas pu être créée.', 'assetsign'), false, ERROR);
      Html::back();
   }
   Session::addMessageAfterRedirect(sprintf(
      __('Campagne lancée : %d personne(s) invitée(s) à attester leur matériel.', 'assetsign'),
      $campaign->getStats()['total']
   ));
   Html::redirect($selfUrl . '?id=' . $campaign->getID());
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$campaign = new Campaign();
if ($id > 0 && !$campaign->getFromDB($id)) {
   Html::displayNotFoundError();
}

if ($id > 0 && (isset($_POST['remind']) || isset($_POST['close']))) {
   Session::checkRight(Profile::RIGHT_ASSETSIGN, UPDATE);
   if (isset($_POST['remind'])) {
      Session::addMessageAfterRedirect(sprintf(__('%d relance(s) envoyée(s).', 'assetsign'), $campaign->remindPending()));
   } else {
      $campaign->close();
      Session::addMessageAfterRedirect(__('Campagne clôturée : les attestations ne peuvent plus être modifiées.', 'assetsign'));
   }
   Html::redirect($selfUrl . '?id=' . $id);
}

$statusLabels = Attestation::getStatusLabels();
$gapLabels = Attestation::getGapLabels();

if ($id > 0 && ($_GET['export'] ?? '') === 'csv') {
   // Preuve d'audit : une ligne par personne, séparateur « ; » (Excel en français), BOM UTF-8.
   header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="attestations-campagne-' . $id . '.csv"');
   $out = fopen('php://output', 'w');
   fwrite($out, "\xEF\xBB\xBF");
   fputcsv($out, [
      __('Personne', 'assetsign'), __('Statut'), __('Matériels', 'assetsign'), __('Date de réponse', 'assetsign'),
      __('Écart', 'assetsign'), __('Commentaire', 'assetsign'), __('Ticket', 'assetsign'), __('Relances', 'assetsign'),
   ], ';');
   foreach ($campaign->getReportLines() as $line) {
      fputcsv($out, [
         $line['user'],
         $statusLabels[$line['status']] ?? '',
         $line['items'],
         $line['date_answered'] ?? '',
         implode(', ', array_map(static fn (string $g): string => $gapLabels[$g], $line['gaps'])),
         $line['comment'],
         $line['tickets_id'] > 0 ? $line['tickets_id'] : '',
         $line['reminders'],
      ], ';');
   }
   fclose($out);
   exit;
}

if ($id > 0 && ($_GET['export'] ?? '') === 'pdf') {
   $pdf = $campaign->renderReportPdf();
   header('Content-Type: application/pdf');
   header('Content-Disposition: attachment; filename="attestations-campagne-' . $id . '.pdf"');
   echo $pdf;
   exit;
}

Html::header(Campaign::getTypeName(1), $_SERVER['PHP_SELF'], 'tools', Campaign::class);

$groupDropdown = '';
$profileDropdown = '';
if ($id === 0) {
   $groupDropdown = Group::dropdown([
      'name'        => 'groups_id',
      'display'     => false,
      'entity'      => Session::getActiveEntity(),
      'entity_sons' => true,
   ]);
   $profileDropdown = \Profile::dropdown(['name' => 'profiles_id', 'display' => false]);
}

TemplateRenderer::getInstance()->display('@assetsign/campaign_form.html.twig', [
   'campaign'         => $id > 0 ? $campaign->fields : null,
   'is_open'          => $id > 0 && $campaign->isOpen(),
   'is_past_deadline' => $id > 0 && CampaignLogic::isPastDeadline($campaign->fields['date_deadline'], time()),
   'stats'            => $id > 0 ? $campaign->getStats() : null,
   'lines'            => $id > 0 ? $campaign->getReportLines() : [],
   'status_labels'    => $statusLabels,
   'gap_labels'       => $gapLabels,
   'can_manage'       => Session::haveRight(Profile::RIGHT_ASSETSIGN, UPDATE),
   'entity_id'        => Session::getActiveEntity(),
   'entity_name'      => Dropdown::getDropdownName('glpi_entities', Session::getActiveEntity()),
   'group_dropdown'   => $groupDropdown,
   'profile_dropdown' => $profileDropdown,
   'default_deadline' => date('Y-m-d', strtotime('+30 days')),
   'action'           => $selfUrl,
   'attestation_url'  => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/attestation.form.php?id=',
   'ticket_url'       => $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=',
   'list_url'         => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/campaign.php',
   'csrf_token'       => Session::getNewCSRFToken(),
]);

Html::footer();
