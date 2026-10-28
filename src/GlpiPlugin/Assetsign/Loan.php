<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CronTask;
use Html;
use Item_Ticket;
use NotificationEvent;
use Session;
use Ticket;

/**
 * Issue #193 : prêt temporaire. Un prêt est une attribution (TYPE_HANDOVER) avec une date de retour
 * prévue (`loan_until`), posée dans la fenêtre de confirmation ou sur la feuille.
 *
 * Tâche planifiée quotidienne « assetsignLoans » :
 * - X jours avant l'échéance (Config::loan_reminder_days), e-mail au bénéficiaire et au technicien
 *   (notification « loan_due »), une fois par échéance ;
 * - échéance passée sans retour, ticket « Retour de prêt en retard » lié au matériel
 *   (Config::loan_overdue_ticket), un seul par prêt.
 *
 * Règle du projet : rien n'est désaffecté ni changé de statut automatiquement. Le retour passe par
 * la restitution habituelle ; un prêt est considéré rendu dès que le matériel n'est plus affecté au
 * bénéficiaire dans GLPI, ou qu'une restitution a été signée pour lui après le prêt.
 */
final class Loan
{
   /** Feuilles dont le prêt est en cours (envoyée, signée ou terminée sans signature). */
   public const ACTIVE_STATUSES = [
      Assetsign::STATUS_PENDING,
      Assetsign::STATUS_SENT,
      Assetsign::STATUS_VIEWED,
      Assetsign::STATUS_SIGNED,
      Assetsign::STATUS_COMPLETED_NO_SIGNATURE,
      Assetsign::STATUS_AWAITING_COSIGNATURE,
   ];

   /**
    * @param array<string, mixed> $row ligne de glpi_plugin_assetsign_assetsigns
    */
   public static function isReturned(array $row): bool {
      global $DB;

      $itemtype = (string) $row['itemtype'];
      if (is_subclass_of($itemtype, CommonDBTM::class)) {
         $item = new $itemtype();
         if (!$item->getFromDB((int) $row['items_id'])) {
            return true;
         }
         if ((int) ($row['beneficiary_type'] ?? Assetsign::BENEFICIARY_INTERNAL) === Assetsign::BENEFICIARY_INTERNAL
            && $item->isField('users_id')
            && (int) $item->fields['users_id'] !== (int) $row['users_id']
         ) {
            return true;
         }
      }

      return (int) $DB->request([
         'COUNT' => 'c',
         'FROM'  => Assetsign::getTable(),
         'WHERE' => [
            'itemtype'      => $itemtype,
            'items_id'      => (int) $row['items_id'],
            'type'          => Assetsign::TYPE_RETURN,
            'status'        => [Assetsign::STATUS_SIGNED, Assetsign::STATUS_COMPLETED_NO_SIGNATURE],
            'is_deleted'    => 0,
            'date_creation' => ['>=', (string) $row['date_creation']],
         ],
      ])->current()['c'] > 0;
   }

   /**
    * @return array<string, mixed>
    */
   private static function activeCriteria(): array {
      return [
         'type'       => Assetsign::TYPE_HANDOVER,
         'status'     => self::ACTIVE_STATUSES,
         'is_deleted' => 0,
         'NOT'        => ['loan_until' => null],
      ];
   }

   /**
    * Prêts en cours, non rendus, dans les entités de la session (page d'accueil), échéance la
    * plus proche en premier.
    *
    * @return list<array{id: int, item: string, beneficiary: string, loan_until: string, overdue: bool, tickets_id: int}>
    */
   public static function getActiveLoans(int $limit = 10): array {
      global $DB;

      if (!Session::haveRight(Assetsign::$rightname, UPDATE)) {
         return [];
      }
      $loans = [];
      foreach ($DB->request([
         'FROM'  => Assetsign::getTable(),
         'WHERE' => self::activeCriteria() + getEntitiesRestrictCriteria(Assetsign::getTable(), '', '', true),
         'ORDER' => 'loan_until ASC',
      ]) as $row) {
         if (self::isReturned($row)) {
            continue;
         }
         $sheet = new Assetsign();
         $sheet->fields = $row;
         $item = $sheet->getTargetItem();
         $beneficiary = $sheet->getBeneficiary();
         $loans[] = [
            'id'          => (int) $row['id'],
            'item'        => (string) ($item['name'] ?? ''),
            'beneficiary' => trim(\formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? '')),
            'loan_until'  => (string) $row['loan_until'],
            'overdue'     => (string) $row['loan_until'] < date('Y-m-d'),
            'tickets_id'  => (int) $row['loan_tickets_id'],
         ];
         if (count($loans) >= $limit) {
            break;
         }
      }

      return $loans;
   }

   public static function cronInfo(string $name): array {
      return $name === 'assetsignLoans'
         ? ['description' => __('Rappelle les prêts arrivant à échéance et ouvre un ticket pour les retours en retard', 'assetsign')]
         : [];
   }

   public static function cronAssetsignLoans(CronTask $task): int {
      $count = self::runReminders() + self::runOverdueTickets();
      $task->addVolume($count);

      return $count > 0 ? 1 : 0;
   }

   /**
    * E-mail au bénéficiaire et au technicien X jours avant l'échéance, une fois par échéance
    * (changer la date de retour réarme le rappel, cf. Assetsign::setLoanUntil()).
    */
   public static function runReminders(): int {
      global $DB;

      $count = 0;
      $configs = [];
      foreach ($DB->request([
         'FROM'  => Assetsign::getTable(),
         'WHERE' => self::activeCriteria() + ['loan_reminder_sent_at' => null, 'loan_until' => ['>=', date('Y-m-d')]],
      ]) as $row) {
         $entityId = (int) $row['entities_id'];
         $days = (int) (($configs[$entityId] ??= Config::getForEntity($entityId))->fields['loan_reminder_days'] ?? 3);
         if ($days <= 0 || (string) $row['loan_until'] > date('Y-m-d', strtotime("+$days days")) || self::isReturned($row)) {
            continue;
         }
         $DB->update(Assetsign::getTable(), ['loan_reminder_sent_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
         $sheet = new Assetsign();
         $sheet->fields = $row;
         NotificationEvent::raiseEvent('loan_due', $sheet);
         $count++;
      }

      return $count;
   }

   /**
    * Échéance passée sans retour : un ticket par prêt, lié au matériel, assigné au technicien.
    * Rien n'est désaffecté ni changé de statut.
    */
   public static function runOverdueTickets(): int {
      global $DB;

      $count = 0;
      $configs = [];
      foreach ($DB->request([
         'FROM'  => Assetsign::getTable(),
         'WHERE' => self::activeCriteria() + ['loan_tickets_id' => 0, 'loan_until' => ['<', date('Y-m-d')]],
      ]) as $row) {
         $entityId = (int) $row['entities_id'];
         if (empty(($configs[$entityId] ??= Config::getForEntity($entityId))->fields['loan_overdue_ticket']) || self::isReturned($row)) {
            continue;
         }
         $ticketsId = self::openOverdueTicket($row);
         if ($ticketsId > 0) {
            $DB->update(Assetsign::getTable(), ['loan_tickets_id' => $ticketsId], ['id' => (int) $row['id']]);
            $count++;
         }
      }

      return $count;
   }

   /**
    * @param array<string, mixed> $row
    */
   private static function openOverdueTicket(array $row): int {
      $sheet = new Assetsign();
      $sheet->fields = $row;
      $item = $sheet->getTargetItem();
      $beneficiary = $sheet->getBeneficiary();
      $name = trim(\formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? ''));
      $itemName = (string) ($item['name'] ?? ('#' . $row['items_id']));
      $dueDate = Html::convDate((string) $row['loan_until']);

      $content = TwigBlocks::content([
         TwigBlocks::p(sprintf(
            __('%1$s « %2$s » prêté à %3$s devait être rendu le %4$s. Le matériel est toujours affecté à cette personne.', 'assetsign'),
            Assetsign::getCanonicalItemtypeLabel((string) $row['itemtype']),
            $itemName,
            $name,
            $dueDate
         )),
         TwigBlocks::p(__('Récupérez le matériel puis faites signer la restitution, ou prolongez le prêt depuis la feuille.', 'assetsign')),
         TwigBlocks::link(
            Assetsign::getFormURLWithID((int) $row['id']),
            sprintf(__('Feuille de prêt n° %d', 'assetsign'), (int) $row['id'])
         ),
      ]);

      $input = [
         'name'        => sprintf(__('Retour de prêt en retard : %1$s (%2$s)', 'assetsign'), $itemName, $name),
         'content'     => $content,
         'entities_id' => (int) $row['entities_id'],
         'type'        => Ticket::DEMAND_TYPE,
      ];
      $tech = (int) $row['users_id_tech'];
      if ($tech > 0) {
         $input['_users_id_requester'] = $tech;
         $input['_users_id_assign'] = $tech;
      }
      if ((int) ($row['beneficiary_type'] ?? Assetsign::BENEFICIARY_INTERNAL) === Assetsign::BENEFICIARY_INTERNAL && (int) $row['users_id'] > 0) {
         $input['_users_id_observer'] = (int) $row['users_id'];
      }

      $ticketsId = (int) (new Ticket())->add($input);
      if ($ticketsId > 0) {
         (new Item_Ticket())->add(['tickets_id' => $ticketsId, 'itemtype' => (string) $row['itemtype'], 'items_id' => (int) $row['items_id']]);
      }

      return $ticketsId;
   }
}
