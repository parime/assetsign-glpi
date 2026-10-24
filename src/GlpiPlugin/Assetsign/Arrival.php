<?php

namespace GlpiPlugin\Assetsign;

use CommonITILActor;
use CommonITILObject;
use CronTask;
use Html;
use ITILFollowup;
use Migration;
use Planning;
use Session;
use Ticket;
use Ticket_User;
use TicketTask;
use User;

/**
 * Issue #221 : arrivée d'un salarié — le pendant du départ (Departure, issue #157).
 *
 * Un dossier d'arrivée ouvre UN ticket « Arrivée de [personne] » pour l'équipe, avec une tâche par
 * étape configurée (poste, licences, accès, remise du matériel). La personne est demandeuse du
 * ticket, ses notifications désactivées sur ce ticket : l'onglet « Remise de matériel » du ticket permet
 * ainsi de lui remettre directement du matériel disponible, avec sa fiche à signer.
 *
 * Pas de connecteur propre à un logiciel RH (décision de l'issue #221) : les SIRH alimentent déjà
 * l'annuaire (LDAP, Entra ID) synchronisé avec les comptes GLPI. Quatre déclencheurs, chacun
 * activable par entité : action manuelle (onglet du compte), création du compte, réactivation du
 * compte, date de début du compte à J-X (tâche planifiée « assetsignArrivals »).
 *
 * Règle du projet : le plugin ne change jamais un statut, un droit ni une affectation ; il crée le
 * ticket que l'équipe traite. Un seul dossier en cours par personne (tant que son ticket n'est ni
 * résolu ni clos).
 */
class Arrival extends Compat\Base\ArrivalBase
{
   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   public static function getTypeName($nb = 0): string {
      return _n('Arrivée', 'Arrivées', $nb, 'assetsign');
   }

   public static function getIcon(): string {
      return 'ti ti-door-enter';
   }

   /**
    * @return array<int, string>
    */
   public static function getTriggerLabels(): array {
      return [
         ArrivalLogic::TRIGGER_MANUAL       => __('Manuel', 'assetsign'),
         ArrivalLogic::TRIGGER_CREATION     => __('Création du compte', 'assetsign'),
         ArrivalLogic::TRIGGER_REACTIVATION => __('Réactivation du compte', 'assetsign'),
         ArrivalLogic::TRIGGER_BEGIN_DATE   => __('Date de début du compte', 'assetsign'),
      ];
   }

   /**
    * Tâches proposées par défaut, dans la langue de l'utilisateur (ArrivalLogic::DEFAULT_TASKS en
    * est la version française, sans dépendance à GLPI pour les tests).
    */
   public static function defaultTasksText(): string {
      return implode("\n", [
         __('Commander ou préparer le poste de travail', 'assetsign'),
         __('Attribuer les licences logicielles', 'assetsign'),
         __('Créer les accès (badge, comptes applicatifs, messagerie)', 'assetsign'),
         __('Remettre le matériel et faire signer la fiche d\'attribution', 'assetsign'),
      ]);
   }

   /**
    * Tâches effectives d'une entité : sa liste configurée, sinon la liste par défaut traduite.
    *
    * @return list<string>
    */
   public static function effectiveTasks(string $configured): array {
      return ArrivalLogic::parseTasks($configured, self::defaultTasksText());
   }

   /**
    * Prépare le dossier d'arrivée de $usersId : le dossier en cours s'il existe, sinon un nouveau
    * dossier et son ticket.
    */
   public static function prepareFor(int $usersId, int $trigger): ?self {
      $user = new User();
      if ($usersId <= 0 || !$user->getFromDB($usersId)) {
         return null;
      }
      $open = self::findOpenFor($usersId);
      if ($open !== null) {
         // Ticket refusé à la première tentative (droits, entité) : on réessaie plutôt que de
         // laisser un dossier sans ticket.
         $open->openTicket();
         return $open;
      }

      $arrival = new self();
      $id = $arrival->add([
         'entities_id'   => (int) ($user->fields['entities_id'] ?? 0),
         'users_id'      => $usersId,
         'users_id_tech' => Session::getLoginUserID() ?: 0,
         'trigger_type'  => $trigger,
         'date_arrival'  => !empty($user->fields['begin_date']) ? substr((string) $user->fields['begin_date'], 0, 10) : null,
      ]);
      if (!$id) {
         return null;
      }
      $arrival->getFromDB($id);
      $arrival->openTicket();

      return $arrival;
   }

   /**
    * Dossier en cours de la personne : son dernier dossier, tant que le ticket n'est ni résolu ni
    * clos (ou n'a pas pu être créé il y a moins de 60 jours).
    */
   public static function findOpenFor(int $usersId): ?self {
      global $DB;

      $row = $DB->request([
         'FROM'  => self::getTable(),
         'WHERE' => ['users_id' => $usersId],
         'ORDER' => 'id DESC',
         'LIMIT' => 1,
      ])->current();
      if ($row === null) {
         return null;
      }
      $ticketsId = (int) $row['tickets_id'];
      if ($ticketsId > 0) {
         $ticket = new Ticket();
         if ($ticket->getFromDB($ticketsId)
            && in_array((int) $ticket->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)
         ) {
            return null;
         }
      } else if (strtotime((string) $row['date_creation']) < time() - 60 * 86400) {
         return null;
      }
      $arrival = new self();
      $arrival->getFromDB((int) $row['id']);

      return $arrival;
   }

   /**
    * Ticket « Arrivée de … » avec une tâche à faire par étape configurée. Un seul par dossier.
    *
    * @return int id du ticket, 0 si la création a été refusée
    */
   public function openTicket(): int {
      if ((int) ($this->fields['tickets_id'] ?? 0) > 0) {
         return (int) $this->fields['tickets_id'];
      }
      $usersId = (int) $this->fields['users_id'];
      $entitiesId = (int) $this->fields['entities_id'];
      $name = getUserName($usersId);
      // convDate(null) renvoie null : sans date de début, titre « Arrivée de X » sans « (le ) ».
      $date = (string) Html::convDate($this->fields['date_arrival'] ?? null);
      $tasks = self::effectiveTasks((string) (Config::getForEntity($entitiesId)->fields['arrival_tasks'] ?? ''));

      $content = '<p>' . htmlescape($date !== ''
         ? sprintf(__('Arrivée de %1$s le %2$s : préparez son poste, ses accès et son matériel.', 'assetsign'), $name, $date)
         : sprintf(__('Arrivée de %s : préparez son poste, ses accès et son matériel.', 'assetsign'), $name))
         . '</p><ul><li>' . implode('</li><li>', array_map('htmlescape', $tasks)) . '</li></ul>'
         . '<p>' . htmlescape(__('Le matériel se remet depuis l\'onglet « Remise de matériel » de ce ticket : la fiche d\'attribution est envoyée à la personne pour signature.', 'assetsign')) . '</p>';

      $input = [
         'name'                      => $date !== ''
            ? sprintf(__('Arrivée de %1$s (le %2$s)', 'assetsign'), $name, $date)
            : sprintf(__('Arrivée de %s', 'assetsign'), $name),
         'content'                   => $content,
         'entities_id'               => $entitiesId,
         'type'                      => Ticket::DEMAND_TYPE,
         // Demandeuse pour pouvoir recevoir son matériel depuis le ticket (Assetsign::createFromTicket()),
         // sans notification : la liste des tâches est interne à l'équipe.
         '_actors'                   => [
            'requester' => [['itemtype' => User::class, 'items_id' => $usersId, 'use_notification' => 0, 'alternative_email' => '']],
         ],
      ];
      $tech = (int) ($this->fields['users_id_tech'] ?? 0);
      if ($tech > 0 && $tech !== $usersId) {
         $input['_actors']['observer'] = [['itemtype' => User::class, 'items_id' => $tech, 'use_notification' => 1, 'alternative_email' => '']];
      }

      $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
      $ticketsId = (int) (new Ticket())->add($input);
      foreach ($ticketsId > 0 ? $tasks : [] as $task) {
         (new TicketTask())->add([
            'tickets_id' => $ticketsId,
            'content'    => '<p>' . htmlescape($task) . '</p>',
            'state'      => Planning::TODO,
         ]);
      }
      // GLPI écarte sans le dire un demandeur dont le compte n'est pas encore valide dans l'entité du
      // ticket : date de début à venir (le cas normal d'une arrivée préparée à l'avance) ou aucun
      // profil dans l'entité. On ne contourne pas ce contrôle : on prévient l'équipe, et la tâche
      // planifiée ajoute la personne dès que son compte devient valide (attachPendingRequesters()).
      if ($ticketsId > 0 && !self::hasRequester($ticketsId, $usersId)) {
         $newcomer = new User();
         $begin = $newcomer->getFromDB($usersId) ? (string) ($newcomer->fields['begin_date'] ?? '') : '';
         (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => $ticketsId,
            'is_private' => 1,
            'content'    => '<p>' . htmlescape($begin !== '' && strtotime($begin) > time()
               ? sprintf(
                  __('Le compte de %1$s ne sera valide qu\'à partir du %2$s : la personne sera ajoutée automatiquement comme demandeur de ce ticket ce jour-là. Le matériel pourra alors lui être remis depuis l\'onglet « Remise de matériel ».', 'assetsign'),
                  $name,
                  Html::convDate($begin)
               )
               : sprintf(
                  __('%s n\'a pas encore de profil dans cette entité : la personne sera ajoutée automatiquement comme demandeur de ce ticket dès que ses habilitations seront créées. Le matériel pourra alors lui être remis depuis l\'onglet « Remise de matériel ».', 'assetsign'),
                  $name
               )) . '</p>',
         ]);
      }
      // « Votre ticket a été enregistré »… : messages du cœur sans intérêt ici.
      $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if ($ticketsId <= 0) {
         return 0;
      }
      $this->update(['id' => $this->getID(), 'tickets_id' => $ticketsId]);

      return $ticketsId;
   }

   /**
    * Hook ITEM_ADD sur User (setup.php) : création d'un compte, par l'annuaire ou à la main.
    */
   public static function onUserAdd(User $user): void {
      $config = Config::getForEntity((int) ($user->fields['entities_id'] ?? 0));
      if (empty($config->fields['arrival_on_creation']) || !ArrivalLogic::isNewcomerAccount($user->fields, time())) {
         return;
      }
      self::prepareFor($user->getID(), ArrivalLogic::TRIGGER_CREATION);
   }

   /**
    * Hook ITEM_UPDATE sur User (setup.php) : réactivation d'un compte.
    */
   public static function onUserUpdate(User $user): void {
      if (!ArrivalLogic::isReactivation($user->oldvalues, $user->fields)) {
         return;
      }
      $config = Config::getForEntity((int) ($user->fields['entities_id'] ?? 0));
      if (empty($config->fields['arrival_on_reactivation'])) {
         return;
      }
      self::prepareFor($user->getID(), ArrivalLogic::TRIGGER_REACTIVATION);
   }

   /**
    * @return array{description: string}|array{}
    */
   public static function cronInfo(string $name): array {
      return $name === 'assetsignArrivals'
         ? ['description' => __('Prépare les arrivées à l\'approche de la date de début du compte', 'assetsign')]
         : [];
   }

   public static function cronAssetsignArrivals(CronTask $task): int {
      $done = self::runBeginDateTriggers(time()) + self::attachPendingRequesters();
      $task->addVolume($done);

      return $done > 0 ? 1 : 0;
   }

   private static function hasRequester(int $ticketsId, int $usersId): bool {
      return countElementsInTable(Ticket_User::getTable(), [
         'tickets_id' => $ticketsId, 'users_id' => $usersId, 'type' => CommonITILActor::REQUESTER,
      ]) > 0;
   }

   /**
    * Ajoute la personne comme demandeur (sans notification) du ticket de son arrivée en cours dès
    * que son compte devient valide dans l'entité du ticket (date de début atteinte, profil créé) —
    * le contrôle même que GLPI applique, cf. openTicket().
    *
    * @return int nombre de tickets complétés
    */
   public static function attachPendingRequesters(): int {
      global $DB;

      $count = 0;
      $rows = $DB->request([
         'SELECT'    => [self::getTable() . '.users_id', self::getTable() . '.tickets_id', Ticket::getTable() . '.entities_id'],
         'FROM'      => self::getTable(),
         'INNER JOIN' => [
            Ticket::getTable() => ['ON' => [self::getTable() => 'tickets_id', Ticket::getTable() => 'id']],
         ],
         'WHERE'     => [
            Ticket::getTable() . '.is_deleted' => 0,
            'NOT' => [Ticket::getTable() . '.status' => [CommonITILObject::SOLVED, CommonITILObject::CLOSED]],
         ],
      ]);
      foreach ($rows as $row) {
         $usersId = (int) $row['users_id'];
         $ticketsId = (int) $row['tickets_id'];
         if (self::hasRequester($ticketsId, $usersId) || !User::isValidUserForEntity($usersId, (int) $row['entities_id'])) {
            continue;
         }
         if ((new Ticket_User())->add([
            'tickets_id' => $ticketsId, 'users_id' => $usersId, 'type' => CommonITILActor::REQUESTER, 'use_notification' => 0,
         ])) {
            $count++;
         }
      }

      return $count;
   }

   /**
    * @return int nombre de dossiers d'arrivée créés
    */
   public static function runBeginDateTriggers(int $now): int {
      global $DB;

      $count = 0;
      $rows = $DB->request([
         'SELECT' => ['id', 'entities_id', 'begin_date'],
         'FROM'   => User::getTable(),
         'WHERE'  => ['is_active' => 1, 'is_deleted' => 0, 'NOT' => ['begin_date' => null]],
      ]);
      foreach ($rows as $row) {
         $config = Config::getForEntity((int) $row['entities_id']);
         if (empty($config->fields['arrival_on_begin_date'])
            || !ArrivalLogic::beginDateWithinWindow($row['begin_date'], (int) $config->fields['arrival_begin_date_days'], $now)
            || self::hasAnyArrivalSince((int) $row['id'], $now - 60 * 86400)
         ) {
            continue;
         }
         if (self::prepareFor((int) $row['id'], ArrivalLogic::TRIGGER_BEGIN_DATE) !== null) {
            $count++;
         }
      }

      return $count;
   }

   /**
    * Garde-fou de la tâche planifiée : une arrivée déjà préparée dans les 60 derniers jours
    * (même résolue) n'est pas recréée tant que la date de début reste dans la fenêtre.
    */
   private static function hasAnyArrivalSince(int $usersId, int $since): bool {
      return countElementsInTable(self::getTable(), [
         'users_id'      => $usersId,
         'date_creation' => ['>=', date('Y-m-d H:i:s', $since)],
      ]) > 0;
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
               `date_arrival` date DEFAULT NULL,
               `tickets_id` int unsigned NOT NULL DEFAULT 0,
               `date_creation` timestamp NULL DEFAULT NULL,
               `date_mod` timestamp NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `entities_id` (`entities_id`),
               KEY `users_id` (`users_id`),
               KEY `tickets_id` (`tickets_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
