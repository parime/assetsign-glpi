<?php

namespace GlpiPlugin\Assetsign\Dashboard;

use CommonDBTM;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\CsrLogic;
use GlpiPlugin\Assetsign\EnvironmentalData;
use Session;

/**
 * Issue #142 : collecte des indicateurs RSE sur les entités actives (tous les matériels des
 * types gérés, affectés ou non, hors corbeille et gabarits), puis calcul par CsrLogic. Requêtes
 * groupées par type de matériel (empreintes et Infocom chargées en une fois), jamais une requête
 * par matériel.
 */
final class CsrIndicators
{
   /** Période des filières de fin de vie : 12 derniers mois glissants. */
   public const END_OF_LIFE_MONTHS = 12;

   /**
    * @return array{
    *     total_items: int, with_footprint: int, footprint_coverage: int, footprint_total: float,
    *     avoided_total: float, extended_items: int, average_age_years: float|null,
    *     average_lifetime_years: float|null, end_of_life: array{don: int, vente: int, destruction: int},
    *     second_life_rate: int|null
    * }
    */
   public static function collect(): array {
       global $DB;

       $items = [];
       $itemtypes = Config::getForEntity((int) Session::getActiveEntity())->getManagedItemtypes();
      foreach ($itemtypes as $itemtype) {
         if (!is_subclass_of($itemtype, CommonDBTM::class)) {
             continue;
         }
          $table = $itemtype::getTable();
          $where = $DB->fieldExists($table, 'entities_id') ? getEntitiesRestrictCriteria($table, '', '', $DB->fieldExists($table, 'is_recursive')) : [];
         foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($table, $flag)) {
               $where[$table . '.' . $flag] = 0;
            }
         }
          $ids = array_map('intval', array_column(iterator_to_array($DB->request([
              'SELECT' => [$table . '.id'],
              'FROM'   => $table,
              'WHERE'  => $where,
          ]), false), 'id'));
         if ($ids === []) {
             continue;
         }

          $footprints = [];
         foreach ($DB->request([
                 'SELECT' => ['items_id', 'carbon_footprint_manufacturing'],
                 'FROM'   => EnvironmentalData::getTable(),
                 'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids, 'NOT' => ['carbon_footprint_manufacturing' => null]],
             ]) as $row
         ) {
             $footprints[(int) $row['items_id']] = (float) $row['carbon_footprint_manufacturing'];
         }
          $infocoms = [];
         foreach ($DB->request([
                 'SELECT' => ['items_id', 'use_date', 'decommission_date', 'sink_time'],
                 'FROM'   => 'glpi_infocoms',
                 'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids],
             ]) as $row
         ) {
             $infocoms[(int) $row['items_id']] = $row;
         }

         foreach ($ids as $id) {
             $infocom = $infocoms[$id] ?? [];
             $items[] = [
                 'footprint'         => $footprints[$id] ?? null,
                 'sink_time'         => (float) ($infocom['sink_time'] ?? 0),
                 'use_date'          => $infocom['use_date'] ?? null,
                 'decommission_date' => $infocom['decommission_date'] ?? null,
             ];
         }
      }

       return CsrLogic::summarize($items, self::endOfLife(), time());
   }

   /**
    * Fiches de don, vente et destruction finalisées (signées, ou terminées sans signature pour un
    * bénéficiaire externe) sur les 12 derniers mois.
    *
    * @return array{don: int, vente: int, destruction: int}
    */
   private static function endOfLife(): array {
       global $DB;

       $counts = ['don' => 0, 'vente' => 0, 'destruction' => 0];
       $types = [Assetsign::TYPE_DON => 'don', Assetsign::TYPE_VENTE => 'vente', Assetsign::TYPE_DESTRUCTION => 'destruction'];
      foreach ($DB->request([
              'SELECT' => ['type', 'COUNT' => 'id AS cpt'],
              'FROM'   => Assetsign::getTable(),
              'WHERE'  => [
                  'type'        => array_keys($types),
                  'status'      => [Assetsign::STATUS_SIGNED, Assetsign::STATUS_COMPLETED_NO_SIGNATURE],
                  'is_deleted'  => 0,
                  'date_signed' => ['>=', date('Y-m-d H:i:s', strtotime('-' . self::END_OF_LIFE_MONTHS . ' months'))],
              ] + getEntitiesRestrictCriteria(Assetsign::getTable(), '', '', true),
              'GROUPBY' => 'type',
          ]) as $row
      ) {
          $counts[$types[(int) $row['type']]] = (int) $row['cpt'];
      }

       return $counts;
   }

   /**
    * Cartes du tableau de bord GLPI natif (widgets bigNumber).
    *
    * @param array<string, mixed> $params
    * @return array{number: int|float, url: string, label: string, icon: string}
    */
   public static function carbonCard(array $params = []): array {
       $summary = self::collect();
       return self::card(
           (int) round($summary['footprint_total']),
           sprintf(__('kg CO2-eq de fabrication (%d %% du parc renseigné)', 'assetsign'), $summary['footprint_coverage']),
           'ti ti-leaf'
       );
   }

   /**
    * @param array<string, mixed> $params
    * @return array{number: int|float, url: string, label: string, icon: string}
    */
   public static function avoidedCard(array $params = []): array {
       $summary = self::collect();
       return self::card(
           (int) round($summary['avoided_total']),
           sprintf(__('kg CO2-eq évités par le réemploi (%d matériel(s) prolongé(s))', 'assetsign'), $summary['extended_items']),
           'ti ti-recycle'
       );
   }

   /**
    * @param array<string, mixed> $params
    * @return array{number: int|float, url: string, label: string, icon: string}
    */
   public static function secondLifeCard(array $params = []): array {
       $summary = self::collect();
       return self::card(
           $summary['second_life_rate'] ?? 0,
           __('Taux de seconde vie en pourcentage (dons et ventes, 12 mois)', 'assetsign'),
           'ti ti-heart-handshake'
       );
   }

   /**
    * @return array{number: int, url: string, label: string, icon: string}
    */
   private static function card(int $number, string $label, string $icon): array {
       global $CFG_GLPI;
       return [
           'number' => $number,
           'url'    => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/csr.php',
           'label'  => $label,
           'icon'   => $icon,
       ];
   }
}
