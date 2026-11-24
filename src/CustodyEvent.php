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

use Migration;

/**
 * Issue #326 : une ligne = une étape signée de la chaîne de possession (saisie, transfert, clôture).
 *
 * Écrite une seule fois, jamais modifiée ni supprimée par l'interface : aucune classe CommonDBTM,
 * donc ni formulaire ni action groupée ne peut la changer. Elle porte la preuve des deux signatures
 * (images enregistrées comme Documents GLPI, avec leur empreinte) et l'empreinte de la version du
 * PDF produite après elle : chaque version cite l'empreinte de la précédente.
 */
final class CustodyEvent
{
   public const TABLE = 'glpi_plugin_assetsign_custodyevents';

   public const TYPE_SEIZURE  = 1;
   public const TYPE_TRANSFER = 2;
   public const TYPE_CLOSURE  = 3;

    /** @return array<int, string> */
   public static function getTypeLabels(): array {
       return [
           self::TYPE_SEIZURE  => __('Saisie', 'assetsign'),
           self::TYPE_TRANSFER => __('Transfert', 'assetsign'),
           self::TYPE_CLOSURE  => __('Clôture', 'assetsign'),
       ];
   }

   public static function getTypeIcon(int $type): string {
       return match ($type) {
           self::TYPE_SEIZURE  => 'ti ti-lock',
           self::TYPE_CLOSURE  => 'ti ti-lock-open',
           default             => 'ti ti-arrows-exchange',
       };
   }

    /**
     * @param array<string, mixed> $row
     */
   public static function insert(array $row): int {
       global $DB;

       $DB->insert(self::TABLE, $row + ['date_creation' => date('Y-m-d H:i:s')]);
       return (int) $DB->insertId();
   }

    /**
     * Empreinte et Document de la version du PDF produite après cette étape.
     */
   public static function setPdfVersion(int $eventId, int $version, int $documentsId, string $hash): void {
       global $DB;

       // Seule écriture après l'insertion : la version du PDF n'existe qu'une fois l'étape
       // enregistrée (elle y figure). Les champs signés ne sont jamais réécrits.
       $DB->update(self::TABLE, [
           'pdf_version'  => $version,
           'documents_id' => $documentsId,
           'pdf_hash'     => $hash,
       ], ['id' => $eventId, 'documents_id' => 0]);
   }

    /**
     * Étapes d'une chaîne, dans l'ordre où elles ont eu lieu.
     *
     * @return list<array<string, mixed>>
     */
   public static function getForCase(int $caseId): array {
       global $DB;

       return array_values(iterator_to_array($DB->request([
           'FROM'  => self::TABLE,
           'WHERE' => ['plugin_assetsign_custodycases_id' => $caseId],
           'ORDER' => ['id ASC'],
       ])));
   }

   public static function install(Migration $migration): void {
       global $DB;

       $table = self::TABLE;
      if ($DB->tableExists($table)) {
          return;
      }
       $migration->displayMessage('Création de la table ' . $table);
       $DB->doQuery("CREATE TABLE `$table` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `plugin_assetsign_custodycases_id` int unsigned NOT NULL DEFAULT 0,
              `event_type` tinyint NOT NULL DEFAULT 0,
              `event_date` timestamp NULL DEFAULT NULL,
              `users_id_from` int unsigned NOT NULL DEFAULT 0,
              `name_from` varchar(255) NOT NULL DEFAULT '',
              `users_id_to` int unsigned NOT NULL DEFAULT 0,
              `name_to` varchar(255) NOT NULL DEFAULT '',
              `locations_id` int unsigned NOT NULL DEFAULT 0,
              `storage` varchar(255) NOT NULL DEFAULT '',
              `closure` varchar(16) NOT NULL DEFAULT '',
              `comment` text,
              `sig_from_documents_id` int unsigned NOT NULL DEFAULT 0,
              `sig_from_hash` varchar(64) NOT NULL DEFAULT '',
              `sig_to_documents_id` int unsigned NOT NULL DEFAULT 0,
              `sig_to_hash` varchar(64) NOT NULL DEFAULT '',
              `users_id` int unsigned NOT NULL DEFAULT 0,
              `signed_at` timestamp NULL DEFAULT NULL,
              `ip_address` varchar(46) NOT NULL DEFAULT '',
              `user_agent` varchar(512) NOT NULL DEFAULT '',
              `pdf_version` int unsigned NOT NULL DEFAULT 0,
              `documents_id` int unsigned NOT NULL DEFAULT 0,
              `pdf_hash` varchar(64) NOT NULL DEFAULT '',
              `date_creation` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `plugin_assetsign_custodycases_id` (`plugin_assetsign_custodycases_id`),
              KEY `users_id_from` (`users_id_from`),
              KEY `users_id_to` (`users_id_to`),
              KEY `locations_id` (`locations_id`),
              KEY `documents_id` (`documents_id`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
   }
}
