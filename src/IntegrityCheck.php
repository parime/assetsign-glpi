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

use CommonGLPI;
use CronTask;
use Document;
use Migration;
use Session;

/**
 * Issue #371 : controle d'integrite periodique des preuves signees. Pour chaque fiche signee (ou
 * en attente de contre-signature), verifie que le PDF signe (ou le scan papier) existe toujours,
 * que son empreinte SHA-256 correspond a celle de la preuve de signature, et qu'il reste rattache
 * au materiel. Rien n'est corrige automatiquement : le rapport liste les anomalies.
 *
 * Chaque execution (tache planifiee hebdomadaire ou bouton de la page) est conservee
 * (RUNS_TABLE), avec le nombre de fiches controlees par entite et les anomalies : la page n'en
 * montre que la part des entites actives de la session.
 */
class IntegrityCheck extends CommonGLPI
{
   public const RUNS_TABLE = 'glpi_plugin_assetsign_integrityruns';

    /** Executions conservees (environ un an de controles hebdomadaires). */
   public const KEPT_RUNS = 60;

   public const ANOMALY_NO_PROOF        = 'no_proof';
   public const ANOMALY_NO_DOCUMENT     = 'no_document';
   public const ANOMALY_DOCUMENT_GONE   = 'document_gone';
   public const ANOMALY_FILE_MISSING    = 'file_missing';
   public const ANOMALY_HASH_MISMATCH   = 'hash_mismatch';
   public const ANOMALY_NOT_ATTACHED    = 'not_attached';

   public static function getTypeName($nb = 0): string {
       return __('Contrôle d\'intégrité des preuves', 'assetsign');
   }

   public static function getMenuName(): string {
       return self::getTypeName();
   }

   public static function getIcon(): string {
       return 'ti ti-shield-check';
   }

    /**
     * @return array{title: string, page: string, icon: string}|false
     */
   public static function getMenuContent() {
      if (!Session::haveRight(Profile::RIGHT_ASSETSIGN, READ)) {
          return false;
      }
       return [
           'title' => self::getMenuName(),
           'page'  => '/plugins/assetsign/front/integrity.php',
           'icon'  => self::getIcon(),
       ];
   }

    /** @return array<string, string> libelle de chaque anomalie */
   public static function getAnomalyLabels(): array {
       return [
           self::ANOMALY_NO_PROOF      => __('Preuve de signature absente', 'assetsign'),
           self::ANOMALY_NO_DOCUMENT   => __('Aucun PDF signé enregistré', 'assetsign'),
           self::ANOMALY_DOCUMENT_GONE => __('Document supprimé de GLPI', 'assetsign'),
           self::ANOMALY_FILE_MISSING  => __('Fichier absent du disque', 'assetsign'),
           self::ANOMALY_HASH_MISMATCH => __('Empreinte SHA-256 modifiée', 'assetsign'),
           self::ANOMALY_NOT_ATTACHED  => __('Document détaché du matériel', 'assetsign'),
       ];
   }

    /**
     * Controle les fiches signees des entites donnees (toutes si null).
     *
     * @param list<int>|null $entityIds
     * @return array{checked: array<int, int>, anomalies: list<array{assetsigns_id: int, entities_id: int, documents_id: int, code: string}>}
     */
   public static function check(?array $entityIds = null): array {
       global $DB;

       $where = [
           'is_deleted' => 0,
           'status'     => [...Assetsign::STATUSES_SIGNED, Assetsign::STATUS_AWAITING_COSIGNATURE],
       ];
       if ($entityIds !== null) {
          $where['entities_id'] = $entityIds === [] ? [-1] : $entityIds;
       }

       $checked = [];
       $anomalies = [];
       foreach ($DB->request(['FROM' => Assetsign::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
          $entity = (int) $row['entities_id'];
          $checked[$entity] = ($checked[$entity] ?? 0) + 1;
          foreach (self::checkSheet($row) as [$documentsId, $code]) {
             $anomalies[] = [
                 'assetsigns_id' => (int) $row['id'],
                 'entities_id'   => $entity,
                 'documents_id'  => $documentsId,
                 'code'          => $code,
             ];
          }
       }

       return ['checked' => $checked, 'anomalies' => $anomalies];
   }

    /**
     * @param array<string, mixed> $row ligne de glpi_plugin_assetsign_assetsigns
     * @return list<array{0: int, 1: string}> anomalies : [documents_id, code]
     */
   private static function checkSheet(array $row): array {
       global $DB;

       $hashes = [];
      foreach (Signature::getAllForAssetsign((int) $row['id']) as $proof) {
         if ((string) ($proof['document_hash'] ?? '') !== '') {
             $hashes[] = strtolower((string) $proof['document_hash']);
         }
      }

       $documents = (int) $row['status'] === Assetsign::STATUS_SIGNED_ON_PAPER
           ? [(int) $row['document_id_paper_scan']]
           : [(int) $row['document_id_signed'], (int) $row['document_id_cosigned']];
       $documents = array_values(array_filter($documents, static fn (int $id): bool => $id > 0));

       $anomalies = [];
      if ($hashes === []) {
          $anomalies[] = [0, self::ANOMALY_NO_PROOF];
      }
      if ($documents === []) {
          $anomalies[] = [0, self::ANOMALY_NO_DOCUMENT];
          return $anomalies;
      }

      foreach ($documents as $documentsId) {
          $document = new Document();
         if (!$document->getFromDB($documentsId) || (int) ($document->fields['is_deleted'] ?? 0) === 1) {
             $anomalies[] = [$documentsId, self::ANOMALY_DOCUMENT_GONE];
             continue;
         }
          $path = GLPI_DOC_DIR . '/' . (string) $document->fields['filepath'];
          $hash = (string) $document->fields['filepath'] !== '' && is_file($path) ? hash_file('sha256', $path) : false;
         if ($hash === false) {
             $anomalies[] = [$documentsId, self::ANOMALY_FILE_MISSING];
         } else if ($hashes !== [] && !in_array($hash, $hashes, true)) {
             $anomalies[] = [$documentsId, self::ANOMALY_HASH_MISMATCH];
         }
          $attached = countElementsInTable('glpi_documents_items', [
              'documents_id' => $documentsId,
              'itemtype'     => (string) $row['itemtype'],
              'items_id'     => (int) $row['items_id'],
          ]);
         if ($attached === 0) {
             $anomalies[] = [$documentsId, self::ANOMALY_NOT_ATTACHED];
         }
      }

       return $anomalies;
   }

    /**
     * Controle et enregistre l'execution.
     *
     * @param list<int>|null $entityIds null = toutes les entites (tache planifiee)
     * @return array{id: int, checked: int, anomalies: int}
     */
   public static function run(?array $entityIds = null, int $usersId = 0): array {
       global $DB;

       $result = self::check($entityIds);
       $DB->insert(self::RUNS_TABLE, [
           'date_run' => date('Y-m-d H:i:s'),
           'users_id' => $usersId,
           'details'  => json_encode($result),
       ]);
       $id = (int) $DB->insertId();

       $old = iterator_to_array($DB->request([
           'SELECT' => ['id'],
           'FROM'   => self::RUNS_TABLE,
           'ORDER'  => 'id DESC',
           'START'  => self::KEPT_RUNS,
           'LIMIT'  => 1000,
       ]));
      if ($old !== []) {
          $DB->delete(self::RUNS_TABLE, ['id' => array_column($old, 'id')]);
      }

       return ['id' => $id, 'checked' => array_sum($result['checked']), 'anomalies' => count($result['anomalies'])];
   }

    /**
     * Executions enregistrees, la plus recente d'abord, reduites aux entites donnees.
     *
     * @param list<int> $entityIds
     * @return list<array{id: int, date_run: string, users_id: int, checked: int, anomalies: list<array{assetsigns_id: int, entities_id: int, documents_id: int, code: string}>}>
     */
   public static function getRuns(array $entityIds, int $limit = 10): array {
       global $DB;

       $runs = [];
      foreach ($DB->request(['FROM' => self::RUNS_TABLE, 'ORDER' => 'id DESC', 'LIMIT' => $limit]) as $row) {
          $details = json_decode((string) $row['details'], true);
          $details = is_array($details) ? $details : [];
          $checked = 0;
         foreach ((array) ($details['checked'] ?? []) as $entity => $count) {
            if (in_array((int) $entity, $entityIds, true)) {
                $checked += (int) $count;
            }
         }
          $anomalies = array_values(array_filter(
              (array) ($details['anomalies'] ?? []),
              static fn ($a): bool => is_array($a) && in_array((int) ($a['entities_id'] ?? -1), $entityIds, true)
          ));
          $runs[] = [
              'id'        => (int) $row['id'],
              'date_run'  => (string) $row['date_run'],
              'users_id'  => (int) $row['users_id'],
              'checked'   => $checked,
              'anomalies' => $anomalies,
          ];
      }
       return $runs;
   }

    /**
     * Lignes affichables (ou exportables) des anomalies d'une execution.
     *
     * @param list<array{assetsigns_id: int, entities_id: int, documents_id: int, code: string}> $anomalies
     * @return list<array{assetsigns_id: int, reference: string, item: string, url: string, document: string, document_url: string, label: string, code: string}>
     */
   public static function describe(array $anomalies): array {
       $labels = self::getAnomalyLabels();
       $rows = [];
      foreach ($anomalies as $anomaly) {
          $sheet = new Assetsign();
          $found = $sheet->getFromDB((int) $anomaly['assetsigns_id']);
          $item = '';
         if ($found && is_a((string) $sheet->fields['itemtype'], \CommonDBTM::class, true)) {
             $asset = new $sheet->fields['itemtype']();
            if ($asset->getFromDB((int) $sheet->fields['items_id'])) {
                $item = (string) $asset->getName();
            }
         }
          $documentsId = (int) $anomaly['documents_id'];
          $document = new Document();
          $documentFound = $documentsId > 0 && $document->getFromDB($documentsId);
          $rows[] = [
              'assetsigns_id' => (int) $anomaly['assetsigns_id'],
              'reference'     => $found ? (string) ($sheet->fields['reference'] ?? '') : '',
              'item'          => $item,
              'url'           => $found ? (string) $sheet->getLinkURL() : '',
              'document'      => $documentFound ? (string) $document->fields['name'] : ($documentsId > 0 ? '#' . $documentsId : ''),
              'document_url'  => $documentFound ? (string) $document->getLinkURL() : '',
              'label'         => $labels[$anomaly['code']] ?? (string) $anomaly['code'],
              'code'          => (string) $anomaly['code'],
          ];
      }
       return $rows;
   }

    /** @param list<array<string, mixed>> $rows lignes de describe() */
   public static function toCsv(array $rows): string {
       $out = fopen('php://temp', 'r+');
       fwrite($out, "\xEF\xBB\xBF");
       fputcsv($out, [__('Fiche', 'assetsign'), __('Référence', 'assetsign'), __('Matériel', 'assetsign'), __('Document', 'assetsign'), __('Anomalie', 'assetsign')], ';', '"', '\\');
      foreach ($rows as $row) {
          fputcsv($out, array_map(
              static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v,
              [$row['assetsigns_id'], $row['reference'], $row['item'], $row['document'], $row['label']]
          ), ';', '"', '\\');
      }
       rewind($out);
       $csv = (string) stream_get_contents($out);
       fclose($out);
       return $csv;
   }

    /** @return array{description: string}|array{} */
   public static function cronInfo(string $name): array {
       return $name === 'assetsignIntegrity'
           ? ['description' => __('Contrôle l\'intégrité des preuves signées (PDF présent, empreinte inchangée, document rattaché)', 'assetsign')]
           : [];
   }

   public static function cronAssetsignIntegrity(CronTask $task): int {
       $result = self::run();
       $task->addVolume($result['checked']);
      if ($result['anomalies'] > 0) {
          $task->log(sprintf(__('%d anomalie(s) sur %d fiche(s) signée(s) contrôlée(s)', 'assetsign'), $result['anomalies'], $result['checked']));
      }
       return 1;
   }

   public static function install(Migration $migration): void {
       global $DB;

      if (!$DB->tableExists(self::RUNS_TABLE)) {
          $migration->displayMessage('Création de la table ' . self::RUNS_TABLE);
          $DB->doQuery('CREATE TABLE `' . self::RUNS_TABLE . '` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `date_run` timestamp NULL DEFAULT NULL,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `details` longtext,
                PRIMARY KEY (`id`),
                KEY `date_run` (`date_run`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
      }
   }
}
