<?php

/**
 * -------------------------------------------------------------------------
 * Assetsign plugin for GLPI
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

/**
 * Issue grcmanager #283 : quand le plugin GRC Manager (glpi-iso27001-management) est actif, sa
 * politique « Charte informatique » est la référence de version de la charte. Sa version
 * approuvée la plus récente figure en tête de la charte signée dans les campagnes : une nouvelle
 * version change l'empreinte de la charte, ce qui déclenche le mécanisme de re-signature des
 * anciens signataires (issue #224).
 *
 * Aucune dépendance dure : grcmanager est détecté à l'exécution (plugin actif, table et colonne
 * présentes) et sa table lue directement, en lecture seule, sans charger ses classes — comme
 * grcmanager lit les campagnes de charte d'assetsign.
 */
final class GrcmanagerCharter
{
   public const PLUGIN_KEY = 'grcmanager';
   public const TABLE = 'glpi_plugin_grcmanager_policies';

    /**
     * Version approuvée la plus récente de la charte dans grcmanager, ou null.
     *
     * @return array{title: string, version: string, approval_date: string}|null
     */
   public static function approvedVersion(): ?array {
       global $DB;

      if (!\Plugin::isPluginActive(self::PLUGIN_KEY)
           || !$DB->tableExists(self::TABLE)
           || !$DB->fieldExists(self::TABLE, 'is_it_charter')
       ) {
          return null;
      }
       $row = $DB->request([
           'SELECT' => ['title', 'version', 'approval_date'],
           'FROM'   => self::TABLE,
           'WHERE'  => ['is_it_charter' => 1, 'status' => 'approved', ['NOT' => ['approval_date' => null]]],
           'ORDER'  => ['approval_date DESC', 'id DESC'],
           'LIMIT'  => 1,
       ])->current();

       return $row === null ? null : [
           'title'         => (string) $row['title'],
           'version'       => (string) $row['version'],
           'approval_date' => (string) $row['approval_date'],
       ];
   }

    /**
     * Ligne de version placée en tête de la charte signée.
     *
     * @param array{title: string, version: string, approval_date: string} $version
     */
   public static function headerHtml(array $version): string {
       return '<p><strong>' . htmlescape(sprintf(
           __('%1$s, version %2$s approuvée le %3$s', 'assetsign'),
           $version['title'],
           $version['version'],
           \Html::convDate($version['approval_date'])
       )) . '</strong></p>';
   }
}
