<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonITILObject;
use Html;
use Item_Ticket;
use ITILFollowup;
use Session;
use Ticket;
use User;

/**
 * Issue #195 : ticket « Récupération du matériel de [personne] » ouvert pour l'équipe quand un
 * départ est préparé (manuellement, à la désactivation du compte ou X jours avant sa date de fin).
 *
 * Le ticket liste et lie chaque matériel du départ ; les étapes suivantes (signature, annulation,
 * jour du départ avec du matériel encore affecté) y ajoutent un suivi. Règle du projet : le plugin
 * ne change jamais un statut, une affectation ou un ticket de lui-même — c'est l'équipe qui traite
 * le ticket. En particulier, aucun suivi n'est ajouté à un ticket résolu ou clos (GLPI pourrait le
 * rouvrir).
 */
final class DepartureTicket
{
   public static function isEnabled(Departure $departure): bool {
      return !empty(Config::getForEntity((int) $departure->fields['entities_id'])->fields['departure_ticket']);
   }

   /**
    * Ouvre le ticket du départ s'il n'existe pas encore (un seul ticket par départ).
    *
    * @return int id du ticket, 0 si l'option est désactivée ou la création refusée
    */
   public static function open(Departure $departure): int {
      if ((int) ($departure->fields['tickets_id'] ?? 0) > 0) {
         return (int) $departure->fields['tickets_id'];
      }
      if (!self::isEnabled($departure)) {
         return 0;
      }

      $name = self::personName((int) $departure->fields['users_id']);
      $date = Html::convDate($departure->fields['date_departure'] ?? null);
      $title = $date !== ''
         ? sprintf(__('Récupération du matériel de %1$s (départ le %2$s)', 'assetsign'), $name, $date)
         : sprintf(__('Récupération du matériel de %s', 'assetsign'), $name);

      $content = '<p>' . htmlescape(sprintf(
         __('Le départ de %s est préparé : le matériel ci-dessous est à récupérer. La personne a reçu la fiche de restitution à signer.', 'assetsign'),
         $name
      )) . '</p>' . self::itemList($departure->getItemLines())
         . '<p>' . self::departureLink($departure) . '</p>';

      $tech = (int) ($departure->fields['users_id_tech'] ?? 0);
      $input = [
         'name'                => $title,
         'content'             => $content,
         'entities_id'         => (int) $departure->fields['entities_id'],
         'type'                => Ticket::DEMAND_TYPE,
         '_users_id_requester' => (int) (Session::getLoginUserID() ?: $departure->fields['users_id']),
      ];
      if ($tech > 0) {
         $input['_users_id_assign'] = $tech;
      }

      $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
      $ticketsId = (int) (new Ticket())->add($input);
      // « Votre ticket a été enregistré »… : message du cœur sans intérêt sur l'écran du départ.
      $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if ($ticketsId <= 0) {
         return 0;
      }

      $departure->update(['id' => $departure->getID(), 'tickets_id' => $ticketsId]);
      self::linkItems($departure);

      return $ticketsId;
   }

   /** Lie au ticket chaque matériel du départ pas encore lié (matériel ajouté après coup). */
   public static function linkItems(Departure $departure): void {
      $ticketsId = (int) ($departure->fields['tickets_id'] ?? 0);
      if ($ticketsId <= 0) {
         return;
      }
      foreach ($departure->getChildren() as $row) {
         $link = ['tickets_id' => $ticketsId, 'itemtype' => (string) $row['itemtype'], 'items_id' => (int) $row['items_id']];
         if (countElementsInTable(Item_Ticket::getTable(), $link) === 0) {
            (new Item_Ticket())->add($link);
         }
      }
   }

   /**
    * Suivi sur le ticket du départ, seulement s'il est encore ouvert.
    *
    * @return bool vrai si le suivi a été ajouté
    */
   public static function addFollowup(Departure $departure, string $html): bool {
      $ticketsId = (int) ($departure->fields['tickets_id'] ?? 0);
      $ticket = new Ticket();
      if ($ticketsId <= 0 || !$ticket->getFromDB($ticketsId)) {
         return false;
      }
      if (in_array((int) $ticket->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)) {
         return false;
      }

      return (bool) (new ITILFollowup())->add([
         'itemtype'   => Ticket::class,
         'items_id'   => $ticketsId,
         'content'    => $html,
         'is_private' => 0,
      ]);
   }

   public static function onSigned(Departure $departure): void {
      self::addFollowup($departure, '<p>' . htmlescape(sprintf(
         __('%1$s a signé la restitution de son matériel le %2$s. Le document signé est joint au départ.', 'assetsign'),
         self::personName((int) $departure->fields['users_id']),
         Html::convDateTime($departure->fields['date_signed'] ?? date('Y-m-d H:i:s'))
      )) . '</p><p>' . self::departureLink($departure) . '</p>');
   }

   public static function onCancelled(Departure $departure): void {
      self::addFollowup($departure, '<p>' . htmlescape(__('Le départ a été annulé dans assetsign : les fiches de restitution en attente sont annulées.', 'assetsign')) . '</p>');
   }

   /**
    * Jour du départ (tâche planifiée assetsignDepartures) : suivi qui liste le matériel encore
    * affecté dans GLPI à la personne. Une seule fois par départ. Rien n'est désaffecté.
    *
    * @return int nombre de suivis ajoutés
    */
   public static function runDepartureDayFollowups(): int {
      global $DB;

      $count = 0;
      $rows = $DB->request([
         'FROM'  => Departure::getTable(),
         'WHERE' => [
            'tickets_id'              => ['>', 0],
            'date_departure_followup' => null,
            'date_departure'          => ['<=', date('Y-m-d')],
            'NOT'                     => ['status' => DepartureLogic::STATUS_CANCELLED],
         ],
      ]);
      foreach ($rows as $row) {
         $departure = new Departure();
         if (!$departure->getFromDB((int) $row['id'])) {
            continue;
         }
         $remaining = AssignedItems::find((int) $row['users_id'], (int) $row['entities_id']);
         $html = $remaining === []
            ? '<p>' . htmlescape(__('Jour du départ : plus aucun matériel n\'est affecté à la personne dans GLPI.', 'assetsign')) . '</p>'
            : '<p>' . htmlescape(sprintf(
               _n(
                  'Jour du départ : %d matériel est encore affecté à la personne dans GLPI.',
                  'Jour du départ : %d matériels sont encore affectés à la personne dans GLPI.',
                  count($remaining),
                  'assetsign'
               ),
               count($remaining)
            )) . '</p>' . self::itemList(array_map(static fn (CommonDBTM $item): array => AssignedItems::snapshot($item), $remaining));
         if (self::addFollowup($departure, $html)) {
            $count++;
         }
         // Marqué même si le ticket est déjà clos : le suivi du jour du départ n'est tenté qu'une fois.
         $departure->update(['id' => $departure->getID(), 'date_departure_followup' => date('Y-m-d H:i:s')]);
      }

      return $count;
   }

   /**
    * @param list<array{type_label: string, name: string, serial: string}> $lines
    */
   private static function itemList(array $lines): string {
      if ($lines === []) {
         return '';
      }
      $html = '<ul>';
      foreach ($lines as $line) {
         $label = trim($line['type_label'] . ' « ' . $line['name'] . ' »');
         if ($line['serial'] !== '') {
            $label .= ' — ' . sprintf(__('N° de série %s', 'assetsign'), $line['serial']);
         }
         $html .= '<li>' . htmlescape($label) . '</li>';
      }

      return $html . '</ul>';
   }

   private static function departureLink(Departure $departure): string {
      return '<a href="' . htmlescape(Departure::getFormURLWithID($departure->getID())) . '">'
         . htmlescape(sprintf(__('Voir le départ n° %d dans assetsign', 'assetsign'), $departure->getID())) . '</a>';
   }

   private static function personName(int $usersId): string {
      $user = new User();
      if (!$user->getFromDB($usersId)) {
         return '#' . $usersId;
      }

      return trim(\formatUserName(0, $user->fields['name'] ?? '', $user->fields['realname'] ?? '', $user->fields['firstname'] ?? ''));
   }
}
