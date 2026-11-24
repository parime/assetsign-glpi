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
use CommonGLPI;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;
use Ticket;

/**
 * Issue #326 : chaîne de possession d'un matériel saisi lors d'un incident ou d'une enquête
 * (ISO 27001, mesure A.5.28 sur la collecte des preuves).
 *
 * Une fiche par saisie : motif, état constaté, numéro de scellé, photos, lien vers le ticket et,
 * quand le plugin GRC est présent, vers l'incident de sécurité. Chaque étape (saisie, transfert
 * d'une personne à une autre, clôture par restitution, conservation ou destruction) est signée sur
 * place par la personne qui remet et par celle qui reçoit, comme une remise (même capture de
 * signature, même contrôle de l'image que les autres fiches du plugin), puis enregistrée une fois
 * pour toutes dans CustodyEvent.
 *
 * Après chaque étape, un nouveau PDF reprend tout l'historique ; il est enregistré comme une
 * nouvelle version (Document GLPI) sans jamais remplacer les précédentes, et cite l'empreinte
 * SHA-256 de chaque version antérieure : une version retouchée se voit à la lecture de la
 * suivante. Le PDF est lié à la fiche, au matériel, au ticket et à l'incident (le registre des
 * preuves de GRC l'inscrit alors avec son empreinte).
 *
 * Accès réservé au droit dédié Profile::RIGHT_CUSTODY, limité aux entités de la session. Volontairement
 * absent du passeport du matériel : une saisie ne doit pas être visible de tous les techniciens.
 */
class CustodyCase extends Compat\Base\CustodyCaseBase
{
   public const RIGHTNAME = Profile::RIGHT_CUSTODY;

   public const STATUS_OPEN   = 0;
   public const STATUS_CLOSED = 1;

   public const CLOSURE_RETURNED  = 'returned';
   public const CLOSURE_RETAINED  = 'retained';
   public const CLOSURE_DESTROYED = 'destroyed';

   public const MAX_PHOTOS = 12;

   public const GRC_PLUGIN = 'grcmanager';
   public const GRC_INCIDENT_CLASS = 'PluginGrcmanagerSecurityIncident';
   public const GRC_INCIDENT_FIELD = 'plugin_grcmanager_securityincidents_id';
   public const GRC_INCIDENT_ITEMS_TABLE = 'glpi_plugin_grcmanager_securityincidents_items';

   private const MAX_TEXT = 255;

    /** Champs fixés à l'ouverture (signés), jamais modifiés ensuite. */
   private const UPDATABLE_FIELDS = [
        'id', 'status', 'closure', 'date_closed', 'users_id_holder', 'name_holder', 'locations_id',
        'storage', 'documents_id', 'pdf_hash', 'pdf_version', 'photos',
    ];

   public static function getTypeName($nb = 0): string {
       return _n('Chaîne de possession', 'Chaînes de possession', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-shield-lock';
   }

    /** Secteur 'assets' (Parc), comme les autres écrans d'usage. */
   public static function getSectorizedDetails(): array {
       return ['assets', Menu::class, self::class];
   }

    /** Pas de bouton « Ajouter » : une saisie s'ouvre depuis le matériel, le ticket ou l'incident. */
   public static function getMenuContent(): array {
       return Assetsign::withoutAddLink(parent::getMenuContent());
   }

    /** @return array<int, string> */
   public static function getStatusLabels(): array {
       return [
           self::STATUS_OPEN   => __('Sous scellé', 'assetsign'),
           self::STATUS_CLOSED => __('Clôturée', 'assetsign'),
       ];
   }

   public static function getStatusColor(int $status): string {
       return $status === self::STATUS_OPEN ? '#f76707' : '#2fb344';
   }

    /** @return array<string, string> */
   public static function getClosureLabels(): array {
       return [
           self::CLOSURE_RETURNED  => __('Restitution à l\'utilisateur', 'assetsign'),
           self::CLOSURE_RETAINED  => __('Conservation (archivage de la pièce)', 'assetsign'),
           self::CLOSURE_DESTROYED => __('Destruction', 'assetsign'),
       ];
   }

    /** Le plugin GRC est actif et son incident de sécurité est chargé. */
   public static function isGrcAvailable(): bool {
       return \Plugin::isPluginActive(self::GRC_PLUGIN) && class_exists(self::GRC_INCIDENT_CLASS);
   }

    /** L'utilisateur peut-il ouvrir cet incident GRC ? */
   private static function readableIncident(int $incidentId): ?CommonDBTM {
      if ($incidentId <= 0 || !self::isGrcAvailable()) {
          return null;
      }
       $incident = getItemForItemtype(self::GRC_INCIDENT_CLASS);
      if (!$incident instanceof CommonDBTM || !$incident->getFromDB($incidentId) || !$incident->can($incidentId, READ)) {
          return null;
      }
       return $incident;
   }

   private static function readableTicket(int $ticketId): ?Ticket {
       $ticket = new Ticket();
      if ($ticketId <= 0 || !$ticket->getFromDB($ticketId) || !$ticket->can($ticketId, READ)) {
          return null;
      }
       return $ticket;
   }

    // ------------------------------------------------------------------ Recherche

   public function rawSearchOptions(): array {
       $table = self::getTable();
       return [
           ['id' => 'common', 'name' => self::getTypeName(1)],
           ['id' => 1, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink', 'itemtype' => self::class],
           ['id' => 2, 'table' => $table, 'field' => 'itemtype', 'name' => __('Type de matériel', 'assetsign'), 'datatype' => 'itemtype'],
           ['id' => 3, 'table' => $table, 'field' => 'items_id', 'name' => __('Matériel', 'assetsign'), 'datatype' => 'specific', 'additionalfields' => ['itemtype'], 'nosearch' => true],
           ['id' => 4, 'table' => $table, 'field' => 'seal_number', 'name' => __('N° de scellé', 'assetsign'), 'datatype' => 'string'],
           ['id' => 5, 'table' => $table, 'field' => 'date_seized', 'name' => __('Date de la saisie', 'assetsign'), 'datatype' => 'datetime'],
           ['id' => 6, 'table' => $table, 'field' => 'status', 'name' => __('Statut', 'assetsign'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
           ['id' => 7, 'table' => $table, 'field' => 'name_holder', 'name' => __('Détenteur actuel', 'assetsign'), 'datatype' => 'string'],
           ['id' => 8, 'table' => 'glpi_locations', 'field' => 'completename', 'linkfield' => 'locations_id', 'name' => __('Lieu de stockage', 'assetsign'), 'datatype' => 'dropdown'],
           ['id' => 9, 'table' => 'glpi_tickets', 'field' => 'name', 'linkfield' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'itemlink', 'itemlink_type' => 'Ticket'],
           ['id' => 10, 'table' => $table, 'field' => 'closure', 'name' => __('Issue de la saisie', 'assetsign'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
           ['id' => 11, 'table' => $table, 'field' => 'documents_id', 'name' => __('PDF', 'assetsign'), 'datatype' => 'specific', 'nosearch' => true],
           ['id' => 12, 'table' => $table, 'field' => 'pdf_version', 'name' => __('Version du PDF', 'assetsign'), 'datatype' => 'integer'],
           ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown'],
       ];
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
      switch ($field) {
         case 'items_id':
             $item = Assetsign::resolveReadableItem((string) ($values['itemtype'] ?? ''), (int) ($values[$field] ?? 0));
            if ($item === null) {
               return htmlescape('#' . (int) ($values[$field] ?? 0));
            }
              return TwigBlocks::fragment('components/item_link.html.twig', [
                  'url'   => (string) $item->getLinkURL(),
                  'label' => (string) $item->getName(),
              ]);
         case 'status':
            $status = (int) ($values[$field] ?? 0);
              return TwigBlocks::fragment('components/status_badge.html.twig', [
                  'color' => self::getStatusColor($status),
                  'label' => self::getStatusLabels()[$status] ?? '',
              ]);
         case 'closure':
              return htmlescape(self::getClosureLabels()[(string) ($values[$field] ?? '')] ?? '');
         case 'documents_id':
             $documentsId = (int) ($values[$field] ?? 0);
            if ($documentsId <= 0) {
               return '';
            }
             global $CFG_GLPI;
              return TwigBlocks::fragment('components/document_link.html.twig', [
                  'url'   => $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documentsId,
                  'label' => __('Télécharger', 'assetsign'),
              ]);
      }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

    // ------------------------------------------------------------------ Onglets

   private static function isContext(CommonGLPI $item): bool {
       return $item instanceof Ticket || (self::isGrcAvailable() && is_a($item, self::GRC_INCIDENT_CLASS));
   }

    /** @return array<string, int|string> critère des fiches liées à l'objet de l'onglet */
   private static function criteriaFor(CommonDBTM $item): array {
      if ($item instanceof Ticket) {
          return ['tickets_id' => $item->getID()];
      }
      if (self::isContext($item)) {
          return [self::GRC_INCIDENT_FIELD => $item->getID()];
      }
       return ['itemtype' => $item->getType(), 'items_id' => $item->getID()];
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if (!(bool) Session::haveRight(self::$rightname, READ) || !($item instanceof CommonDBTM) || $item->isNewItem()) {
          return '';
      }
      if (!self::isContext($item) && !in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return '';
      }
       $count = countElementsInTable(self::getTable(), self::criteriaFor($item) + getEntitiesRestrictCriteria(self::getTable(), '', '', true));
       return self::createTabEntry(__('Chaîne de possession', 'assetsign'), $count, null, self::getIcon());
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!(bool) Session::haveRight(self::$rightname, READ) || !($item instanceof CommonDBTM)) {
          return false;
      }
       self::showForItem($item);
       return true;
   }

    /**
     * Onglet « Chaîne de possession » : fiches liées au matériel, au ticket ou à l'incident, et
     * formulaire d'ouverture d'une saisie.
     */
   public static function showForItem(CommonDBTM $item): void {
       $isContext = self::isContext($item);
       $entitiesId = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());

       $candidates = [];
      if ($isContext) {
          $candidates = self::linkedItems($item);
      }

       TemplateRenderer::getInstance()->display('@assetsign/custodycase_tab.html.twig', [
           'cases'          => self::listRows(self::criteriaFor($item)),
           'is_context'     => $isContext,
           'context_label'  => $item instanceof Ticket ? __('Ticket') : __('Incident de sécurité', 'assetsign'),
           'item'           => $item,
           'candidates'     => $candidates,
           'can_create'     => (bool) Session::haveRight(self::$rightname, CREATE),
           'entities_id'    => $entitiesId,
           'default_from'   => $isContext ? 0 : (int) ($item->fields['users_id'] ?? 0),
           'tickets_id'     => $item instanceof Ticket ? $item->getID() : 0,
           'incident_id'    => ($isContext && !$item instanceof Ticket) ? $item->getID() : 0,
           'grc_available'  => self::isGrcAvailable(),
           'grc_class'      => self::GRC_INCIDENT_CLASS,
           'now'            => date('Y-m-d\TH:i'),
           'max_photos'     => self::MAX_PHOTOS,
           'csrf_token'     => Session::getNewCSRFToken(),
           'current_user'   => (int) Session::getLoginUserID(),
       ]);
   }

    /**
     * Fiches visibles (entités de la session) répondant au critère, prêtes pour l'affichage.
     *
     * @param array<string, mixed> $criteria
     * @return list<array<string, mixed>>
     */
   public static function listRows(array $criteria): array {
       global $DB;

       $rows = [];
      foreach ($DB->request([
               'FROM'  => self::getTable(),
               'WHERE' => $criteria + getEntitiesRestrictCriteria(self::getTable(), '', '', true),
               'ORDER' => 'date_seized DESC',
           ]) as $row
       ) {
          $item = Assetsign::resolveReadableItem((string) $row['itemtype'], (int) $row['items_id']);
          $row['item_name'] = $item !== null ? (string) $item->getName() : '#' . (int) $row['items_id'];
          $row['item_url'] = $item !== null ? (string) $item->getLinkURL() : '';
          $row['status_label'] = self::getStatusLabels()[(int) $row['status']] ?? '';
          $row['closure_label'] = self::getClosureLabels()[(string) $row['closure']] ?? '';
          $row['location_name'] = (int) $row['locations_id'] > 0 ? \Dropdown::getDropdownName('glpi_locations', (int) $row['locations_id']) : '';
          $rows[] = $row;
      }
       return $rows;
   }

    /**
     * Matériels gérés liés au ticket (onglet Éléments) ou à l'incident GRC, que l'utilisateur peut lire.
     *
     * @return list<array{value: string, label: string}>
     */
   public static function linkedItems(CommonDBTM $context): array {
       global $DB;

      if ($context instanceof Ticket) {
          $table = 'glpi_items_tickets';
          $field = 'tickets_id';
      } else {
          $table = self::GRC_INCIDENT_ITEMS_TABLE;
          $field = self::GRC_INCIDENT_FIELD;
         if (!$DB->tableExists($table)) {
             return [];
         }
      }
       $items = [];
      foreach ($DB->request(['SELECT' => ['itemtype', 'items_id'], 'FROM' => $table, 'WHERE' => [$field => $context->getID()]]) as $row) {
          $item = Assetsign::resolveReadableItem((string) $row['itemtype'], (int) $row['items_id']);
         if ($item === null) {
             continue;
         }
          $value = $item->getType() . ':' . $item->getID();
          $items[$value] = [
              'value' => $value,
              'label' => Assetsign::getItemtypeLabel($item->getType()) . ' : ' . $item->getName(),
          ];
      }
       return array_values($items);
   }

    // ------------------------------------------------------------------ Écriture

    /**
     * Ouvre une chaîne de possession : la saisie est la première étape signée.
     *
     * @param array<string, mixed> $input
     * @param list<array{path: string}> $photos fichiers déjà présents sur le serveur
     * @param array{ip?: string, user_agent?: string} $meta
     * @throws \RuntimeException message pour l'utilisateur
     */
   public static function open(CommonDBTM $item, array $input, string $signatureFrom, string $signatureTo, array $photos = [], array $meta = []): self {
       $reason = trim((string) ($input['reason'] ?? ''));
      if ($reason === '') {
          throw new \RuntimeException(__('Indiquez le motif de la saisie.', 'assetsign'));
      }
      if (count($photos) > self::MAX_PHOTOS) {
          throw new \RuntimeException(sprintf(__('Une saisie compte %d photos au plus.', 'assetsign'), self::MAX_PHOTOS));
      }
       $date = self::normalizeDate((string) ($input['event_date'] ?? ''));
       [$fromId, $fromName] = self::resolvePerson((int) ($input['users_id_from'] ?? 0), (string) ($input['name_from'] ?? ''), __('Indiquez la personne qui remet le matériel.', 'assetsign'));
       [$toId, $toName] = self::resolvePerson((int) ($input['users_id_to'] ?? 0), (string) ($input['name_to'] ?? ''), __('Indiquez la personne qui reçoit le matériel.', 'assetsign'));
       self::assertDistinct($fromId, $fromName, $toId, $toName);

       $ticketId = (int) ($input['tickets_id'] ?? 0);
      if ($ticketId > 0 && self::readableTicket($ticketId) === null) {
          throw new \RuntimeException(__('Ticket introuvable.', 'assetsign'));
      }
       $incidentId = (int) ($input['incident_id'] ?? 0);
      if ($incidentId > 0 && self::readableIncident($incidentId) === null) {
          throw new \RuntimeException(__('Incident de sécurité introuvable.', 'assetsign'));
      }
       Pdf\SignatureImageValidator::assertValid($signatureFrom);
       Pdf\SignatureImageValidator::assertValid($signatureTo);
       $jpegs = [];
      foreach ($photos as $photo) {
          $jpegs[] = ConditionPhoto::sanitize((string) $photo['path']);
      }

       $locationId = (int) ($input['locations_id'] ?? 0);
       $storage = mb_substr(trim((string) ($input['storage'] ?? '')), 0, self::MAX_TEXT);
       $case = new self();
       $id = (int) $case->add([
           'entities_id'           => (int) ($item->fields['entities_id'] ?? 0),
           'itemtype'              => $item->getType(),
           'items_id'              => $item->getID(),
           'reason'                => $reason,
           'condition_note'        => trim((string) ($input['condition_note'] ?? '')),
           'seal_number'           => mb_substr(trim((string) ($input['seal_number'] ?? '')), 0, self::MAX_TEXT),
           'tickets_id'            => $ticketId,
           self::GRC_INCIDENT_FIELD => $incidentId,
           'status'                => self::STATUS_OPEN,
           'date_seized'           => $date,
           'users_id'              => (int) (Session::getLoginUserID() ?: 0),
           'users_id_holder'       => $toId,
           'name_holder'           => $toName,
           'locations_id'          => $locationId,
           'storage'               => $storage,
       ]);
      if ($id <= 0 || !$case->getFromDB($id)) {
          throw new \RuntimeException(__('Échec de l\'ouverture de la chaîne de possession.', 'assetsign'));
      }

       $stored = [];
      foreach ($jpegs as $index => $jpeg) {
          $stored[] = $case->storeFile($jpeg, 'jpg', sprintf(__('Photo de la saisie %1$d - chaîne de possession n°%2$d', 'assetsign'), $index + 1, $id));
      }
      if ($stored !== []) {
          $case->update(['id' => $id, 'photos' => json_encode($stored)]);
          $case->getFromDB($id);
      }

       $case->recordEvent(CustodyEvent::TYPE_SEIZURE, [
           'event_date'    => $date,
           'users_id_from' => $fromId,
           'name_from'     => $fromName,
           'users_id_to'   => $toId,
           'name_to'       => $toName,
           'locations_id'  => $locationId,
           'storage'       => $storage,
           'comment'       => trim((string) ($input['comment'] ?? '')),
       ], $signatureFrom, $signatureTo, $meta);

       return $case;
   }

    /**
     * Transfert du détenteur actuel vers une autre personne (et éventuellement un autre lieu).
     *
     * @param array<string, mixed> $input
     * @param array{ip?: string, user_agent?: string} $meta
     * @throws \RuntimeException
     */
   public function transfer(array $input, string $signatureFrom, string $signatureTo, array $meta = []): void {
       $this->assertOpen();
       $this->recordHandover(CustodyEvent::TYPE_TRANSFER, '', $input, $signatureFrom, $signatureTo, $meta);
   }

    /**
     * Clôture : restitution à l'utilisateur, conservation ou destruction, signée par le détenteur
     * et par la personne qui reçoit (ou qui détruit) la pièce.
     *
     * @param array<string, mixed> $input
     * @param array{ip?: string, user_agent?: string} $meta
     * @throws \RuntimeException
     */
   public function close(array $input, string $signatureFrom, string $signatureTo, array $meta = []): void {
       $this->assertOpen();
       $closure = (string) ($input['closure'] ?? '');
      if (!array_key_exists($closure, self::getClosureLabels())) {
          throw new \RuntimeException(__('Choisissez l\'issue de la saisie : restitution, conservation ou destruction.', 'assetsign'));
      }
       $this->recordHandover(CustodyEvent::TYPE_CLOSURE, $closure, $input, $signatureFrom, $signatureTo, $meta);
   }

    /**
     * @param array<string, mixed> $input
     * @param array{ip?: string, user_agent?: string} $meta
     */
   private function recordHandover(int $type, string $closure, array $input, string $signatureFrom, string $signatureTo, array $meta): void {
       $date = self::normalizeDate((string) ($input['event_date'] ?? ''));
      if ($date < (string) $this->fields['date_seized']) {
          throw new \RuntimeException(__('Cette étape ne peut pas précéder la saisie.', 'assetsign'));
      }
       [$toId, $toName] = self::resolvePerson((int) ($input['users_id_to'] ?? 0), (string) ($input['name_to'] ?? ''), __('Indiquez la personne qui reçoit le matériel.', 'assetsign'));
       $fromId = (int) $this->fields['users_id_holder'];
       $fromName = (string) $this->fields['name_holder'];
       self::assertDistinct($fromId, $fromName, $toId, $toName);
       Pdf\SignatureImageValidator::assertValid($signatureFrom);
       Pdf\SignatureImageValidator::assertValid($signatureTo);

       $locationId = array_key_exists('locations_id', $input) ? (int) $input['locations_id'] : (int) $this->fields['locations_id'];
       $storage = mb_substr(trim((string) ($input['storage'] ?? '')), 0, self::MAX_TEXT);

       $this->recordEvent($type, [
           'event_date'    => $date,
           'users_id_from' => $fromId,
           'name_from'     => $fromName,
           'users_id_to'   => $toId,
           'name_to'       => $toName,
           'locations_id'  => $locationId,
           'storage'       => $storage,
           'closure'       => $closure,
           'comment'       => trim((string) ($input['comment'] ?? '')),
       ], $signatureFrom, $signatureTo, $meta);

       $update = [
           'id'              => $this->getID(),
           'users_id_holder' => $toId,
           'name_holder'     => $toName,
           'locations_id'    => $locationId,
           'storage'         => $storage,
       ];
       if ($type === CustodyEvent::TYPE_CLOSURE) {
           $update += ['status' => self::STATUS_CLOSED, 'closure' => $closure, 'date_closed' => $date];
       }
       $this->update($update);
       $this->getFromDB($this->getID());
       // Le PDF de cette étape reprend le nouvel état de la fiche (détenteur, statut).
       $this->buildPdfVersion();
   }

    /**
     * Enregistre l'étape signée ; à la saisie, le PDF est produit aussitôt (pour les autres étapes,
     * après la mise à jour de la fiche, cf. recordHandover()).
     *
     * @param array<string, mixed> $data
     * @param array{ip?: string, user_agent?: string} $meta
     */
   private function recordEvent(int $type, array $data, string $signatureFrom, string $signatureTo, array $meta): void {
       $id = $this->getID();
       $label = CustodyEvent::getTypeLabels()[$type] ?? '';
       $from = $this->storeSignature($signatureFrom, sprintf(__('Signature de la personne qui remet (%1$s) - chaîne de possession n°%2$d', 'assetsign'), $label, $id));
       $to = $this->storeSignature($signatureTo, sprintf(__('Signature de la personne qui reçoit (%1$s) - chaîne de possession n°%2$d', 'assetsign'), $label, $id));

       CustodyEvent::insert([
           'plugin_assetsign_custodycases_id' => $id,
           'event_type'            => $type,
           'event_date'            => $data['event_date'],
           'users_id_from'         => (int) $data['users_id_from'],
           'name_from'             => (string) $data['name_from'],
           'users_id_to'           => (int) $data['users_id_to'],
           'name_to'               => (string) $data['name_to'],
           'locations_id'          => (int) $data['locations_id'],
           'storage'               => (string) $data['storage'],
           'closure'               => (string) ($data['closure'] ?? ''),
           'comment'               => (string) $data['comment'],
           'sig_from_documents_id' => $from['documents_id'],
           'sig_from_hash'         => $from['sha256'],
           'sig_to_documents_id'   => $to['documents_id'],
           'sig_to_hash'           => $to['sha256'],
           'users_id'              => (int) (Session::getLoginUserID() ?: 0),
           'signed_at'             => date('Y-m-d H:i:s'),
           'ip_address'            => mb_substr((string) ($meta['ip'] ?? ''), 0, 46),
           'user_agent'            => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 512),
       ]);

      if ($type === CustodyEvent::TYPE_SEIZURE) {
          $this->buildPdfVersion();
      }
   }

    /**
     * Nouvelle version du PDF après la dernière étape : enregistrée comme un nouveau Document (les
     * versions précédentes restent), liée à la fiche, au matériel, au ticket et à l'incident.
     */
   private function buildPdfVersion(): void {
       $events = CustodyEvent::getForCase($this->getID());
       $last = end($events);
      if ($last === false) {
          return;
      }
       $version = (int) $this->fields['pdf_version'] + 1;
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $built = (new Pdf\CustodyPdfBuilder())->build($this, $events, $version);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

       CustodyEvent::setPdfVersion((int) $last['id'], $version, $built['documents_id'], $built['hash']);
       $this->update([
           'id'           => $this->getID(),
           'documents_id' => $built['documents_id'],
           'pdf_hash'     => $built['hash'],
           'pdf_version'  => $version,
       ]);
       $this->getFromDB($this->getID());
   }

    /**
     * Objets auxquels chaque version du PDF est liée (onglet Documents).
     *
     * @return list<array{0: string, 1: int}>
     */
   public function getDocumentTargets(): array {
       $targets = [
           [self::class, $this->getID()],
           [(string) $this->fields['itemtype'], (int) $this->fields['items_id']],
       ];
       if ((int) $this->fields['tickets_id'] > 0) {
           $targets[] = ['Ticket', (int) $this->fields['tickets_id']];
       }
       if ((int) ($this->fields[self::GRC_INCIDENT_FIELD] ?? 0) > 0 && self::isGrcAvailable()) {
           $targets[] = [self::GRC_INCIDENT_CLASS, (int) $this->fields[self::GRC_INCIDENT_FIELD]];
       }
       return $targets;
   }

    /**
     * Image de signature (PNG du canevas) enregistrée comme Document lié à la fiche seulement.
     *
     * @return array{documents_id: int, sha256: string}
     */
   private function storeSignature(string $dataUri, string $name): array {
       preg_match('/^data:image\/png;base64,(.+)$/', $dataUri, $matches);
       return $this->storeFile((string) base64_decode($matches[1] ?? '', true), 'png', $name);
   }

    /**
     * Fichier enregistré comme Document GLPI lié à la fiche, avec son empreinte SHA-256 contrôlée
     * après l'écriture.
     *
     * @return array{documents_id: int, sha256: string}
     * @throws \RuntimeException
     */
   private function storeFile(string $binary, string $extension, string $name): array {
       $hash = hash('sha256', $binary);
       $tmpName = uniqid('assetsign_custody_', true) . '.' . $extension;
      if ($binary === '' || file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $binary) === false) {
          throw new \RuntimeException(__('Échec de l\'enregistrement de la chaîne de possession.', 'assetsign'));
      }
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $document = new Document();
       $documentsId = (int) $document->add([
           'name'          => $name,
           'comment'       => 'SHA-256 : ' . $hash,
           'entities_id'   => (int) $this->fields['entities_id'],
           'is_recursive'  => 0,
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if ($documentsId <= 0 || !$document->getFromDB($documentsId)) {
          throw new \RuntimeException(__('Échec de l\'enregistrement de la chaîne de possession.', 'assetsign'));
      }
       $stored = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
      if (!is_file($stored) || hash_file('sha256', $stored) !== $hash) {
          $document->delete(['id' => $documentsId], true);
          throw new \RuntimeException(__('Échec de l\'enregistrement de la chaîne de possession.', 'assetsign'));
      }
       (new Document_Item())->add(['documents_id' => $documentsId, 'itemtype' => self::class, 'items_id' => $this->getID()]);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

       return ['documents_id' => $documentsId, 'sha256' => $hash];
   }

    /**
     * Personne d'une étape : un compte GLPI, ou un nom saisi (enquêteur externe, prestataire).
     *
     * @return array{0: int, 1: string} id du compte (0 si nom saisi), nom affiché
     * @throws \RuntimeException
     */
   public static function resolvePerson(int $usersId, string $name, string $missingMessage): array {
      if ($usersId > 0) {
          $user = new \User();
         if (!$user->getFromDB($usersId)) {
            throw new \RuntimeException($missingMessage);
         }
          return [$usersId, mb_substr(Pdf\PdfMetadata::personName($user->fields), 0, self::MAX_TEXT)];
      }
       $name = mb_substr(trim($name), 0, self::MAX_TEXT);
      if ($name === '') {
          throw new \RuntimeException($missingMessage);
      }
       return [0, $name];
   }

   private static function assertDistinct(int $fromId, string $fromName, int $toId, string $toName): void {
      if (($fromId > 0 && $fromId === $toId) || ($fromId === 0 && $toId === 0 && mb_strtolower($fromName) === mb_strtolower($toName))) {
          throw new \RuntimeException(__('La personne qui remet et celle qui reçoit doivent être différentes.', 'assetsign'));
      }
   }

   private function assertOpen(): void {
      if ((int) $this->fields['status'] !== self::STATUS_OPEN) {
          throw new \RuntimeException(__('Cette chaîne de possession est clôturée : elle ne change plus.', 'assetsign'));
      }
   }

    /** Date de l'étape (champ datetime-local), jamais dans le futur ; vide = maintenant. */
   public static function normalizeDate(string $value): string {
       $value = trim(str_replace('T', ' ', $value));
      if ($value === '') {
          return date('Y-m-d H:i:s');
      }
       $timestamp = strtotime($value);
      if ($timestamp === false) {
          throw new \RuntimeException(__('Date de l\'étape invalide.', 'assetsign'));
      }
      if ($timestamp > time() + 300) {
          throw new \RuntimeException(__('La date de l\'étape ne peut pas être dans le futur.', 'assetsign'));
      }
       return date('Y-m-d H:i:s', $timestamp);
   }

    // ------------------------------------------------------------------ Garde-fous

    /** Motif, état, scellé, matériel et liens sont signés à la saisie : jamais réécrits. */
   public function prepareInputForUpdate($input) {
       return array_intersect_key($input, array_flip(self::UPDATABLE_FIELDS));
   }

    /** Une chaîne de possession est une preuve : elle ne se supprime pas. */
   public function pre_deleteItem() {
       return false;
   }

   public static function canDelete(): bool {
       return false;
   }

   public static function canPurge(): bool {
       return false;
   }

    // ------------------------------------------------------------------ Lecture

    /** @return list<array{documents_id: int, sha256: string}> */
   public function getPhotos(): array {
       $photos = json_decode((string) ($this->fields['photos'] ?? ''), true);
       return is_array($photos) ? array_values(array_filter($photos, 'is_array')) : [];
   }

    /** Matériel concerné, avec marque et modèle résolus. */
   public function getTargetItem(): array {
       $itemtype = (string) $this->fields['itemtype'];
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          return [];
      }
       $item = new $itemtype();
      if (!$item->getFromDB((int) $this->fields['items_id'])) {
          return [];
      }
       $fields = $item->fields;
       $fields['manufacturer_name'] = Assetsign::resolveManufacturerName($item);
       $fields['model_name'] = Assetsign::resolveModelName($item);
       return $fields;
   }

   public function getDocumentTitle(int $version): string {
       $item = $this->getTargetItem();
       return sprintf(
           __('%1$s - Chaîne de possession n°%2$d - %3$s (version %4$d)', 'assetsign'),
           date('Y-m-d', strtotime((string) ($this->fields['date_seized'] ?: 'now'))),
           $this->getID(),
           (string) ($item['name'] ?? '') ?: (string) $this->fields['itemtype'],
           $version
       );
   }

    /** Nom de l'incident GRC lié (vide sans GRC ou sans droit de lecture). */
   public function getIncidentName(): string {
       $incident = self::readableIncident((int) ($this->fields[self::GRC_INCIDENT_FIELD] ?? 0));
       return $incident !== null ? (string) $incident->getName() : '';
   }

   public function getIncidentUrl(): string {
       $incident = self::readableIncident((int) ($this->fields[self::GRC_INCIDENT_FIELD] ?? 0));
       return $incident !== null ? (string) $incident->getLinkURL() : '';
   }

   public function getTicketName(): string {
       $ticket = self::readableTicket((int) ($this->fields['tickets_id'] ?? 0));
       return $ticket !== null ? (string) $ticket->getName() : '';
   }

    /**
     * Étapes prêtes pour l'affichage (fiche et PDF).
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
   public static function decorateEvents(array $events): array {
       $types = CustodyEvent::getTypeLabels();
       $closures = self::getClosureLabels();
      foreach ($events as &$event) {
          $event['type_label'] = $types[(int) $event['event_type']] ?? '';
          $event['type_icon'] = CustodyEvent::getTypeIcon((int) $event['event_type']);
          $event['closure_label'] = $closures[(string) $event['closure']] ?? '';
          $event['location_name'] = (int) $event['locations_id'] > 0 ? \Dropdown::getDropdownName('glpi_locations', (int) $event['locations_id']) : '';
          $event['recorded_by'] = (int) $event['users_id'] > 0 ? getUserName((int) $event['users_id']) : '';
      }
       unset($event);
       return $events;
   }

   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);
       $item = Assetsign::resolveReadableItem((string) ($this->fields['itemtype'] ?? ''), (int) ($this->fields['items_id'] ?? 0));
       $isOpen = (int) ($this->fields['status'] ?? 0) === self::STATUS_OPEN;

       TemplateRenderer::getInstance()->display('@assetsign/custodycase_form.html.twig', [
           'case'           => $this->fields,
           'item_name'      => $item !== null ? (string) $item->getName() : (string) ($this->fields['itemtype'] ?? '') . ' #' . (int) ($this->fields['items_id'] ?? 0),
           'item_url'       => $item !== null ? (string) $item->getLinkURL() : '',
           'item_type'      => Assetsign::getItemtypeLabel((string) ($this->fields['itemtype'] ?? '')),
           'status_label'   => self::getStatusLabels()[(int) ($this->fields['status'] ?? 0)] ?? '',
           'closure_label'  => self::getClosureLabels()[(string) ($this->fields['closure'] ?? '')] ?? '',
           'closure_labels' => self::getClosureLabels(),
           'location_name'  => (int) ($this->fields['locations_id'] ?? 0) > 0 ? \Dropdown::getDropdownName('glpi_locations', (int) $this->fields['locations_id']) : '',
           'ticket_name'    => $this->getTicketName(),
           'incident_name'  => $this->getIncidentName(),
           'incident_url'   => $this->getIncidentUrl(),
           'opened_by'      => (int) ($this->fields['users_id'] ?? 0) > 0 ? getUserName((int) $this->fields['users_id']) : '',
           'photos'         => $this->getPhotos(),
           'events'         => self::decorateEvents(CustodyEvent::getForCase($this->getID())),
           'can_update'     => $isOpen && (bool) $this->can($this->getID(), UPDATE),
           'default_return' => (int) (($this->getTargetItem())['users_id'] ?? 0),
           'now'            => date('Y-m-d\TH:i'),
           'csrf_token'     => Session::getNewCSRFToken(),
       ]);
       return true;
   }

    // ------------------------------------------------------------------ Installation

   public static function install(Migration $migration): void {
       global $DB;

       $table = self::getTable();
      if ($DB->tableExists($table)) {
          return;
      }
       $migration->displayMessage('Création de la table ' . $table);
       $DB->doQuery("CREATE TABLE `$table` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `entities_id` int unsigned NOT NULL DEFAULT 0,
              `itemtype` varchar(100) NOT NULL,
              `items_id` int unsigned NOT NULL DEFAULT 0,
              `reason` text,
              `condition_note` text,
              `seal_number` varchar(255) NOT NULL DEFAULT '',
              `photos` text,
              `tickets_id` int unsigned NOT NULL DEFAULT 0,
              `plugin_grcmanager_securityincidents_id` int unsigned NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 0,
              `closure` varchar(16) NOT NULL DEFAULT '',
              `date_seized` timestamp NULL DEFAULT NULL,
              `date_closed` timestamp NULL DEFAULT NULL,
              `users_id` int unsigned NOT NULL DEFAULT 0,
              `users_id_holder` int unsigned NOT NULL DEFAULT 0,
              `name_holder` varchar(255) NOT NULL DEFAULT '',
              `locations_id` int unsigned NOT NULL DEFAULT 0,
              `storage` varchar(255) NOT NULL DEFAULT '',
              `documents_id` int unsigned NOT NULL DEFAULT 0,
              `pdf_hash` varchar(64) NOT NULL DEFAULT '',
              `pdf_version` int unsigned NOT NULL DEFAULT 0,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `item` (`itemtype`, `items_id`),
              KEY `entities_id` (`entities_id`),
              KEY `tickets_id` (`tickets_id`),
              KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
              KEY `status` (`status`),
              KEY `locations_id` (`locations_id`),
              KEY `users_id_holder` (`users_id_holder`),
              KEY `date_seized` (`date_seized`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

       $rank = 1;
      foreach ([3, 4, 5, 6, 7, 8, 11] as $searchOptionId) {
          $DB->insert('glpi_displaypreferences', [
              'itemtype'  => self::class,
              'num'       => $searchOptionId,
              'rank'      => $rank++,
              'users_id'  => 0,
              'interface' => 'central',
          ]);
      }
   }
}
