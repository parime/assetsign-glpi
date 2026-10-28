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
 * Issue #228 : lecture des impacts calculés par le plugin Carbon (pluginsGLPI/carbon) pour un
 * matériel : empreinte de fabrication (embodiedimpacts.gwp), impact d'usage (usageimpacts.gwp),
 * énergie et émissions journalières (carbonemissions).
 *
 * Aucune dépendance dure, même principe que GrcmanagerCharter : Carbon est détecté à
 * l'exécution (plugin actif, tables et colonnes présentes) et ses tables lues directement, en
 * lecture seule, sans charger ses classes. Unités de Carbon : grammes de CO2 eq, kWh.
 */
final class CarbonPlugin
{
   public const PLUGIN_KEY = 'carbon';
   public const EMBODIED_TABLE = 'glpi_plugin_carbon_embodiedimpacts';
   public const USAGE_TABLE = 'glpi_plugin_carbon_usageimpacts';
   public const EMISSIONS_TABLE = 'glpi_plugin_carbon_carbonemissions';
   private const PERIOD_DAYS = 365;

   public static function isAvailable(): bool {
       global $DB;

       return \Plugin::isPluginActive(self::PLUGIN_KEY)
           && $DB->tableExists(self::EMBODIED_TABLE)
           && $DB->fieldExists(self::EMBODIED_TABLE, 'gwp');
   }

   /**
    * Empreintes de fabrication de plusieurs matériels d'un même type, en kg CO2 eq.
    *
    * @param list<int> $ids
    * @return array<int, float>
    */
   public static function embodiedFootprints(string $itemtype, array $ids): array {
       global $DB;

      if ($ids === [] || !self::isAvailable()) {
          return [];
      }
       $footprints = [];
      foreach ($DB->request([
              'SELECT' => ['items_id', 'gwp'],
              'FROM'   => self::EMBODIED_TABLE,
              'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids, 'gwp' => ['>', 0]],
          ]) as $row
      ) {
          $footprints[(int) $row['items_id']] = (float) CarbonLogic::gramsToKg((float) $row['gwp']);
      }
       return $footprints;
   }

   /**
    * Impacts d'un matériel, ou null si Carbon n'est pas disponible ou n'a rien calculé pour lui.
    *
    * @return array{embodied_kg: float|null, usage_kg: float|null, energy_kwh: float|null,
    *     emissions_kg: float|null, days: int, kwh_per_day: float|null}|null
    */
   public static function forItem(string $itemtype, int $itemsId): ?array {
       global $DB;

      if (!self::isAvailable()) {
          return null;
      }
       $embodied = self::embodiedFootprints($itemtype, [$itemsId])[$itemsId] ?? null;

       $usage = null;
      if ($DB->tableExists(self::USAGE_TABLE) && $DB->fieldExists(self::USAGE_TABLE, 'gwp')) {
          $row = $DB->request([
              'SELECT' => ['gwp'],
              'FROM'   => self::USAGE_TABLE,
              'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $itemsId, 'gwp' => ['>', 0]],
              'LIMIT'  => 1,
          ])->current();
          $usage = $row === null ? null : CarbonLogic::gramsToKg((float) $row['gwp']);
      }

       $energy = null;
       $emissions = null;
       $days = 0;
      if ($DB->tableExists(self::EMISSIONS_TABLE)) {
          $row = $DB->request([
              'SELECT' => [
                  'COUNT' => 'id AS days',
                  'SUM'   => ['energy_per_day AS energy', 'emission_per_day AS emissions'],
              ],
              'FROM'   => self::EMISSIONS_TABLE,
              'WHERE'  => [
                  'itemtype' => $itemtype,
                  'items_id' => $itemsId,
                  'date'     => ['>=', date('Y-m-d H:i:s', strtotime('-' . self::PERIOD_DAYS . ' days'))],
              ],
          ])->current();
          $days = (int) ($row['days'] ?? 0);
         if ($days > 0) {
             $energy = round((float) $row['energy'], 2);
             $emissions = CarbonLogic::gramsToKg((float) $row['emissions']);
         }
      }

      if ($embodied === null && $usage === null && $days === 0) {
          return null;
      }
       return [
           'embodied_kg'  => $embodied,
           'usage_kg'     => $usage,
           'energy_kwh'   => $energy,
           'emissions_kg' => $emissions,
           'days'         => $days,
           'kwh_per_day'  => $days > 0 ? round((float) $energy / $days, 3) : null,
       ];
   }
}
