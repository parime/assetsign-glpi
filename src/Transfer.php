<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Log;
use User;

/**
 * Issue #370 : passation directe d'un detenteur a un autre. Deux fiches ORDINAIRES jumelees
 * (colonne `transfer_peer_id`, l'une pointe vers l'autre) : la restitution du detenteur sortant et
 * la remise a l'entrant. Chacune garde tout son comportement (PDF, lien et e-mail de signature,
 * signature sur place ou sur papier, preuve, passeport) ; la passation ajoute seulement :
 *
 * - l'attribution GLPI (champ Utilisateur du materiel, puis le materiel remis avec) n'est changee
 *   qu'une fois LES DEUX fiches signees, quel que soit l'ordre des signatures ;
 * - l'annulation ou l'expiration de l'une annule l'autre : une passation est entiere ou n'a pas lieu.
 *
 * Point d'entree de la creation : Assetsign::createTransfer() (acces aux etapes privees de la
 * creation d'une fiche) ; ici, la logique de suivi, appelee par Assetsign::post_updateItem().
 */
final class Transfer {

   /** Statuts qui annulent la passation (l'autre fiche est alors annulee aussi). */
   public const STATUSES_ABORTING = [Assetsign::STATUS_CANCELLED, Assetsign::STATUS_EXPIRED];

   /** Statuts d'une fiche encore en cours (ni signee, ni annulee, ni expiree). */
   public const STATUSES_OPEN = [
      Assetsign::STATUS_DRAFT,
      Assetsign::STATUS_PENDING,
      Assetsign::STATUS_SENT,
      Assetsign::STATUS_VIEWED,
      Assetsign::STATUS_AWAITING_COSIGNATURE,
   ];

   public static function getIcon(): string {
      return 'ti ti-arrows-exchange';
   }

   /** Vrai quand les deux fiches de la passation sont signees (electroniquement ou sur papier). */
   public static function isComplete(int $returnStatus, int $handoverStatus): bool {
      return in_array($returnStatus, Assetsign::STATUSES_SIGNED, true)
         && in_array($handoverStatus, Assetsign::STATUSES_SIGNED, true);
   }

   /** Vrai si cette fiche appartient a une passation dont l'attribution n'est pas encore faite. */
   public static function isAwaitingCompletion(Assetsign $sheet): bool {
      $peer = self::getPeer($sheet);
      if ($peer === null) {
         return false;
      }
      return !self::isComplete((int) $sheet->fields['status'], (int) $peer->fields['status']);
   }

   /** Fiche jumelle (null pour une fiche ordinaire ou si elle n'existe plus). */
   public static function getPeer(Assetsign $sheet): ?Assetsign {
      $peerId = (int) ($sheet->fields['transfer_peer_id'] ?? 0);
      if ($peerId <= 0) {
         return null;
      }
      $peer = new Assetsign();
      return $peer->getFromDB($peerId) ? $peer : null;
   }

   /**
    * Raison pour laquelle la passation est impossible sur ce materiel, '' si elle est possible.
    */
   public static function unavailableReason(CommonDBTM $item, Config $config): string {
      if ((int) ($item->fields['users_id'] ?? 0) <= 0) {
         return __('Aucun utilisateur n\'a ce matériel.', 'assetsign');
      }
      if (!$item->canUpdateItem()) {
         return __('Vous n\'avez pas le droit de modifier ce matériel.', 'assetsign');
      }
      if (empty($config->fields['sign_on_reassignment'])) {
         return __('Désactivé dans la configuration de l\'entité.', 'assetsign');
      }
      if (Assetsign::isInOpenDeparture($item)) {
         return __('Ce matériel figure dans un dossier de départ en cours.', 'assetsign');
      }
      return '';
   }

   /**
    * Appelee apres chaque changement de statut d'une fiche (Assetsign::post_updateItem()).
    */
   public static function onStatusChange(Assetsign $sheet): void {
      $peer = self::getPeer($sheet);
      if ($peer === null) {
         return;
      }
      $status = (int) $sheet->fields['status'];

      if (in_array($status, self::STATUSES_ABORTING, true)) {
         self::abortPeer($peer, $sheet);
         return;
      }

      $handover = (int) $sheet->fields['type'] === Assetsign::TYPE_HANDOVER ? $sheet : $peer;
      $return = $handover === $sheet ? $peer : $sheet;
      if (self::isComplete((int) $return->fields['status'], (int) $handover->fields['status'])) {
         self::complete($return, $handover);
      }
   }

   /**
    * Les deux signatures sont la : le materiel passe dans GLPI du sortant a l'entrant (une seule
    * fois : sans effet s'il est deja attribue a l'entrant), puis le materiel remis avec l'entrant.
    */
   private static function complete(Assetsign $return, Assetsign $handover): bool {
      $itemtype = (string) $handover->fields['itemtype'];
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
         return false;
      }
      $item = new $itemtype();
      $toUsersId = (int) $handover->fields['users_id'];
      if ($toUsersId <= 0 || !$item->getFromDB((int) $handover->fields['items_id']) || !$item->isField('users_id')) {
         return false;
      }
      if ((int) $item->fields['users_id'] === $toUsersId) {
         return false;
      }

      // '_assetsign_transfer' : pas de nouvelle feuille pour ce changement d'utilisateur
      // (cf. Assetsign::handleItemAssignment()), les deux fiches signees en tiennent lieu.
      $item->update(['id' => $item->getID(), 'users_id' => $toUsersId, '_assetsign_transfer' => true]);

      $message = sprintf(
         __('Passation terminée : matériel attribué à %1$s après les deux signatures (restitution n° %2$s, remise n° %3$s)', 'assetsign'),
         self::userName($toUsersId),
         Assetsign::displayReference($return->fields),
         Assetsign::displayReference($handover->fields)
      );
      foreach ([[$item->getID(), $itemtype], [$return->getID(), Assetsign::class], [$handover->getID(), Assetsign::class]] as [$id, $type]) {
         Log::history((int) $id, $type, ['0', '', $message], 0, Log::HISTORY_LOG_SIMPLE_MESSAGE);
      }

      $handover->assignHandedItemsInGlpi();
      return true;
   }

   /** Annule la fiche jumelle encore en cours, sans repasser par update() (pas de boucle). */
   private static function abortPeer(Assetsign $peer, Assetsign $origin): void {
      global $DB;

      if (!in_array((int) $peer->fields['status'], self::STATUSES_OPEN, true)) {
         return;
      }
      $DB->update(Assetsign::getTable(), ['status' => Assetsign::STATUS_CANCELLED], ['id' => $peer->getID()]);
      $peer->fields['status'] = Assetsign::STATUS_CANCELLED;
      Token::invalidateForAssetsign($peer->getID());
      Log::history(
         $peer->getID(),
         Assetsign::class,
         ['0', '', sprintf(
            __('Annulée automatiquement : la fiche jumelle n° %s de la passation a été annulée ou a expiré', 'assetsign'),
            Assetsign::displayReference($origin->fields)
         )],
         0,
         Log::HISTORY_LOG_SIMPLE_MESSAGE
      );
   }

   /**
    * Donnees de l'encadre « Passation » de la fiche (null pour une fiche ordinaire).
    *
    * @return array<string, mixed>|null
    */
   public static function getDisplayData(Assetsign $sheet): ?array {
      $peer = self::getPeer($sheet);
      if ($peer === null) {
         return null;
      }
      $handover = (int) $sheet->fields['type'] === Assetsign::TYPE_HANDOVER ? $sheet : $peer;
      $return = $handover === $sheet ? $peer : $sheet;
      $statuses = Assetsign::getStatuses();
      $badges = Assetsign::getStatusBadgeClasses();
      $line = static function (Assetsign $s) use ($statuses, $badges): array {
         $status = (int) $s->fields['status'];
         return [
            'id'          => $s->getID(),
            'reference'   => Assetsign::displayReference($s->fields),
            'user'        => self::userName((int) $s->fields['users_id']),
            'status'      => $statuses[$status] ?? '',
            'badge'       => $badges[$status] ?? 'bg-secondary-lt',
            'is_signed'   => in_array($status, Assetsign::STATUSES_SIGNED, true),
            'url'         => $s->getLinkURL(),
            'is_current'  => false,
         ];
      };
      $returnLine = $line($return);
      $handoverLine = $line($handover);
      $returnLine['is_current'] = $return->getID() === $sheet->getID();
      $handoverLine['is_current'] = $handover->getID() === $sheet->getID();

      $returnStatus = (int) $return->fields['status'];
      $handoverStatus = (int) $handover->fields['status'];
      return [
         'return'    => $returnLine,
         'handover'  => $handoverLine,
         'complete'  => self::isComplete($returnStatus, $handoverStatus),
         'aborted'   => in_array($returnStatus, self::STATUSES_ABORTING, true) || in_array($handoverStatus, self::STATUSES_ABORTING, true),
      ];
   }

   private static function userName(int $usersId): string {
      $user = new User();
      return $usersId > 0 && $user->getFromDB($usersId) ? $user->getFriendlyName() : '-';
   }

   /** Message de confirmation apres la creation (fiches envoyees ou laissees en brouillon). */
   public static function createdMessage(Assetsign $return, Assetsign $handover): string {
      return sprintf(
         __('Passation préparée : %1$s signe la restitution, %2$s signe la remise. Le matériel lui sera attribué dans GLPI après les deux signatures.', 'assetsign'),
         self::userName((int) $return->fields['users_id']),
         self::userName((int) $handover->fields['users_id'])
      );
   }
}
