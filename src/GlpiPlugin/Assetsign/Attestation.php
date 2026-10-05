<?php

namespace GlpiPlugin\Assetsign;

use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Pdf\PdfRenderingHelpers;
use GlpiPlugin\Assetsign\Pdf\SignatureImageValidator;
use Html;
use Item_Ticket;
use Migration;
use NotificationEvent;
use Ticket;
use User;

/**
 * Issue #158 : attestation de détention d'une personne dans une campagne (Campaign). La liste du
 * matériel est figée au lancement (`items`, JSON) : la personne atteste ce que l'inventaire GLPI
 * lui attribuait à cette date. Deux réponses possibles, depuis sa propre session GLPI (lien de
 * l'e-mail ou page « Mes documents à signer », pour les personnes sans e-mail) :
 *
 * - confirmer et signer : PDF d'attestation signé (Document GLPI lié à l'attestation et au
 *   compte), empreinte SHA-256 conservée ;
 * - signaler un écart (matériel manquant, en trop ou inconnu) avec un commentaire : un ticket GLPI
 *   est ouvert au nom de la personne, rattaché au matériel listé, et le gestionnaire du parc qui a
 *   lancé la campagne est prévenu.
 */
class Attestation extends Compat\Base\AttestationBase
{
   use PdfRenderingHelpers;

   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   public static function getTypeName($nb = 0): string {
       return _n('Attestation de détention', 'Attestations de détention', $nb, 'assetsign');
   }

   /**
    * @return array<int, string>
    */
   public static function getStatusLabels(): array {
       return [
           CampaignLogic::ATTESTATION_PENDING     => __('En attente', 'assetsign'),
           CampaignLogic::ATTESTATION_CONFIRMED   => __('Confirmée', 'assetsign'),
           CampaignLogic::ATTESTATION_DISCREPANCY => __('Écart signalé', 'assetsign'),
       ];
   }

   /**
    * @return array<string, string>
    */
   public static function getGapLabels(): array {
       return [
           CampaignLogic::GAP_MISSING => __('Je n\'ai plus un matériel de la liste', 'assetsign'),
           CampaignLogic::GAP_EXTRA   => __('Je détiens un matériel absent de la liste', 'assetsign'),
           CampaignLogic::GAP_UNKNOWN => __('Je ne reconnais pas un matériel de la liste', 'assetsign'),
       ];
   }

   /**
    * @return list<array{itemtype: string, items_id: int, type_label: string, name: string, serial: string, otherserial: string}>
    */
   public function getItems(): array {
       $items = json_decode((string) ($this->fields['items'] ?? ''), true);
       return is_array($items) ? array_values($items) : [];
   }

   public function getCampaign(): ?Campaign {
       $campaign = new Campaign();
       return $campaign->getFromDB((int) $this->fields['plugin_assetsign_campaigns_id']) ? $campaign : null;
   }

   /**
    * Ouverte à une réponse : en attente et campagne encore ouverte.
    */
   public function isAnswerable(): bool {
       $campaign = $this->getCampaign();
       return (int) $this->fields['status'] === CampaignLogic::ATTESTATION_PENDING
           && $campaign !== null && $campaign->isOpen();
   }

   public function getUrl(): string {
       $base = rtrim($GLOBALS['CFG_GLPI']['url_base'] ?? '', '/');
       return $base . '/plugins/assetsign/front/attestation.form.php?id=' . $this->getID();
   }

   /**
    * @param array{ip?: string, user_agent?: string} $meta
    */
   public function confirm(string $signaturePngDataUrl, array $meta): void {
      if (!$this->isAnswerable()) {
          throw new \RuntimeException(__('Cette attestation n\'attend plus de réponse.', 'assetsign'));
      }
       SignatureImageValidator::assertValid($signaturePngDataUrl);

       $signedAt = date('Y-m-d H:i:s');
       $user = new User();
       $user->getFromDB((int) $this->fields['users_id']);
       $campaign = $this->getCampaign();
       $config = Config::getForEntity((int) $this->fields['entities_id']);

       $html = TemplateRenderer::getInstance()->render('@assetsign/pdf/attestation.html.twig', [
           'company_name'    => (string) ($config->fields['company_name'] ?? ''),
           'campaign_name'   => $campaign !== null ? (string) $campaign->fields['name'] : '',
           'user_name'       => trim(\formatUserName(0, $user->fields['name'] ?? '', $user->fields['realname'] ?? '', $user->fields['firstname'] ?? '')),
           'items'           => $this->getItems(),
           'signature_image' => $signaturePngDataUrl,
           'signed_at'       => Html::convDateTime($signedAt),
           'ip_address'      => $meta['ip'] ?? '',
       ]);
       $binary = $this->renderPdf($html, (bool) ($config->fields['protect_pdf'] ?? false));
       $documentsId = $this->storePdf($binary, $user);

       $this->update([
           'id'             => $this->getID(),
           'status'         => CampaignLogic::ATTESTATION_CONFIRMED,
           'date_answered'  => $signedAt,
           'documents_id'   => $documentsId,
           'signature_hash' => hash('sha256', $binary),
           'ip_address'     => $meta['ip'] ?? '',
       ]);
   }

   /**
    * @param list<string> $gaps types d'écart (CampaignLogic::GAP_*)
    */
   public function reportDiscrepancy(array $gaps, string $comment): void {
      if (!$this->isAnswerable()) {
          throw new \RuntimeException(__('Cette attestation n\'attend plus de réponse.', 'assetsign'));
      }
       $gaps = CampaignLogic::sanitizeGaps($gaps);
       $comment = trim($comment);
      if ($comment === '') {
          throw new \RuntimeException(__('Décrivez l\'écart constaté : quel matériel, et ce qui ne correspond pas.', 'assetsign'));
      }

       $campaign = $this->getCampaign();
       $gapLabels = self::getGapLabels();
       $content = '<p>' . htmlescape(sprintf(
           __('Écart d\'inventaire signalé lors de la campagne « %s ».', 'assetsign'),
           $campaign !== null ? $campaign->fields['name'] : ''
       )) . '</p>';
      if ($gaps !== []) {
          $content .= '<ul>';
         foreach ($gaps as $gap) {
             $content .= '<li>' . htmlescape($gapLabels[$gap]) . '</li>';
         }
          $content .= '</ul>';
      }
       $content .= '<p>' . nl2br(htmlescape($comment)) . '</p><p>' . htmlescape(__('Matériel listé :', 'assetsign')) . '</p><ul>';
      foreach ($this->getItems() as $item) {
          $content .= '<li>' . htmlescape(trim($item['type_label'] . ' ' . $item['name'] . ' ' . $item['serial'])) . '</li>';
      }
       $content .= '</ul>';

       $ticket = new Ticket();
       $ticketId = (int) $ticket->add([
           'name'                => sprintf(__('Écart d\'inventaire : %s', 'assetsign'), getUserName((int) $this->fields['users_id'])),
           'content'             => $content,
           'entities_id'         => (int) $this->fields['entities_id'],
           'type'                => Ticket::DEMAND_TYPE,
           '_users_id_requester' => (int) $this->fields['users_id'],
       ]);
      if ($ticketId > 0) {
         foreach ($this->getItems() as $item) {
             (new Item_Ticket())->add(['tickets_id' => $ticketId, 'itemtype' => $item['itemtype'], 'items_id' => $item['items_id']]);
         }
      }

       $this->update([
           'id'            => $this->getID(),
           'status'        => CampaignLogic::ATTESTATION_DISCREPANCY,
           'date_answered' => date('Y-m-d H:i:s'),
           'gaps'          => json_encode($gaps),
           'comment'       => $comment,
           'tickets_id'    => $ticketId,
       ]);
       NotificationEvent::raiseEvent('attestation_discrepancy', $this);
   }

   public function sendReminder(): void {
       $this->update([
           'id'                 => $this->getID(),
           'reminder_count'     => (int) $this->fields['reminder_count'] + 1,
           'date_last_reminder' => date('Y-m-d H:i:s'),
       ]);
       NotificationEvent::raiseEvent('attestation_reminder', $this);
   }

   private function storePdf(string $binary, User $user): int {
       $tmpName = uniqid('assetsign_attestation_', true) . '.pdf';
      if (file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $binary) === false) {
          return 0;
      }
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $document = new Document();
       $documentsId = (int) $document->add([
           'name'          => sprintf(__('Attestation de détention - %s', 'assetsign'), $user->getFriendlyName()),
           'entities_id'   => $this->fields['entities_id'],
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);
       // Messages techniques de Document::add() sans intérêt pour la personne qui atteste.
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if ($documentsId > 0) {
         foreach ([[self::class, $this->getID()], ['User', $user->getID()]] as [$itemtype, $itemsId]) {
             (new Document_Item())->add(['documents_id' => $documentsId, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
         }
      }
       return $documentsId;
   }

   public static function install(Migration $migration): void {
       global $DB;

       $table = self::getTable();
      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_campaigns_id` int unsigned NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 0,
                `items` longtext,
                `gaps` text,
                `comment` text,
                `tickets_id` int unsigned NOT NULL DEFAULT 0,
                `documents_id` int unsigned NOT NULL DEFAULT 0,
                `signature_hash` varchar(64) NOT NULL DEFAULT '',
                `ip_address` varchar(255) NOT NULL DEFAULT '',
                `reminder_count` int unsigned NOT NULL DEFAULT 0,
                `date_last_reminder` timestamp NULL DEFAULT NULL,
                `date_answered` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_campaigns_id`, `users_id`),
                KEY `users_id` (`users_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
