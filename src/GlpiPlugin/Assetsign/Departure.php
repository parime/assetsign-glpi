<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CronTask;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Pdf\PdfRenderingHelpers;
use GlpiPlugin\Assetsign\Pdf\SignatureImageValidator;
use GlpiPlugin\Assetsign\Pdf\SignatureStamper;
use Html;
use Migration;
use NotificationEvent;
use Session;
use User;

/**
 * Issue #157 : départ d'un salarié — « dossier groupé » (choix du mainteneur) qui rassemble une
 * fiche de Restitution ORDINAIRE par matériel affecté à la personne qui part. Chaque fiche garde
 * tout son comportement existant (PDF, preuve de signature, passeport, état du matériel,
 * compteurs de preuves A.5.11 côté grcmanager) ; le dossier ajoute :
 *
 * - une seule invitation et une seule page de signature (front/departure.form.php) : la personne
 *   signe UNE fois, la même signature est apposée sur chaque fiche (SignatureStamper, comme une
 *   signature individuelle), plus un PDF récapitulatif listant tout le matériel ;
 * - pas de doublon : un seul dossier ouvert par personne, une fiche de restitution déjà en attente
 *   pour un matériel est rattachée au dossier plutôt que recréée ;
 * - les fiches d'un dossier n'envoient ni e-mail ni relance individuels (Assetsign::createForDeparture()) :
 *   relances au niveau du dossier (runReminders()) ;
 * - trois déclencheurs, chacun activable : action manuelle « Préparer le départ » (onglet du
 *   compte utilisateur), désactivation du compte, date de fin de compte à J-X (tâche planifiée).
 */
class Departure extends Compat\Base\DepartureBase
{
   use PdfRenderingHelpers;

   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   public static function getTypeName($nb = 0): string {
      return _n('Départ', 'Départs', $nb, 'assetsign');
   }

   public static function getIcon(): string {
      return 'ti ti-door-exit';
   }

   /**
    * @return array<int, string>
    */
   public static function getTriggerLabels(): array {
      return [
         DepartureLogic::TRIGGER_MANUAL       => __('Manuel', 'assetsign'),
         DepartureLogic::TRIGGER_DEACTIVATION => __('Désactivation du compte', 'assetsign'),
         DepartureLogic::TRIGGER_END_DATE     => __('Date de fin du compte', 'assetsign'),
      ];
   }

   /**
    * @return array<int, string>
    */
   public static function getStatusLabels(): array {
      return [
         DepartureLogic::STATUS_OPEN      => __('En cours', 'assetsign'),
         DepartureLogic::STATUS_SIGNED    => __('Signé', 'assetsign'),
         DepartureLogic::STATUS_CANCELLED => __('Annulé', 'assetsign'),
      ];
   }

   /**
    * Prépare (ou complète) le dossier de départ de $usersId.
    *
    * @return self|null le dossier ouvert, null si la personne n'a aucun matériel affecté
    */
   public static function prepareFor(int $usersId, int $trigger): ?self {
      $user = new User();
      if ($usersId <= 0 || !$user->getFromDB($usersId)) {
         return null;
      }
      $entitiesId = (int) ($user->fields['entities_id'] ?? 0);
      $items = self::findAssignedItems($usersId, $entitiesId);

      $departure = self::findOpenFor($usersId);
      if ($departure === null) {
         if ($items === []) {
            return null;
         }
         $departure = new self();
         $id = $departure->add([
            'entities_id'    => $entitiesId,
            'users_id'       => $usersId,
            'users_id_tech'  => Session::getLoginUserID() ?: 0,
            'trigger_type'   => $trigger,
            'status'         => DepartureLogic::STATUS_OPEN,
            'date_departure' => !empty($user->fields['end_date']) ? substr((string) $user->fields['end_date'], 0, 10) : null,
         ]);
         if (!$id) {
            return null;
         }
         $departure->getFromDB($id);
         $isNew = true;
      } else {
         $isNew = false;
      }

      foreach ($items as $item) {
         $departure->attachItem($item);
      }

      if ($isNew) {
         NotificationEvent::raiseEvent('departure_created', $departure);
      }

      return $departure;
   }

   public static function findOpenFor(int $usersId): ?self {
      $departure = new self();
      if ($departure->getFromDBByCrit(['users_id' => $usersId, 'status' => DepartureLogic::STATUS_OPEN])) {
         return $departure;
      }

      return null;
   }

   /**
    * Matériels gérés par assetsign actuellement affectés à $usersId.
    *
    * @return list<CommonDBTM>
    */
   private static function findAssignedItems(int $usersId, int $entitiesId): array {
      global $DB;

      $items = [];
      foreach (Config::getForEntity($entitiesId)->getManagedItemtypes() as $itemtype) {
         if (!is_subclass_of($itemtype, CommonDBTM::class)) {
            continue;
         }
         $table = $itemtype::getTable();
         if (!$DB->fieldExists($table, 'users_id')) {
            continue;
         }
         $where = ['users_id' => $usersId];
         foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($table, $flag)) {
               $where[$flag] = 0;
            }
         }
         foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
            $item = new $itemtype();
            if ($item->getFromDB((int) $row['id']) && !self::alreadyReturnedBy($item, $usersId)) {
               $items[] = $item;
            }
         }
      }

      return $items;
   }

   /**
    * Pas de doublon : un matériel dont la dernière fiche signée de cette personne est une
    * restitution a déjà été rendu (ex. départ signé, puis compte désactivé le dernier jour avant
    * que le technicien ait retiré l'affectation dans GLPI) — il n'est pas redemandé.
    */
   private static function alreadyReturnedBy(CommonDBTM $item, int $usersId): bool {
      global $DB;

      $last = $DB->request([
         'SELECT' => ['type'],
         'FROM'   => Assetsign::getTable(),
         'WHERE'  => [
            'itemtype'   => $item->getType(),
            'items_id'   => $item->getID(),
            'users_id'   => $usersId,
            'type'       => [Assetsign::TYPE_HANDOVER, Assetsign::TYPE_RETURN],
            'status'     => Assetsign::STATUS_SIGNED,
            'is_deleted' => 0,
         ],
         'ORDER'  => ['date_signed DESC', 'id DESC'],
         'LIMIT'  => 1,
      ])->current();

      return $last !== null && (int) $last['type'] === Assetsign::TYPE_RETURN;
   }

   /**
    * Rattache une restitution pour ce matériel : la fiche de restitution déjà en attente si elle
    * existe (pas de doublon), sinon une nouvelle fiche sans e-mail individuel.
    */
   private function attachItem(CommonDBTM $item): void {
      global $DB;

      $pending = $DB->request([
         'SELECT' => ['id', 'plugin_assetsign_departures_id'],
         'FROM'   => Assetsign::getTable(),
         'WHERE'  => [
            'itemtype'   => $item->getType(),
            'items_id'   => $item->getID(),
            'type'       => Assetsign::TYPE_RETURN,
            'is_deleted' => 0,
            'status'     => [Assetsign::STATUS_DRAFT, Assetsign::STATUS_PENDING, ...Assetsign::STATUSES_AWAITING_SIGNATURE],
         ],
         'LIMIT'  => 1,
      ])->current();

      if ($pending !== null) {
         if ((int) $pending['plugin_assetsign_departures_id'] !== $this->getID()) {
            $DB->update(
               Assetsign::getTable(),
               ['plugin_assetsign_departures_id' => $this->getID()],
               ['id' => (int) $pending['id']]
            );
         }
         return;
      }

      Assetsign::createForDeparture($item, (int) $this->fields['users_id'], $this->getID());
   }

   /**
    * @return list<array<string, mixed>> lignes des fiches de restitution du dossier
    */
   public function getChildren(): array {
      global $DB;

      return iterator_to_array($DB->request([
         'FROM'  => Assetsign::getTable(),
         'WHERE' => ['plugin_assetsign_departures_id' => $this->getID(), 'is_deleted' => 0],
         'ORDER' => 'id ASC',
      ]), false);
   }

   /**
    * Lignes affichables (écran du dossier, PDF récapitulatif, e-mails).
    *
    * @return list<array{assetsign_id: int, type_label: string, name: string, serial: string, otherserial: string, status: int, status_label: string}>
    */
   public function getItemLines(): array {
      $statuses = Assetsign::getStatuses();
      $lines = [];
      foreach ($this->getChildren() as $row) {
         $itemtype = (string) $row['itemtype'];
         $fields = [];
         if (is_subclass_of($itemtype, CommonDBTM::class)) {
            $item = new $itemtype();
            if ($item->getFromDB((int) $row['items_id'])) {
               $fields = $item->fields;
            }
         }
         $lines[] = [
            'assetsign_id' => (int) $row['id'],
            'type_label'   => Assetsign::getCanonicalItemtypeLabel($itemtype),
            'name'         => (string) ($fields['name'] ?? ('#' . $row['items_id'])),
            'serial'       => (string) ($fields['serial'] ?? ''),
            'otherserial'  => (string) ($fields['otherserial'] ?? ''),
            'status'       => (int) $row['status'],
            'status_label' => (string) ($statuses[(int) $row['status']] ?? $row['status']),
         ];
      }

      return $lines;
   }

   public function isOpen(): bool {
      return (int) ($this->fields['status'] ?? -1) === DepartureLogic::STATUS_OPEN;
   }

   /**
    * Signature groupée : la même signature est apposée sur chaque fiche encore en attente, puis
    * le PDF récapitulatif est produit. $witnessUsersId : technicien en présence duquel la
    * signature est recueillie sur son propre écran (signature sur place), null sinon.
    *
    * @param array{ip?: string, user_agent?: string} $meta
    */
   public function sign(string $signaturePngDataUrl, array $meta, ?int $witnessUsersId = null): void {
      if (!$this->isOpen()) {
         throw new \RuntimeException(__('Ce départ n\'attend plus de signature.', 'assetsign'));
      }
      SignatureImageValidator::assertValid($signaturePngDataUrl);

      $witnessName = null;
      if ($witnessUsersId !== null) {
         $witness = new User();
         $witness->getFromDB($witnessUsersId);
         $witnessName = trim(\formatUserName(
            0,
            $witness->fields['name'] ?? '',
            $witness->fields['realname'] ?? '',
            $witness->fields['firstname'] ?? ''
         ));
      }

      $stamper = new SignatureStamper();
      $signedAt = date('Y-m-d H:i:s');
      $hashes = [];
      foreach ($this->getChildren() as $row) {
         if (!in_array((int) $row['status'], Assetsign::STATUSES_AWAITING_SIGNATURE, true)) {
            continue;
         }
         $assetsign = new Assetsign();
         if (!$assetsign->getFromDB((int) $row['id'])) {
            continue;
         }
         $signer = $assetsign->getExpectedSigner();
         $result = $stamper->apply($assetsign, $signaturePngDataUrl, $signer, $witnessName);
         $assetsign->markSigned($result['path'], [
            'signer_name'   => trim(\formatUserName(0, $signer['name'] ?? '', $signer['realname'] ?? '', $signer['firstname'] ?? '')),
            'signer_email'  => $signer['email'] ?? '',
            'ip_address'    => $meta['ip'] ?? '',
            'user_agent'    => $meta['user_agent'] ?? '',
            'document_hash' => $result['hash'],
            'signed_at'     => $result['signed_at'],
            'witness_name'  => $witnessName,
         ], false);
         $hashes[$assetsign->getID()] = $result['hash'];
         $signedAt = $result['signed_at'];
      }

      $summary = $this->storeSummaryPdf($signaturePngDataUrl, $signedAt, $witnessName, $hashes, $meta);
      $this->update([
         'id'                  => $this->getID(),
         'status'              => DepartureLogic::statusFromChildren(array_map('intval', array_column($this->getChildren(), 'status'))),
         'date_signed'         => $signedAt,
         'document_id_summary' => $summary !== null ? $summary->getID() : 0,
      ]);

      NotificationEvent::raiseEvent('departure_signed', $this);
   }

   /**
    * @param array<int, string> $hashes empreinte du PDF signé de chaque fiche
    * @param array{ip?: string, user_agent?: string} $meta
    */
   private function storeSummaryPdf(string $signaturePng, string $signedAt, ?string $witnessName, array $hashes, array $meta): ?Document {
      $user = new User();
      $user->getFromDB((int) $this->fields['users_id']);
      $config = Config::getForEntity((int) $this->fields['entities_id']);

      $html = TemplateRenderer::getInstance()->render('@assetsign/pdf/departure_summary.html.twig', [
         'company_name'    => (string) ($config->fields['company_name'] ?? ''),
         'user_name'       => trim(\formatUserName(0, $user->fields['name'] ?? '', $user->fields['realname'] ?? '', $user->fields['firstname'] ?? '')),
         'departure_date'  => Html::convDate($this->fields['date_departure'] ?? null),
         'lines'           => $this->getItemLines(),
         'hashes'          => $hashes,
         'signature_image' => $signaturePng,
         'signed_at'       => Html::convDateTime($signedAt),
         'witness_name'    => $witnessName,
         'ip_address'      => $meta['ip'] ?? '',
      ]);
      $binary = $this->renderPdf($html, (bool) ($config->fields['protect_pdf'] ?? false));

      $tmpName = uniqid('assetsign_departure_', true) . '.pdf';
      if (file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $binary) === false) {
         return null;
      }
      $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
      $document = new Document();
      $documentsId = $document->add([
         'name'          => sprintf(__('Restitution de départ - %s', 'assetsign'), $user->getFriendlyName()),
         'entities_id'   => $this->fields['entities_id'],
         '_filename'     => [$tmpName],
         '_tag_filename' => [$tmpName],
      ]);
      // Messages techniques de Document::add() (« Document copié avec succès »…) sans intérêt ici.
      $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if (!$documentsId) {
         return null;
      }
      foreach ([[self::class, $this->getID()], ['User', (int) $this->fields['users_id']]] as [$itemtype, $itemsId]) {
         (new Document_Item())->add(['documents_id' => $documentsId, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
      }
      $document->getFromDB($documentsId);

      return $document;
   }

   /**
    * Annule le dossier et ses fiches encore en attente (départ annulé, erreur de manipulation).
    */
   public function cancel(): void {
      foreach ($this->getChildren() as $row) {
         $assetsign = new Assetsign();
         if ($assetsign->getFromDB((int) $row['id']) && $assetsign->isStillEditable()) {
            $assetsign->cancelRequest();
         }
      }
      $this->update(['id' => $this->getID(), 'status' => DepartureLogic::STATUS_CANCELLED]);
   }

   public function sendReminderNow(): void {
      if (!$this->isOpen()) {
         throw new \RuntimeException(__('Ce départ n\'attend plus de signature.', 'assetsign'));
      }
      $this->update([
         'id'                 => $this->getID(),
         'reminder_count'     => (int) $this->fields['reminder_count'] + 1,
         'date_last_reminder' => date('Y-m-d H:i:s'),
      ]);
      NotificationEvent::raiseEvent('departure_reminder', $this);
   }

   public function getSignUrl(): string {
      $base = rtrim($GLOBALS['CFG_GLPI']['url_base'] ?? '', '/');

      return $base . '/plugins/assetsign/front/departure.form.php?id=' . $this->getID();
   }

   // ================================================================================
   // Déclencheurs automatiques et relances
   // ================================================================================

   /**
    * Hook ITEM_UPDATE sur User (setup.php) : désactivation du compte.
    */
   public static function onUserUpdate(User $user): void {
      if (!DepartureLogic::isDeactivation($user->oldvalues, $user->fields)) {
         return;
      }
      $config = Config::getForEntity((int) ($user->fields['entities_id'] ?? 0));
      if (empty($config->fields['departure_on_deactivation'])) {
         return;
      }
      $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
      $departure = self::prepareFor($user->getID(), DepartureLogic::TRIGGER_DEACTIVATION);
      $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if ($departure !== null) {
         Session::addMessageAfterRedirect(sprintf(
            __('Départ préparé : %d matériel(s) à restituer, l\'utilisateur est invité à signer.', 'assetsign'),
            count($departure->getChildren())
         ));
      }
   }

   /**
    * @return array{description: string}|array{}
    */
   public static function cronInfo(string $name): array {
      return $name === 'assetsignDepartures'
         ? ['description' => __('Prépare les départs à date de fin de compte et relance les départs non signés', 'assetsign')]
         : [];
   }

   public static function cronAssetsignDepartures(CronTask $task): int {
      $created = self::runEndDateTriggers();
      $reminded = self::runReminders();
      $task->addVolume($created + $reminded);

      return ($created + $reminded) > 0 ? 1 : 0;
   }

   public static function runEndDateTriggers(): int {
      global $DB;

      $count = 0;
      $now = time();
      $rows = $DB->request([
         'SELECT' => ['id', 'entities_id', 'end_date'],
         'FROM'   => User::getTable(),
         'WHERE'  => ['is_active' => 1, 'is_deleted' => 0, 'NOT' => ['end_date' => null]],
      ]);
      foreach ($rows as $row) {
         $config = Config::getForEntity((int) $row['entities_id']);
         if (empty($config->fields['departure_on_end_date'])
            || !DepartureLogic::endDateWithinWindow($row['end_date'], (int) $config->fields['departure_end_date_days'], $now)
            || self::hasAnyDepartureSince((int) $row['id'], $now - 30 * 86400)
         ) {
            continue;
         }
         if (self::prepareFor((int) $row['id'], DepartureLogic::TRIGGER_END_DATE) !== null) {
            $count++;
         }
      }

      return $count;
   }

   /**
    * Garde-fou anti-doublon de la tâche planifiée : un départ déjà traité (signé ou annulé) dans
    * les 30 derniers jours n'est pas recréé tant que la date de fin reste dans la fenêtre.
    */
   private static function hasAnyDepartureSince(int $usersId, int $since): bool {
      return countElementsInTable(self::getTable(), [
         'users_id'      => $usersId,
         'date_creation' => ['>=', date('Y-m-d H:i:s', $since)],
      ]) > 0;
   }

   public static function runReminders(): int {
      global $DB;

      $count = 0;
      foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['status' => DepartureLogic::STATUS_OPEN]]) as $row) {
         $config = Config::getForEntity((int) $row['entities_id']);
         $days = (int) floor((time() - (int) strtotime((string) $row['date_creation'])) / 86400);
         $sinceLast = $row['date_last_reminder'] !== null
            ? (int) floor((time() - (int) strtotime((string) $row['date_last_reminder'])) / 86400)
            : null;
         if (
            !DepartureLogic::reminderDue(
                $days,
                $sinceLast,
                (int) $row['reminder_count'],
                $config->getReminderDelays(),
                (int) $config->fields['max_reminders']
            )
         ) {
            continue;
         }
         $departure = new self();
         if ($departure->getFromDB((int) $row['id'])) {
            $departure->sendReminderNow();
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
               `entities_id` int unsigned NOT NULL DEFAULT 0,
               `users_id` int unsigned NOT NULL DEFAULT 0,
               `users_id_tech` int unsigned NOT NULL DEFAULT 0,
               `trigger_type` tinyint NOT NULL DEFAULT 0,
               `status` tinyint NOT NULL DEFAULT 0,
               `date_departure` date DEFAULT NULL,
               `document_id_summary` int unsigned NOT NULL DEFAULT 0,
               `reminder_count` int unsigned NOT NULL DEFAULT 0,
               `date_last_reminder` timestamp NULL DEFAULT NULL,
               `date_signed` timestamp NULL DEFAULT NULL,
               `date_creation` timestamp NULL DEFAULT NULL,
               `date_mod` timestamp NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `entities_id` (`entities_id`),
               KEY `users_id` (`users_id`),
               KEY `status` (`status`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
