<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #142 (tableau de bord RSE) : calculs purs, sans GLPI, des indicateurs RSE du parc — à
 * partir UNIQUEMENT de données réelles déjà saisies (principe fondateur du plugin, cf.
 * EnvironmentalData) : empreinte de fabrication saisie, dates Infocom, fiches de fin de vie
 * signées. Une donnée absente n'est jamais remplacée par une estimation : elle fait baisser le
 * taux de couverture affiché à côté de l'indicateur.
 *
 * Testé sans base de données (tests/CsrLogicTest.php).
 */
final class CsrLogic
{
   private const YEAR_SECONDS = 86400 * 365.25;

   /**
    * Bénéfice du réemploi (« impact évité ») d'un matériel, méthode d'évitement proportionnel :
    * `empreinte × (durée réelle − durée prévue) / durée prévue`, seulement au-delà de la durée
    * d'amortissement prévue. null si une donnée manque ou si rien n'est encore à valoriser.
    * Formule partagée avec le passeport matériel (PassportEvent::getReuseBenefit()).
    *
    * @return array{avoided_impact: float, planned_years: float, actual_years: float, is_still_in_service: bool}|null
    */
   public static function avoidedImpact(
       ?float $footprint,
       float $plannedYears,
       ?string $useDate,
       ?string $decommissionDate,
       int $now
   ): ?array {
      if ($footprint === null || $plannedYears <= 0 || empty($useDate)) {
          return null;
      }
       $start = strtotime($useDate);
       $isStillInService = empty($decommissionDate);
       $end = $isStillInService ? $now : strtotime((string) $decommissionDate);
      if ($start === false || $end === false) {
          return null;
      }
       $actualYears = max(0.0, ($end - $start) / self::YEAR_SECONDS);
       $extraYears = $actualYears - $plannedYears;
      if ($extraYears <= 0) {
          return null;
      }

       return [
           'avoided_impact'      => round($footprint * ($extraYears / $plannedYears), 2),
           'planned_years'       => $plannedYears,
           'actual_years'        => round($actualYears, 1),
           'is_still_in_service' => $isStillInService,
       ];
   }

   /**
    * @param list<array{footprint: float|null, sink_time: float, use_date: string|null, decommission_date: string|null}> $items
    * @param array{don: int, vente: int, destruction: int} $endOfLife fiches de fin de vie finalisées sur la période
    * @return array{
    *     total_items: int, with_footprint: int, footprint_coverage: int, footprint_total: float,
    *     avoided_total: float, extended_items: int, average_age_years: float|null,
    *     average_lifetime_years: float|null, end_of_life: array{don: int, vente: int, destruction: int},
    *     second_life_rate: int|null
    * }
    */
   public static function summarize(array $items, array $endOfLife, int $now): array {
       $withFootprint = 0;
       $footprintTotal = 0.0;
       $avoidedTotal = 0.0;
       $extended = 0;
       $ages = [];
       $lifetimes = [];

      foreach ($items as $item) {
         if ($item['footprint'] !== null) {
             $withFootprint++;
             $footprintTotal += $item['footprint'];
         }
          $benefit = self::avoidedImpact($item['footprint'], $item['sink_time'], $item['use_date'], $item['decommission_date'], $now);
         if ($benefit !== null) {
             $extended++;
             $avoidedTotal += $benefit['avoided_impact'];
         }
          $start = !empty($item['use_date']) ? strtotime($item['use_date']) : false;
         if ($start === false) {
             continue;
         }
         if (empty($item['decommission_date'])) {
             $ages[] = max(0, $now - $start) / self::YEAR_SECONDS;
         } else {
             $end = strtotime($item['decommission_date']);
            if ($end !== false) {
                $lifetimes[] = max(0, $end - $start) / self::YEAR_SECONDS;
            }
         }
      }

       $total = count($items);
       $endOfLifeTotal = $endOfLife['don'] + $endOfLife['vente'] + $endOfLife['destruction'];

       return [
           'total_items'            => $total,
           'with_footprint'         => $withFootprint,
           'footprint_coverage'     => $total === 0 ? 0 : (int) round(100 * $withFootprint / $total),
           'footprint_total'        => round($footprintTotal, 2),
           'avoided_total'          => round($avoidedTotal, 2),
           'extended_items'         => $extended,
           'average_age_years'      => $ages === [] ? null : round(array_sum($ages) / count($ages), 1),
           'average_lifetime_years' => $lifetimes === [] ? null : round(array_sum($lifetimes) / count($lifetimes), 1),
           'end_of_life'            => $endOfLife,
           'second_life_rate'       => $endOfLifeTotal === 0
               ? null
               : (int) round(100 * ($endOfLife['don'] + $endOfLife['vente']) / $endOfLifeTotal),
       ];
   }
}
