<?php

namespace GlpiPlugin\Assetsign;

use CronTask;
use Glpi\Application\View\TemplateRenderer;
use Glpi\RichText\RichText;
use GlpiPlugin\Assetsign\Pdf\PdfLanguage;
use GlpiPlugin\Assetsign\Pdf\PdfRenderingHelpers;
use Html;
use Migration;
use NotificationEvent;
use Session;
use Ticket;

/**
 * Issue #158 : campagne d'attestation annuelle de détention du matériel (inventaire
 * contradictoire, ISO 27001 A.5.9). Un administrateur fixe un périmètre (entité et ses
 * sous-entités, éventuellement un groupe et/ou un profil) et une date limite ; chaque personne
 * du périmètre qui détient au moins un matériel géré reçoit une Attestation (liste figée de son
 * matériel au lancement) à confirmer en signant, ou sur laquelle signaler un écart.
 *
 * Issue #204 : même mécanisme pour la charte informatique (type CampaignLogic::TYPE_CHARTER,
 * ISO 27001 A.5.10 et A.6.3) : chaque personne du périmètre signe la charte figée au lancement ;
 * à l'échéance, un ticket liste les non-signataires (aucun compte n'est bloqué). Renouvellement
 * automatique paramétrable par entité (Configuration, tâche planifiée assetsignCampaigns).
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
           'html_lang'     => PdfLanguage::htmlLang(),
           'company_name'=> (string) ($config->fields['company_name'] ?? ''),
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
    * Lance une campagne : une attestation par personne du périmètre détenant du matériel, ou, pour
    * une campagne de charte (issue #204), par personne du périmètre, la charte en vigueur étant
    * figée dans la campagne au lancement.
    *
    * @param array{name: string, entities_id: int, is_recursive: bool, groups_id: int, profiles_id: int, date_deadline: string, type?: int, only_outdated?: bool} $input
    * @throws \RuntimeException campagne de charte sans charte configurée pour l'entité
    */
   public static function launch(array $input): ?self {
       $type = (int) ($input['type'] ?? CampaignLogic::TYPE_HOLDING) === CampaignLogic::TYPE_CHARTER
           ? CampaignLogic::TYPE_CHARTER
           : CampaignLogic::TYPE_HOLDING;
       $charter = '';
      if ($type === CampaignLogic::TYPE_CHARTER) {
          $charter = self::getCharterContent((int) $input['entities_id']);
         if ($charter === '') {
             throw new \RuntimeException(__('Aucune charte informatique n\'est configurée pour cette entité : renseignez la charte du gabarit d\'attribution par défaut ou le lien vers la charte dans la Configuration.', 'assetsign'));
         }
          // Deux campagnes de charte en cours sur la même entité : chacun devrait signer deux fois,
          // et le taux de signature (repris par grcmanager) ne suivrait que la plus récente.
          $running = self::findRunningCharter((int) $input['entities_id']);
         if ($running !== null) {
             throw new \RuntimeException(sprintf(
                 __('La campagne de charte « %s » est encore en cours pour cette entité : attendez sa date limite ou clôturez-la avant d\'en lancer une nouvelle.', 'assetsign'),
                 $running
             ));
         }
      }
       // Issue #224 : re-signature d'une nouvelle version, limitee aux personnes dont la derniere
       // signature porte sur une ancienne version.
       $restrictTo = null;
      if ($type === CampaignLogic::TYPE_CHARTER && !empty($input['only_outdated'])) {
          $restrictTo = array_flip(self::outdatedSignerIds((int) $input['entities_id']));
         if ($restrictTo === []) {
             throw new \RuntimeException(__('Personne n\'a signé une ancienne version de la charte : aucune re-signature à lancer.', 'assetsign'));
         }
      }
       $defaultName = $type === CampaignLogic::TYPE_CHARTER
           ? ($restrictTo !== null
               ? sprintf(__('Charte informatique, nouvelle version du %s', 'assetsign'), \Html::convDate(date('Y-m-d')))
               : sprintf(__('Charte informatique %s', 'assetsign'), date('Y')))
           : sprintf(__('Attestation de détention %s', 'assetsign'), date('Y'));

       $campaign = new self();
       $id = $campaign->add([
           'name'            => trim($input['name']) !== '' ? trim($input['name']) : $defaultName,
           'type'            => $type,
           'charter_content' => $charter,
           'charter_hash'    => CampaignLogic::charterHash($charter),
           'entities_id'     => $input['entities_id'],
           'is_recursive'    => $input['is_recursive'] ? 1 : 0,
           'groups_id'       => $input['groups_id'],
           'profiles_id'     => $input['profiles_id'],
           'date_deadline'   => $input['date_deadline'] !== '' ? $input['date_deadline'] : null,
           'status'          => CampaignLogic::CAMPAIGN_OPEN,
           'users_id'        => Session::getLoginUserID() ?: 0,
       ]);
      if (!$id) {
          return null;
      }
       $campaign->getFromDB($id);

      foreach ($campaign->findUsersInScope() as $usersId) {
         if ($restrictTo !== null && !isset($restrictTo[$usersId])) {
             continue;
         }
          $items = $type === CampaignLogic::TYPE_CHARTER ? [] : AssignedItems::find($usersId, (int) $campaign->fields['entities_id']);
         if ($type === CampaignLogic::TYPE_HOLDING && $items === []) {
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
             NotificationEvent::raiseEvent($campaign->isCharter() ? 'charter_invitation' : 'attestation_invitation', $attestation);
         }
      }

       return $campaign;
   }

   /**
    * Nom de la campagne de charte encore en cours (ouverte et non échue) de l'entité, ou null.
    * Une campagne échue reste ouverte pour les signatures tardives sans bloquer la suivante.
    */
   public static function findRunningCharter(int $entitiesId): ?string {
       global $DB;

       $row = $DB->request([
           'SELECT' => ['name'],
           'FROM'   => self::getTable(),
           'WHERE'  => [
               'type'        => CampaignLogic::TYPE_CHARTER,
               'status'      => CampaignLogic::CAMPAIGN_OPEN,
               'entities_id' => $entitiesId,
               'OR'          => [
                   ['date_deadline' => null],
                   ['date_deadline' => ['>=', date('Y-m-d')]],
               ],
           ],
           'LIMIT'  => 1,
       ])->current();
       return $row !== null ? (string) $row['name'] : null;
   }

   public function isCharter(): bool {
       return (int) ($this->fields['type'] ?? CampaignLogic::TYPE_HOLDING) === CampaignLogic::TYPE_CHARTER;
   }

   /**
    * Charte en vigueur pour une entité (issue #204) : texte de la charte du gabarit d'attribution
    * par défaut, suivi du lien vers la charte complète s'il est renseigné. HTML assaini, chaîne
    * vide si rien n'est configuré.
    */
   public static function getCharterContent(int $entitiesId): string {
       $template = Template::getDefaultFor(Assetsign::TYPE_HANDOVER, $entitiesId);
       $html = trim((string) ($template?->fields['charter_content'] ?? ''));
       $url = (string) (Config::getForEntity($entitiesId)->fields['charter_url'] ?? '');
      if ($url !== '') {
          $html .= '<p><a href="' . htmlescape($url) . '">' . htmlescape($url) . '</a></p>';
      }
       return $html === '' ? '' : RichText::getSafeHtml($html);
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
           ? ['description' => __('Campagnes d\'attestation et de charte : relances jusqu\'à la date limite, ticket des chartes non signées, renouvellement annuel de la charte', 'assetsign')]
           : [];
   }

   public static function cronAssetsignCampaigns(CronTask $task): int {
       $count = self::runReminders() + self::runCharterFollowUp() + self::runCharterRenewals()
           + self::runCharterVersionChecks();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

   /**
    * Issue #204 : une fois l'échéance d'une campagne de charte passée, un ticket (un seul par
    * campagne) liste les personnes qui ne l'ont pas signée, pour suite à donner par le
    * gestionnaire. Aucun compte n'est bloqué.
    *
    * @return int nombre de tickets créés
    */
   public static function runCharterFollowUp(): int {
       global $DB;

       $count = 0;
      foreach ($DB->request([
              'FROM'  => self::getTable(),
              'WHERE' => ['status' => CampaignLogic::CAMPAIGN_OPEN, 'type' => CampaignLogic::TYPE_CHARTER, 'tickets_id' => 0],
          ]) as $row
      ) {
          $campaign = new self();
         if (!$campaign->getFromDB((int) $row['id'])) {
             continue;
         }
          $pending = array_values(array_filter(
              $campaign->getAttestations(),
              static fn (array $a): bool => (int) $a['status'] === CampaignLogic::ATTESTATION_PENDING
          ));
         if (!CampaignLogic::charterTicketDue($row['date_deadline'], 0, count($pending), time())) {
             continue;
         }
          $content = '<p>' . htmlescape(sprintf(
              __('La charte informatique de la campagne « %1$s » n\'a pas été signée avant le %2$s par :', 'assetsign'),
              $campaign->fields['name'],
              Html::convDate($campaign->fields['date_deadline'])
          )) . '</p><ul>';
         foreach ($pending as $attestation) {
             $content .= '<li>' . htmlescape(getUserName((int) $attestation['users_id'])) . '</li>';
         }
          $content .= '</ul>';
          $ticketInput = [
              'name'        => sprintf(__('Charte informatique non signée : %1$d personne(s) (%2$s)', 'assetsign'), count($pending), $campaign->fields['name']),
              'content'     => $content,
              'entities_id' => (int) $campaign->fields['entities_id'],
              'type'        => Ticket::DEMAND_TYPE,
          ];
          if ((int) $campaign->fields['users_id'] > 0) {
             $ticketInput['_users_id_requester'] = (int) $campaign->fields['users_id'];
          }
          $ticketId = (int) (new Ticket())->add($ticketInput);
          if ($ticketId > 0) {
             $campaign->update(['id' => $campaign->getID(), 'tickets_id' => $ticketId]);
             $count++;
          }
      }
       return $count;
   }

   /**
    * Issue #204 : renouvellement de la charte, pour chaque entité qui l'a activé dans sa
    * Configuration : une nouvelle campagne (entité et sous-entités) dès que la précédente date de
    * la période choisie. Une entité sans charte configurée est ignorée.
    *
    * @return int nombre de campagnes lancées
    */
   public static function runCharterRenewals(): int {
       global $DB;

       $count = 0;
      foreach ($DB->request(['FROM' => Config::getTable(), 'WHERE' => ['charter_campaign_enabled' => 1]]) as $config) {
          $entitiesId = (int) $config['entities_id'];
          $last = $DB->request([
              'SELECT' => ['date_creation'],
              'FROM'   => self::getTable(),
              'WHERE'  => ['type' => CampaignLogic::TYPE_CHARTER, 'entities_id' => $entitiesId],
              'ORDER'  => 'date_creation DESC',
              'LIMIT'  => 1,
          ])->current();
         if (!CampaignLogic::charterRenewalDue($last['date_creation'] ?? null, (int) $config['charter_renewal_months'], time())) {
             continue;
         }
         try {
             $campaign = self::launch([
                 'name'          => '',
                 'type'          => CampaignLogic::TYPE_CHARTER,
                 'entities_id'   => $entitiesId,
                 'is_recursive'  => true,
                 'groups_id'     => 0,
                 'profiles_id'   => 0,
                 'date_deadline' => date('Y-m-d', strtotime('+' . max(1, (int) $config['charter_deadline_days']) . ' days')),
             ]);
         } catch (\RuntimeException $e) {
             \Glpi\Error\ErrorHandler::logCaughtException($e);
             continue;
         }
         if ($campaign !== null) {
             $count++;
         }
      }
       return $count;
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

   /**
    * Issue #224 : empreinte de la derniere charte signee par chaque personne de l'entite.
    *
    * @return array<int, string>
    */
   public static function lastSignedHashByUser(int $entitiesId): array {
       global $DB;

       $campaigns = self::getTable();
       $attestations = Attestation::getTable();
       $map = [];
      foreach ($DB->request([
              'SELECT'     => ["$attestations.users_id", "$campaigns.charter_hash"],
              'FROM'       => $attestations,
              'INNER JOIN' => [
                  $campaigns => ['FKEY' => [$attestations => 'plugin_assetsign_campaigns_id', $campaigns => 'id']],
              ],
              'WHERE'      => [
                  "$campaigns.type"        => CampaignLogic::TYPE_CHARTER,
                  "$campaigns.entities_id" => $entitiesId,
                  "$attestations.status"   => CampaignLogic::ATTESTATION_CONFIRMED,
              ],
              'ORDER'      => ["$attestations.date_answered ASC", "$attestations.id ASC"],
          ]) as $row
      ) {
          $map[(int) $row['users_id']] = (string) $row['charter_hash'];
      }
       return $map;
   }

   /**
    * Issue #224 : personnes de l'entite dont la derniere signature porte sur une ancienne version.
    *
    * @return list<int>
    */
   public static function outdatedSignerIds(int $entitiesId): array {
       return CampaignLogic::outdatedSigners(
           self::lastSignedHashByUser($entitiesId),
           CampaignLogic::charterHash(self::getCharterContent($entitiesId))
       );
   }

   /**
    * Issue #224 : versions successives de la charte de l'entite, la plus recente d'abord : date de
    * premiere diffusion, campagnes, nombre de signataires et texte.
    *
    * @return list<array{hash: string, first_date: string, campaigns: list<string>, signers: int, content: string, is_current: bool}>
    */
   public static function charterVersions(int $entitiesId): array {
       global $DB;

       $current = CampaignLogic::charterHash(self::getCharterContent($entitiesId));
       $versions = [];
      foreach ($DB->request([
              'FROM'  => self::getTable(),
              'WHERE' => ['type' => CampaignLogic::TYPE_CHARTER, 'entities_id' => $entitiesId],
              'ORDER' => 'date_creation ASC',
          ]) as $row
      ) {
          $hash = (string) $row['charter_hash'];
          $versions[$hash] ??= [
              'hash'       => $hash,
              'first_date' => (string) $row['date_creation'],
              'campaigns'  => [],
              'ids'        => [],
              'content'    => (string) $row['charter_content'],
              'is_current' => $hash === $current,
          ];
          $versions[$hash]['campaigns'][] = (string) $row['name'];
          $versions[$hash]['ids'][] = (int) $row['id'];
      }
       $result = [];
      foreach ($versions as $version) {
          $signers = $DB->request([
              'SELECT'   => ['users_id'],
              'DISTINCT' => true,
              'FROM'     => Attestation::getTable(),
              'WHERE'    => [
                  'plugin_assetsign_campaigns_id' => $version['ids'],
                  'status'                        => CampaignLogic::ATTESTATION_CONFIRMED,
              ],
          ])->count();
          unset($version['ids']);
          $result[] = $version + ['signers' => $signers];
      }
       return array_reverse($result);
   }

   /**
    * Issue #224 : la charte en vigueur n'a encore ete diffusee par aucune campagne de l'entite.
    */
   public static function isCurrentCharterUndistributed(int $entitiesId): bool {
       global $DB;

       $current = CampaignLogic::charterHash(self::getCharterContent($entitiesId));
       return $current !== '' && $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['type' => CampaignLogic::TYPE_CHARTER, 'entities_id' => $entitiesId, 'charter_hash' => $current],
       ])->count() === 0;
   }

   /**
    * Issue #224 : pour chaque entite qui l'a choisi dans sa Configuration, une campagne de
    * re-signature des que la charte change, limitee aux signataires d'une ancienne version. Une
    * seule fois par version : une version deja diffusee ne relance rien.
    *
    * @return int nombre de campagnes lancees
    */
   public static function runCharterVersionChecks(): int {
       global $DB;

       $count = 0;
      foreach ($DB->request(['FROM' => Config::getTable(), 'WHERE' => ['charter_resign_on_change' => 1]]) as $config) {
          $entitiesId = (int) $config['entities_id'];
         if (!self::isCurrentCharterUndistributed($entitiesId)
             || self::findRunningCharter($entitiesId) !== null
             || self::outdatedSignerIds($entitiesId) === []
         ) {
             continue;
         }
         try {
             $campaign = self::launch([
                 'name'          => '',
                 'type'          => CampaignLogic::TYPE_CHARTER,
                 'only_outdated' => true,
                 'entities_id'   => $entitiesId,
                 'is_recursive'  => true,
                 'groups_id'     => 0,
                 'profiles_id'   => 0,
                 'date_deadline' => date('Y-m-d', strtotime('+' . max(1, (int) $config['charter_deadline_days']) . ' days')),
             ]);
         } catch (\RuntimeException $e) {
             \Glpi\Error\ErrorHandler::logCaughtException($e);
             continue;
         }
         if ($campaign !== null) {
             $count++;
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
                `type` tinyint NOT NULL DEFAULT 0,
                `charter_content` longtext,
                `charter_hash` varchar(64) NOT NULL DEFAULT '',
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `groups_id` int unsigned NOT NULL DEFAULT 0,
                `profiles_id` int unsigned NOT NULL DEFAULT 0,
                `date_deadline` date DEFAULT NULL,
                `status` tinyint NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `tickets_id` int unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `status` (`status`),
                KEY `type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      } else if (!$DB->fieldExists($table, 'type')) {
          // Issue #204 : campagnes de charte informatique.
          $migration->addField($table, 'type', 'integer', ['value' => 0, 'after' => 'name']);
          $migration->addField($table, 'charter_content', 'longtext', ['after' => 'type']);
          $migration->addField($table, 'tickets_id', 'fkey', ['after' => 'users_id']);
          $migration->addKey($table, 'type');
          $migration->migrationOneTable($table);
      }
       // Issue #224 : empreinte de la version de charte diffusee, calculee pour l'existant.
      if (!$DB->fieldExists($table, 'charter_hash')) {
          $migration->addField($table, 'charter_hash', 'string', ['value' => '', 'after' => 'charter_content']);
          $migration->migrationOneTable($table);
         foreach ($DB->request(['FROM' => $table, 'WHERE' => ['type' => CampaignLogic::TYPE_CHARTER]]) as $row) {
             $DB->update($table, ['charter_hash' => CampaignLogic::charterHash((string) $row['charter_content'])], ['id' => $row['id']]);
         }
      }
   }
}
