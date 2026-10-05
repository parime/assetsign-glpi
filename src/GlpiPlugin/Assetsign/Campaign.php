<?php

namespace GlpiPlugin\Assetsign;

use CronTask;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Pdf\PdfRenderingHelpers;
use Html;
use Migration;
use NotificationEvent;
use Session;

/**
 * Issue #158 : campagne d'attestation annuelle de détention du matériel (inventaire
 * contradictoire, ISO 27001 A.5.9). Un administrateur fixe un périmètre (entité et ses
 * sous-entités, éventuellement un groupe et/ou un profil) et une date limite ; chaque personne
 * du périmètre qui détient au moins un matériel géré reçoit une Attestation (liste figée de son
 * matériel au lancement) à confirmer en signant, ou sur laquelle signaler un écart.
 *
 * Suivi : taux de réponse, écarts, non-répondants, relances automatiques jusqu'à la date limite
 * (tâche planifiée assetsignCampaigns), export CSV et PDF de la campagne comme preuve d'audit.
 */
class Campaign extends Compat\Base\CampaignBase
{
   use PdfRenderingHelpers;

   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   /**
    * Rapport PDF de la campagne (preuve d'audit A.5.9) : synthèse et détail par personne.
    */
   public function renderReportPdf(): string {
       $config = Config::getForEntity((int) $this->fields['entities_id']);
       $html = TemplateRenderer::getInstance()->render('@assetsign/pdf/campaign_report.html.twig', [
           'company_name'  => (string) ($config->fields['company_name'] ?? ''),
           'campaign'      => $this->fields,
           'stats'         => $this->getStats(),
           'lines'         => $this->getReportLines(),
           'status_labels' => Attestation::getStatusLabels(),
           'gap_labels'    => Attestation::getGapLabels(),
           'generated_at'  => Html::convDateTime(date('Y-m-d H:i:s')),
       ]);
       return $this->renderPdf($html);
   }

   public static function getTypeName($nb = 0): string {
       return _n('Campagne d\'attestation', 'Campagnes d\'attestation', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-clipboard-check';
   }

   /**
    * Lance une campagne : une attestation par personne du périmètre détenant du matériel.
    *
    * @param array{name: string, entities_id: int, is_recursive: bool, groups_id: int, profiles_id: int, date_deadline: string} $input
    */
   public static function launch(array $input): ?self {
       $campaign = new self();
       $id = $campaign->add([
           'name'          => trim($input['name']) !== '' ? trim($input['name']) : sprintf(__('Attestation de détention %s', 'assetsign'), date('Y')),
           'entities_id'   => $input['entities_id'],
           'is_recursive'  => $input['is_recursive'] ? 1 : 0,
           'groups_id'     => $input['groups_id'],
           'profiles_id'   => $input['profiles_id'],
           'date_deadline' => $input['date_deadline'] !== '' ? $input['date_deadline'] : null,
           'status'        => CampaignLogic::CAMPAIGN_OPEN,
           'users_id'      => Session::getLoginUserID() ?: 0,
       ]);
      if (!$id) {
          return null;
      }
       $campaign->getFromDB($id);

      foreach ($campaign->findUsersInScope() as $usersId) {
          $items = AssignedItems::find($usersId, (int) $campaign->fields['entities_id']);
         if ($items === []) {
             continue;
         }
          $attestation = new Attestation();
          $attestationId = $attestation->add([
              'plugin_assetsign_campaigns_id' => $id,
              'users_id'                      => $usersId,
              'entities_id'                   => $campaign->fields['entities_id'],
              'status'                        => CampaignLogic::ATTESTATION_PENDING,
              'items'                         => json_encode(array_map([AssignedItems::class, 'snapshot'], $items)),
          ]);
         if ($attestationId) {
             $attestation->getFromDB($attestationId);
             NotificationEvent::raiseEvent('attestation_invitation', $attestation);
         }
      }

       return $campaign;
   }

   /**
    * Personnes actives ayant une habilitation dans le périmètre (entité, sous-entités si
    * récursif), filtrées par groupe et/ou profil si renseignés.
    *
    * @return list<int>
    */
   public function findUsersInScope(): array {
       global $DB;

       $entitiesId = (int) $this->fields['entities_id'];
       $entityIds = !empty($this->fields['is_recursive']) ? array_values(getSonsOf('glpi_entities', $entitiesId)) : [$entitiesId];
       $criteria = [
           'SELECT'     => ['glpi_users.id'],
           'DISTINCT'   => true,
           'FROM'       => 'glpi_users',
           'INNER JOIN' => [
               'glpi_profiles_users' => ['FKEY' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
           ],
           'WHERE'      => [
               'glpi_users.is_active'             => 1,
               'glpi_users.is_deleted'            => 0,
               'glpi_profiles_users.entities_id'  => $entityIds,
           ],
           'ORDER'      => 'glpi_users.id',
       ];
       if ((int) $this->fields['profiles_id'] > 0) {
          $criteria['WHERE']['glpi_profiles_users.profiles_id'] = (int) $this->fields['profiles_id'];
       }
       if ((int) $this->fields['groups_id'] > 0) {
          $criteria['INNER JOIN']['glpi_groups_users'] = ['FKEY' => ['glpi_groups_users' => 'users_id', 'glpi_users' => 'id']];
          $criteria['WHERE']['glpi_groups_users.groups_id'] = (int) $this->fields['groups_id'];
       }

       return array_map('intval', array_column(iterator_to_array($DB->request($criteria), false), 'id'));
   }

   /**
    * @return list<array<string, mixed>>
    */
   public function getAttestations(): array {
       global $DB;

       return iterator_to_array($DB->request([
           'FROM'  => Attestation::getTable(),
           'WHERE' => ['plugin_assetsign_campaigns_id' => $this->getID()],
           'ORDER' => 'id ASC',
       ]), false);
   }

   /**
    * @return array{total: int, confirmed: int, discrepancy: int, pending: int, responded: int, response_rate: int}
    */
   public function getStats(): array {
       return CampaignLogic::stats(array_map('intval', array_column($this->getAttestations(), 'status')));
   }

   public function isOpen(): bool {
       return (int) ($this->fields['status'] ?? -1) === CampaignLogic::CAMPAIGN_OPEN;
   }

   /**
    * Relance toutes les attestations encore en attente (bouton « Relancer les non-répondants »).
    */
   public function remindPending(): int {
       $count = 0;
      foreach ($this->getAttestations() as $row) {
         if ((int) $row['status'] !== CampaignLogic::ATTESTATION_PENDING) {
             continue;
         }
          $attestation = new Attestation();
         if ($attestation->getFromDB((int) $row['id'])) {
             $attestation->sendReminder();
             $count++;
         }
      }
       return $count;
   }

   public function close(): void {
       $this->update(['id' => $this->getID(), 'status' => CampaignLogic::CAMPAIGN_CLOSED]);
   }

   /**
    * Lignes du tableau de suivi et des exports.
    *
    * @return list<array{id: int, user: string, status: int, items: int, date_answered: string|null, comment: string, gaps: list<string>, tickets_id: int, reminders: int}>
    */
   public function getReportLines(): array {
       $lines = [];
      foreach ($this->getAttestations() as $row) {
          $lines[] = [
              'id'            => (int) $row['id'],
              'user'          => getUserName((int) $row['users_id']),
              'status'        => (int) $row['status'],
              'items'         => count(json_decode((string) $row['items'], true) ?: []),
              'date_answered' => $row['date_answered'],
              'comment'       => (string) ($row['comment'] ?? ''),
              'gaps'          => CampaignLogic::sanitizeGaps(json_decode((string) ($row['gaps'] ?? ''), true)),
              'tickets_id'    => (int) $row['tickets_id'],
              'reminders'     => (int) $row['reminder_count'],
          ];
      }
       return $lines;
   }

   /**
    * @return array{description: string}|array{}
    */
   public static function cronInfo(string $name): array {
       return $name === 'assetsignCampaigns'
           ? ['description' => __('Relance les attestations de détention en attente jusqu\'à la date limite de leur campagne', 'assetsign')]
           : [];
   }

   public static function cronAssetsignCampaigns(CronTask $task): int {
       $count = self::runReminders();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

   /**
    * Relances automatiques : attestations en attente des campagnes ouvertes dont la date limite
    * n'est pas dépassée, même calendrier que les fiches (Config::getReminderDelays()).
    */
   public static function runReminders(): int {
       global $DB;

       $count = 0;
       $now = time();
      foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['status' => CampaignLogic::CAMPAIGN_OPEN]]) as $campaignRow) {
         if (CampaignLogic::isPastDeadline($campaignRow['date_deadline'], $now)) {
             continue;
         }
          $config = Config::getForEntity((int) $campaignRow['entities_id']);
         foreach ($DB->request([
                 'FROM'  => Attestation::getTable(),
                 'WHERE' => ['plugin_assetsign_campaigns_id' => $campaignRow['id'], 'status' => CampaignLogic::ATTESTATION_PENDING],
             ]) as $row
         ) {
             $days = (int) floor(($now - (int) strtotime((string) $row['date_creation'])) / 86400);
             $sinceLast = $row['date_last_reminder'] !== null
                 ? (int) floor(($now - (int) strtotime((string) $row['date_last_reminder'])) / 86400)
                 : null;
             $due = DepartureLogic::reminderDue(
                 $days,
                 $sinceLast,
                 (int) $row['reminder_count'],
                 $config->getReminderDelays(),
                 (int) $config->fields['max_reminders']
             );
            if (!$due) {
               continue;
            }
             $attestation = new Attestation();
            if ($attestation->getFromDB((int) $row['id'])) {
               $attestation->sendReminder();
               $count++;
            }
         }
      }
       return $count;
   }

   public static function install(Migration $migration): void {
       global $DB;

       $table = self::getTable();
      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `groups_id` int unsigned NOT NULL DEFAULT 0,
                `profiles_id` int unsigned NOT NULL DEFAULT 0,
                `date_deadline` date DEFAULT NULL,
                `status` tinyint NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
