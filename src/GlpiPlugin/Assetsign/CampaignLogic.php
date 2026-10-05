<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #158 (attestation annuelle de détention) : règles pures, sans GLPI, des campagnes
 * d'attestation — statistiques de suivi, types d'écart, échéance. Testées sans base de données
 * (tests/CampaignLogicTest.php).
 */
final class CampaignLogic
{
   public const CAMPAIGN_OPEN   = 0;
   public const CAMPAIGN_CLOSED = 1;

   public const ATTESTATION_PENDING     = 0;
   public const ATTESTATION_CONFIRMED   = 1;
   public const ATTESTATION_DISCREPANCY = 2;

   /** Matériel listé que la personne n'a plus. */
   public const GAP_MISSING = 'missing';
   /** Matériel détenu mais absent de la liste. */
   public const GAP_EXTRA = 'extra';
   /** Matériel listé que la personne ne reconnaît pas. */
   public const GAP_UNKNOWN = 'unknown';

   public const GAPS = [self::GAP_MISSING, self::GAP_EXTRA, self::GAP_UNKNOWN];

   /**
    * @param list<int> $statuses statut de chaque attestation de la campagne
    * @return array{total: int, confirmed: int, discrepancy: int, pending: int, responded: int, response_rate: int}
    */
   public static function stats(array $statuses): array {
       $counts = array_count_values($statuses);
       $confirmed = $counts[self::ATTESTATION_CONFIRMED] ?? 0;
       $discrepancy = $counts[self::ATTESTATION_DISCREPANCY] ?? 0;
       $total = count($statuses);
       $responded = $confirmed + $discrepancy;

       return [
           'total'         => $total,
           'confirmed'     => $confirmed,
           'discrepancy'   => $discrepancy,
           'pending'       => $total - $responded,
           'responded'     => $responded,
           'response_rate' => $total === 0 ? 0 : (int) round(100 * $responded / $total),
       ];
   }

   /**
    * Types d'écart cochés, filtrés par liste blanche (jamais une valeur libre venue du formulaire).
    *
    * @param mixed $input
    * @return list<string>
    */
   public static function sanitizeGaps($input): array {
       return array_values(array_intersect(self::GAPS, is_array($input) ? $input : []));
   }

   /**
    * Échéance dépassée (la date limite est incluse : on peut encore répondre ce jour-là).
    */
   public static function isPastDeadline(?string $deadline, int $now): bool {
      if ($deadline === null || $deadline === '') {
          return false;
      }
       $end = strtotime($deadline . ' 23:59:59');

       return $end !== false && $now > $end;
   }
}
