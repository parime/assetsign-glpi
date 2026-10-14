<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Document;
use Item_Ticket;
use Session;
use Ticket;
use User;

/**
 * Issue #194 : « Mon matériel » dans le portail self-service (page « Mes documents à signer »).
 *
 * L'utilisateur voit le matériel qui lui est affecté dans GLPI, les feuilles qu'il a signées pour
 * ce matériel (PDF servis par le plugin après contrôle : c'est bien lui le bénéficiaire, sans droit
 * sur le matériel ni sur les documents GLPI), et peut signaler un problème : un ticket lié au
 * matériel est créé à son nom. Rien n'est modifié sur le matériel lui-même — c'est l'équipe qui
 * traite le ticket.
 */
final class MyEquipment
{
   public const PROBLEM_BREAKDOWN = 'breakdown';
   public const PROBLEM_DAMAGE    = 'damage';
   public const PROBLEM_LOSS      = 'loss';
   public const PROBLEM_THEFT     = 'theft';

   /** Statuts d'une feuille dont le PDF signé peut être consulté. */
   private const SIGNED_STATUSES = [Assetsign::STATUS_SIGNED, Assetsign::STATUS_AWAITING_COSIGNATURE];

   /**
    * @return array<string, string>
    */
   public static function getProblemTypes(): array {
      return [
         self::PROBLEM_BREAKDOWN => __('Panne', 'assetsign'),
         self::PROBLEM_DAMAGE    => __('Casse', 'assetsign'),
         self::PROBLEM_LOSS      => __('Perte', 'assetsign'),
         self::PROBLEM_THEFT     => __('Vol', 'assetsign'),
      ];
   }

   /**
    * Matériel affecté à l'utilisateur, avec ses feuilles signées (la plus récente d'abord).
    *
    * @return list<array{key: string, itemtype: string, items_id: int, type_label: string, name: string, model: string, serial: string, otherserial: string, documents: list<array{id: int, label: string, date: string}>}>
    */
   public static function forUser(int $usersId): array {
      $user = new User();
      if ($usersId <= 0 || !$user->getFromDB($usersId)) {
         return [];
      }

      $rows = [];
      foreach (AssignedItems::find($usersId, (int) $user->fields['entities_id']) as $item) {
         $snapshot = AssignedItems::snapshot($item);
         $rows[] = [
            'key'         => $item->getType() . ':' . $item->getID(),
            'itemtype'    => $item->getType(),
            'items_id'    => $item->getID(),
            'type_label'  => $snapshot['type_label'],
            'name'        => $snapshot['name'],
            'model'       => trim(Assetsign::resolveManufacturerName($item) . ' ' . Assetsign::resolveModelName($item)),
            'serial'      => $snapshot['serial'],
            'otherserial' => $snapshot['otherserial'],
            'documents'   => self::signedDocuments($item, $usersId),
         ];
      }

      return $rows;
   }

   /**
    * Feuilles signées par l'utilisateur pour ce matériel : feuille du matériel lui-même, ou feuille
    * d'un autre matériel avec lequel il a été remis (écran remis avec l'ordinateur, #166).
    *
    * @return list<array{id: int, label: string, date: string}>
    */
   private static function signedDocuments(CommonDBTM $item, int $usersId): array {
      global $DB;

      $sheetIds = [];
      foreach ($DB->request([
         'SELECT' => ['plugin_assetsign_assetsigns_id'],
         'FROM'   => AssetsignItem::getTable(),
         'WHERE'  => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
      ]) as $row) {
         $sheetIds[] = (int) $row['plugin_assetsign_assetsigns_id'];
      }

      $where = [
         'users_id'           => $usersId,
         'status'             => self::SIGNED_STATUSES,
         'is_deleted'         => 0,
         'document_id_signed' => ['>', 0],
      ];
      $match = ['itemtype' => $item->getType(), 'items_id' => $item->getID()];
      $where[] = $sheetIds === [] ? $match : ['OR' => [$match, ['id' => $sheetIds]]];

      $types = Assetsign::getTypes();
      $documents = [];
      foreach ($DB->request(['FROM' => Assetsign::getTable(), 'WHERE' => $where, 'ORDER' => 'date_signed DESC']) as $row) {
         $documents[] = [
            'id'    => (int) $row['id'],
            'label' => (string) ($types[(int) $row['type']] ?? ''),
            'date'  => (string) $row['date_signed'],
         ];
      }

      return $documents;
   }

   /**
    * PDF signé d'une feuille dont l'utilisateur est le bénéficiaire.
    */
   public static function signedDocumentFor(int $assetsignsId, int $usersId): ?Document {
      $sheet = new Assetsign();
      if ($usersId <= 0 || !$sheet->getFromDB($assetsignsId)
         || (int) $sheet->fields['users_id'] !== $usersId
         || (int) ($sheet->fields['beneficiary_type'] ?? Assetsign::BENEFICIARY_INTERNAL) !== Assetsign::BENEFICIARY_INTERNAL
         || !in_array((int) $sheet->fields['status'], self::SIGNED_STATUSES, true)
         || (int) $sheet->fields['is_deleted'] === 1
      ) {
         return null;
      }
      $document = new Document();

      return $document->getFromDB((int) $sheet->fields['document_id_signed']) ? $document : null;
   }

   /**
    * Ticket à son nom, lié au matériel. Refusé si le matériel ne lui est pas affecté.
    *
    * @return int id du ticket
    */
   public static function reportProblem(int $usersId, string $key, string $problem, string $description): int {
      $types = self::getProblemTypes();
      if (!isset($types[$problem])) {
         throw new \RuntimeException(__('Choisissez le type de problème.', 'assetsign'));
      }
      $owned = array_column(self::forUser($usersId), null, 'key');
      if (!isset($owned[$key])) {
         throw new \RuntimeException(__('Ce matériel ne vous est pas affecté.', 'assetsign'));
      }
      if (!Session::haveRight(Ticket::$rightname, CREATE)) {
         throw new \RuntimeException(__('Votre profil ne permet pas de créer un ticket : contactez le service informatique.', 'assetsign'));
      }

      $item = $owned[$key];
      $itemLabel = trim($item['type_label'] . ' « ' . $item['name'] . ' »');
      $details = array_filter([
         $item['model'],
         $item['serial'] !== '' ? sprintf(__('N° de série %s', 'assetsign'), $item['serial']) : '',
         $item['otherserial'] !== '' ? sprintf(__('N° d\'inventaire %s', 'assetsign'), $item['otherserial']) : '',
      ]);
      $content = '<p>' . htmlescape(sprintf(__('%1$s signalé(e) sur %2$s.', 'assetsign'), $types[$problem], $itemLabel)) . '</p>';
      if ($details !== []) {
         $content .= '<p>' . htmlescape(implode(' — ', $details)) . '</p>';
      }
      if (trim($description) !== '') {
         $content .= '<p>' . nl2br(htmlescape(trim($description))) . '</p>';
      }
      if (in_array($problem, [self::PROBLEM_LOSS, self::PROBLEM_THEFT], true)) {
         $content .= '<p>' . htmlescape(__('Perte ou vol : le service informatique vous indiquera les démarches (déclaration, blocage des accès, remplacement).', 'assetsign')) . '</p>';
      }

      $ticketsId = (int) (new Ticket())->add([
         'name'                => sprintf(__('%1$s : %2$s', 'assetsign'), $types[$problem], $item['name']),
         'content'             => $content,
         'type'                => Ticket::INCIDENT_TYPE,
         'entities_id'         => (int) Session::getActiveEntity(),
         '_users_id_requester' => $usersId,
      ]);
      if ($ticketsId <= 0) {
         throw new \RuntimeException(__('Le ticket n\'a pas pu être créé : contactez le service informatique.', 'assetsign'));
      }
      (new Item_Ticket())->add(['tickets_id' => $ticketsId, 'itemtype' => $item['itemtype'], 'items_id' => $item['items_id']]);

      return $ticketsId;
   }
}
