<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #222 : regles pures du renouvellement du parc (fin d'amortissement, budget par annee),
 * testees sans base de donnees (tests/RenewalLogicTest.php).
 */
final class RenewalLogic
{
   public const HORIZONS = [3, 6, 12, 24];

   /**
    * Fin d'amortissement : mise en service (a defaut, achat) + duree d'amortissement de la fiche
    * financiere (Infocom::sink_time, en annees), ou la duree par defaut de l'entite si elle n'est
    * pas renseignee. Null sans date de depart.
    */
   public static function endOfLife(?string $useDate, ?string $buyDate, float $sinkYears, int $defaultYears): ?string {
       $start = !empty($useDate) ? $useDate : (!empty($buyDate) ? $buyDate : null);
       $years = $sinkYears > 0 ? $sinkYears : (float) $defaultYears;
      if ($start === null || $years <= 0) {
          return null;
      }
       $months = (int) round($years * 12);
       return date('Y-m-d', (int) strtotime(substr($start, 0, 10) . " +$months months"));
   }

   /** A renouveler d'ici l'horizon (fin deja passee comprise). */
   public static function isDue(?string $endOfLife, string $today, int $horizonMonths): bool {
      if ($endOfLife === null) {
          return false;
      }
       return $endOfLife <= date('Y-m-d', (int) strtotime("$today +$horizonMonths months"));
   }

   /**
    * Budget de renouvellement par annee de fin d'amortissement (valeur d'achat de la fiche
    * financiere ; une valeur absente compte zero et est signalee par le nombre de materiels).
    *
    * @param list<array{end_of_life: string, value: float}> $rows
    * @return array<string, array{count: int, budget: float}>
    */
   public static function budgetByYear(array $rows): array {
       $years = [];
      foreach ($rows as $row) {
          $year = substr($row['end_of_life'], 0, 4);
          $years[$year] ??= ['count' => 0, 'budget' => 0.0];
          $years[$year]['count']++;
          $years[$year]['budget'] += max(0.0, $row['value']);
      }
       ksort($years);
       return $years;
   }

   public static function normalizeHorizon(int $months): int {
       return in_array($months, self::HORIZONS, true) ? $months : 12;
   }
}
