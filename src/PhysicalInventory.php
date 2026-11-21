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
use Migration;
use Session;

/**
 * Issue #368 : campagne d'inventaire physique, salle par salle. Le technicien scanne les
 * etiquettes QR du plugin (camera du navigateur) ou saisit le numero de serie / d'inventaire ;
 * le rapport rapproche les scans du materiel GLPI du lieu : trouve, absent, a un autre
 * emplacement, en trop (code inconnu). Lecture seule sur les actifs : seules la campagne et ses
 * scans sont ecrits, aucun ecart n'est corrige automatiquement.
 */
class PhysicalInventory extends Compat\Base\PhysicalInventoryBase
{
   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   public const SCANS_TABLE = 'glpi_plugin_assetsign_physicalinventoryscans';

   public const STATUS_FOUND   = 'found';
   public const STATUS_MOVED   = 'moved';
   public const STATUS_MISSING = 'missing';
   public const STATUS_EXTRA   = 'extra';

    /** Longueur maximale d'un code scanne ou saisi (colonne `code`). */
   public const CODE_MAX_LENGTH = 255;

   public static function getTypeName($nb = 0): string {
       return _n('Inventaire physique', 'Inventaires physiques', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-scan';
   }

   public static function getMenuName(): string {
       return self::getTypeName(2);
   }

    /**
     * @return array{title: string, page: string, icon: string}|false
     */
   public static function getMenuContent() {
      if (!Session::haveRight(self::RIGHTNAME, READ)) {
          return false;
      }
       return [
           'title' => self::getMenuName(),
           'page'  => '/plugins/assetsign/front/physicalinventory.php',
           'icon'  => self::getIcon(),
       ];
   }

    /** @return array<string, string> libelle de chaque resultat du rapprochement */
   public static function getStatusLabels(): array {
       return [
           self::STATUS_FOUND   => __('Trouvé', 'assetsign'),
           self::STATUS_MOVED   => __('À un autre emplacement', 'assetsign'),
           self::STATUS_MISSING => __('Absent', 'assetsign'),
           self::STATUS_EXTRA   => __('En trop', 'assetsign'),
       ];
   }

    /** @return array<string, string> couleur Tabler de chaque resultat */
   public static function getStatusColors(): array {
       return [
           self::STATUS_FOUND   => 'success',
           self::STATUS_MOVED   => 'warning',
           self::STATUS_MISSING => 'danger',
           self::STATUS_EXTRA   => 'secondary',
       ];
   }

   public function isOpen(): bool {
       return empty($this->fields['is_closed']);
   }

    /**
     * Cree une campagne pour un lieu visible de l'utilisateur, dans l'entite active.
     *
     * @param array{name?: string, locations_id?: int, include_sublocations?: bool, comment?: string} $input
     */
   public static function launch(array $input, int $usersId): ?self {
       $name = trim((string) ($input['name'] ?? ''));
       $locationsId = (int) ($input['locations_id'] ?? 0);
      if ($name === '' || !isset(self::getVisibleLocations()[$locationsId])) {
          return null;
      }
       $campaign = new self();
       $id = $campaign->add([
           'name'                 => mb_substr($name, 0, 255),
           'entities_id'          => Session::getActiveEntity(),
           'is_recursive'         => Session::getIsActiveEntityRecursive() ? 1 : 0,
           'locations_id'         => $locationsId,
           'include_sublocations' => !empty($input['include_sublocations']) ? 1 : 0,
           'users_id'             => $usersId,
           'comment'              => (string) ($input['comment'] ?? ''),
           'is_closed'            => 0,
       ]);
       return $id ? $campaign : null;
   }

   public function prepareInputForAdd($input) {
       $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
       return $input;
   }

   public function close(): bool {
       return $this->update([
           'id'          => $this->getID(),
           'is_closed'   => 1,
           'date_closed' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
       ]);
   }

    /** @return array<int, string> lieux des entites actives de la session (id => nom complet) */
   public static function getVisibleLocations(): array {
       global $DB;

       $locations = [];
      foreach ($DB->request([
              'FROM'  => 'glpi_locations',
              'WHERE' => getEntitiesRestrictCriteria('glpi_locations', '', '', true),
              'ORDER' => 'completename',
          ]) as $row
      ) {
          $locations[(int) $row['id']] = (string) $row['completename'];
      }
       return $locations;
   }

    /** @return list<int> lieux couverts par la campagne (le lieu, et ses sous-lieux si demande) */
   public function getLocationIds(): array {
       $id = (int) $this->fields['locations_id'];
      if (empty($this->fields['include_sublocations'])) {
          return [$id];
      }
       return array_values(array_map('intval', getSonsOf('glpi_locations', $id)));
   }

    /**
     * Entites dont le materiel est attendu : celle de la campagne (et ses sous-entites si la
     * campagne est recursive), reduites aux entites donnees (celles de la session).
     *
     * @param list<int> $activeEntities
     * @return list<int>
     */
   public function getScopeEntities(array $activeEntities): array {
       $entity = (int) $this->fields['entities_id'];
       $scope = !empty($this->fields['is_recursive']) ? array_map('intval', getSonsOf('glpi_entities', $entity)) : [$entity];
       return array_values(array_intersect($scope, array_map('intval', $activeEntities)));
   }

    /** @return list<class-string<CommonDBTM>> types de materiel geres et visibles de l'utilisateur */
   public function getItemtypes(): array {
       $itemtypes = [];
      foreach (Config::getForEntity((int) $this->fields['entities_id'])->getManagedItemtypes() as $itemtype) {
         if (is_string($itemtype) && is_a($itemtype, CommonDBTM::class, true) && (bool) $itemtype::canView()) {
             $itemtypes[] = $itemtype;
         }
      }
       return $itemtypes;
   }

    /**
     * Criteres communs de recherche du materiel : non supprime, pas un gabarit, dans les entites.
     *
     * @param class-string<CommonDBTM> $itemtype
     * @param list<int> $entities
     * @return array<mixed>
     */
   private static function itemCriteria(string $itemtype, array $entities): array {
       global $DB;

       $table = $itemtype::getTable();
       $where = ['entities_id' => $entities === [] ? [-1] : $entities];
      foreach (['is_deleted', 'is_template'] as $field) {
         if ($DB->fieldExists($table, $field)) {
             $where[$field] = 0;
         }
      }
       return $where + $itemtype::getSystemSQLCriteria();
   }

    /**
     * Retrouve le materiel designe par un code : URL d'une etiquette QR du plugin (formulaire du
     * materiel avec son id), sinon numero de serie ou d'inventaire, sinon nom exact.
     *
     * @param list<class-string<CommonDBTM>> $itemtypes
     * @param list<int> $entities
     * @return list<array{itemtype: class-string<CommonDBTM>, items_id: int}>
     */
   public static function resolveCode(string $code, array $itemtypes, array $entities): array {
       global $DB;

       $code = trim($code);
      if ($code === '') {
          return [];
      }

       $fromUrl = self::resolveUrl($code, $itemtypes);
      if ($fromUrl !== null) {
          [$itemtype, $id] = $fromUrl;
          $found = $DB->request([
              'SELECT' => ['id'],
              'FROM'   => $itemtype::getTable(),
              'WHERE'  => ['id' => $id] + self::itemCriteria($itemtype, $entities),
          ])->count() > 0;
          return $found ? [['itemtype' => $itemtype, 'items_id' => $id]] : [];
      }

      foreach ([['serial', 'otherserial'], ['name']] as $fields) {
          $matches = [];
         foreach ($itemtypes as $itemtype) {
             $table = $itemtype::getTable();
             $or = [];
            foreach ($fields as $field) {
               if ($DB->fieldExists($table, $field)) {
                   $or[] = [$field => $code];
               }
            }
            if ($or === []) {
                continue;
            }
            foreach ($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $table,
                    'WHERE'  => [['OR' => $or]] + self::itemCriteria($itemtype, $entities),
                    'LIMIT'  => 5,
                ]) as $row
            ) {
                $matches[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['id']];
            }
         }
         if ($matches !== []) {
             return $matches;
         }
      }
       return [];
   }

    /**
     * @param list<class-string<CommonDBTM>> $itemtypes
     * @return array{0: class-string<CommonDBTM>, 1: int}|null
     */
   private static function resolveUrl(string $code, array $itemtypes): ?array {
      if (!preg_match('#^https?://#i', $code)) {
          return null;
      }
       $path = (string) parse_url($code, PHP_URL_PATH);
       parse_str((string) parse_url($code, PHP_URL_QUERY), $query);
       $id = (int) ($query['id'] ?? 0);
      if ($id <= 0) {
          return null;
      }
      foreach ($itemtypes as $itemtype) {
          $form = (string) $itemtype::getFormURL(false);
          $formPath = (string) parse_url($form, PHP_URL_PATH);
          parse_str((string) parse_url($form, PHP_URL_QUERY), $formQuery);
         if ($formPath === '' || !str_ends_with($path, $formPath)) {
             continue;
         }
         foreach ($formQuery as $key => $value) {
            if (($query[$key] ?? null) !== $value) {
                continue 2;
            }
         }
          return [$itemtype, $id];
      }
       return null;
   }

    /**
     * Enregistre un scan (camera ou saisie). Rien n'est ecrit sur le materiel.
     *
     * @param list<int> $activeEntities
     * @return array{result: string, status: string, message: string}
     *   result : recorded, duplicate, ambiguous, empty ou closed
     */
   public function recordScan(string $code, int $usersId, array $activeEntities): array {
       global $DB;

       $code = mb_substr(trim($code), 0, self::CODE_MAX_LENGTH);
      if ($code === '') {
          return ['result' => 'empty', 'status' => '', 'message' => __('Saisissez ou scannez un code.', 'assetsign')];
      }
      if (!$this->isOpen()) {
          return ['result' => 'closed', 'status' => '', 'message' => __('Cette campagne est clôturée : elle ne reçoit plus de scan.', 'assetsign')];
      }

       $matches = self::resolveCode($code, $this->getItemtypes(), $this->getScopeEntities($activeEntities));
      if (count($matches) > 1) {
          return ['result' => 'ambiguous', 'status' => '', 'message' => sprintf(__('Plusieurs matériels correspondent à « %s » : scannez l\'étiquette QR ou saisissez le numéro de série.', 'assetsign'), $code)];
      }

       $itemtype = $matches[0]['itemtype'] ?? '';
       $itemsId = $matches[0]['items_id'] ?? 0;
       $existing = ['physicalinventories_id' => $this->getID()]
           + ($itemsId > 0 ? ['itemtype' => $itemtype, 'items_id' => $itemsId] : ['items_id' => 0, 'code' => $code]);
       $name = $itemsId > 0 ? self::itemName($itemtype, $itemsId) : $code;
      if ($DB->request(['SELECT' => ['id'], 'FROM' => self::SCANS_TABLE, 'WHERE' => $existing])->count() > 0) {
          return ['result' => 'duplicate', 'status' => '', 'message' => sprintf(__('« %s » est déjà scanné dans cette campagne.', 'assetsign'), $name)];
      }

       $DB->insert(self::SCANS_TABLE, [
           'physicalinventories_id' => $this->getID(),
           'code'                   => $code,
           'itemtype'               => $itemtype,
           'items_id'               => $itemsId,
           'users_id'               => $usersId,
           'date_scan'              => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
       ]);

      if ($itemsId === 0) {
          $status = self::STATUS_EXTRA;
          $message = sprintf(__('« %s » : aucun matériel GLPI ne correspond (en trop).', 'assetsign'), $code);
      } else {
          $status = in_array(self::itemLocation($itemtype, $itemsId), $this->getLocationIds(), true) ? self::STATUS_FOUND : self::STATUS_MOVED;
          $message = sprintf(
              $status === self::STATUS_FOUND ? __('« %s » : trouvé.', 'assetsign') : __('« %s » : trouvé, mais enregistré dans GLPI à un autre emplacement.', 'assetsign'),
              $name
          );
      }
       return ['result' => 'recorded', 'status' => $status, 'message' => $message];
   }

    /** @param class-string<CommonDBTM>|string $itemtype */
   private static function itemName(string $itemtype, int $id): string {
      if (!is_a($itemtype, CommonDBTM::class, true)) {
          return '';
      }
       $item = new $itemtype();
       return $item->getFromDB($id) ? (string) $item->getName() : '';
   }

    /** @param class-string<CommonDBTM> $itemtype */
   private static function itemLocation(string $itemtype, int $id): int {
       $item = new $itemtype();
       return $item->getFromDB($id) ? (int) ($item->fields['locations_id'] ?? 0) : 0;
   }

    /** @return int nombre de scans de la campagne */
   public function countScans(): int {
       global $DB;

       return $DB->request(['SELECT' => ['id'], 'FROM' => self::SCANS_TABLE, 'WHERE' => ['physicalinventories_id' => $this->getID()]])->count();
   }

    /**
     * Rapprochement des scans avec le materiel GLPI des entites donnees.
     *
     * @param list<int> $activeEntities
     * @return array{counts: array<string, int>, rows: list<array<string, mixed>>}
     */
   public function getReport(array $activeEntities): array {
       global $DB;

       $entities = $this->getScopeEntities($activeEntities);
       $locations = $this->getLocationIds();
       $itemtypes = $this->getItemtypes();
       $labels = self::getStatusLabels();
       $colors = self::getStatusColors();
       $rows = [];
       $scanned = [];

      foreach ($DB->request(['FROM' => self::SCANS_TABLE, 'WHERE' => ['physicalinventories_id' => $this->getID()], 'ORDER' => 'id']) as $scan) {
          $itemtype = (string) $scan['itemtype'];
          $itemsId = (int) $scan['items_id'];
          $item = null;
         if ($itemsId > 0 && in_array($itemtype, $itemtypes, true)) {
             $candidate = new $itemtype();
            if ($candidate->getFromDBByCrit(['id' => $itemsId] + self::itemCriteria($itemtype, $entities))) {
                $item = $candidate;
            }
         }
         if ($itemsId > 0 && $item === null) {
             // Materiel supprime depuis, ou hors des entites de l'utilisateur : rien n'en est montre.
             continue;
         }
          $status = self::STATUS_EXTRA;
         if ($item !== null) {
             $scanned[$itemtype . ':' . $itemsId] = true;
             $status = in_array((int) ($item->fields['locations_id'] ?? 0), $locations, true) ? self::STATUS_FOUND : self::STATUS_MOVED;
         }
          $rows[] = self::row($status, $item, $labels, $colors) + [
              'code'      => (string) $scan['code'],
              'date_scan' => (string) $scan['date_scan'],
              'scanned_by' => (int) $scan['users_id'] > 0 ? getUserName((int) $scan['users_id']) : '',
          ];
      }

      foreach ($itemtypes as $itemtype) {
         if (!$DB->fieldExists($itemtype::getTable(), 'locations_id')) {
             continue;
         }
         foreach ($DB->request([
                 'FROM'  => $itemtype::getTable(),
                 'WHERE' => ['locations_id' => $locations] + self::itemCriteria($itemtype, $entities),
                 'ORDER' => 'name',
             ]) as $data
         ) {
            if (isset($scanned[$itemtype . ':' . $data['id']])) {
                continue;
            }
             $item = new $itemtype();
             $item->fields = $data;
             $rows[] = self::row(self::STATUS_MISSING, $item, $labels, $colors) + ['code' => '', 'date_scan' => '', 'scanned_by' => ''];
         }
      }

       $counts = array_fill_keys(array_keys($labels), 0);
      foreach ($rows as $row) {
          $counts[$row['status']]++;
      }
       $order = array_flip([self::STATUS_MISSING, self::STATUS_MOVED, self::STATUS_EXTRA, self::STATUS_FOUND]);
       usort($rows, static fn (array $a, array $b): int => $order[$a['status']] <=> $order[$b['status']]);

       return ['counts' => $counts, 'rows' => $rows];
   }

    /**
     * @param array<string, string> $labels
     * @param array<string, string> $colors
     * @return array<string, mixed>
     */
   private static function row(string $status, ?CommonDBTM $item, array $labels, array $colors): array {
       $locationsId = $item !== null ? (int) ($item->fields['locations_id'] ?? 0) : 0;
       return [
           'status'      => $status,
           'label'       => $labels[$status],
           'color'       => $colors[$status],
           'type'        => $item !== null ? $item::getTypeName(1) : '',
           'name'        => $item !== null ? (string) $item->getName() : '',
           'url'         => $item !== null ? (string) $item::getFormURLWithID($item->getID()) : '',
           'serial'      => $item !== null ? (string) ($item->fields['serial'] ?? '') : '',
           'otherserial' => $item !== null ? (string) ($item->fields['otherserial'] ?? '') : '',
           'location'    => $locationsId > 0 ? (string) \Dropdown::getDropdownName('glpi_locations', $locationsId) : '',
       ];
   }

    /** @param list<array<string, mixed>> $rows lignes de getReport() */
   public static function toCsv(array $rows): string {
       $out = fopen('php://temp', 'r+');
       fwrite($out, "\xEF\xBB\xBF");
       fputcsv($out, [
           __('Résultat', 'assetsign'), __('Type', 'assetsign'), __('Matériel', 'assetsign'), __('Numéro de série', 'assetsign'),
           __('Numéro d\'inventaire', 'assetsign'), __('Emplacement dans GLPI', 'assetsign'), __('Code scanné', 'assetsign'),
           __('Date du scan', 'assetsign'), __('Scanné par', 'assetsign'),
       ], ';', '"', '\\');
      foreach ($rows as $row) {
          fputcsv($out, array_map(
              static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v,
              [$row['label'], $row['type'], $row['name'], $row['serial'], $row['otherserial'], $row['location'], $row['code'], $row['date_scan'], $row['scanned_by']]
          ), ';', '"', '\\');
      }
       rewind($out);
       $csv = (string) stream_get_contents($out);
       fclose($out);
       return $csv;
   }

   public function cleanDBonPurge() {
       global $DB;

       $DB->delete(self::SCANS_TABLE, ['physicalinventories_id' => $this->getID()]);
   }

   public static function install(Migration $migration): void {
       global $DB;

       $table = self::getTable();
      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery('CREATE TABLE `' . $table . '` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT \'\',
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `locations_id` int unsigned NOT NULL DEFAULT 0,
                `include_sublocations` tinyint NOT NULL DEFAULT 1,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `comment` text,
                `is_closed` tinyint NOT NULL DEFAULT 0,
                `date_closed` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `locations_id` (`locations_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
      }
      if (!$DB->tableExists(self::SCANS_TABLE)) {
          $migration->displayMessage('Création de la table ' . self::SCANS_TABLE);
          $DB->doQuery('CREATE TABLE `' . self::SCANS_TABLE . '` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `physicalinventories_id` int unsigned NOT NULL DEFAULT 0,
                `code` varchar(255) NOT NULL DEFAULT \'\',
                `itemtype` varchar(255) NOT NULL DEFAULT \'\',
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `date_scan` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `physicalinventories_id` (`physicalinventories_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
      }
   }
}
