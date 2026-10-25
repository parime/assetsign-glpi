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

   /** Attestation de détention du matériel (issue #158). */
   public const TYPE_HOLDING = 0;
   /** Signature de la charte informatique (issue #204). */
   public const TYPE_CHARTER = 1;

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

   /**
    * Issue #204 : une nouvelle campagne de charte est due quand aucune n'a encore été lancée pour
    * l'entité, ou que la dernière date d'au moins $months mois.
    */
   public static function charterRenewalDue(?string $lastLaunch, int $months, int $now): bool {
      if ($lastLaunch === null || $lastLaunch === '') {
          return true;
      }
       $next = strtotime($lastLaunch . ' +' . max(1, $months) . ' months');

       return $next !== false && $now >= $next;
   }

   /**
    * Ticket des non-signataires de la charte : une seule fois, une fois l'échéance passée, et
    * seulement s'il reste des personnes sans réponse.
    */
   public static function charterTicketDue(?string $deadline, int $existingTicketId, int $pending, int $now): bool {
       return $existingTicketId === 0 && $pending > 0 && self::isPastDeadline($deadline, $now);
   }

   /**
    * Issue #224 : empreinte d'une version de la charte. Les espaces sont normalises : un simple
    * changement de mise en page de l'editeur ne cree pas une nouvelle version. Chaine vide si aucune
    * charte.
    */
   public static function charterHash(string $html): string {
       $normalized = trim((string) preg_replace('/\s+/u', ' ', $html));
       return $normalized === '' ? '' : hash('sha256', $normalized);
   }

   /**
    * Issue #224 : personnes dont la derniere signature porte sur une autre version que la charte en
    * vigueur. Une personne qui n'a jamais signe n'en fait pas partie : la campagne habituelle la
    * concerne deja.
    *
    * @param array<int, string> $lastSignedHashByUser identifiant de la personne => empreinte signee
    * @return list<int>
    */
   public static function outdatedSigners(array $lastSignedHashByUser, string $currentHash): array {
      if ($currentHash === '') {
          return [];
      }
       $outdated = [];
      foreach ($lastSignedHashByUser as $usersId => $hash) {
         if ($hash !== '' && $hash !== $currentHash) {
             $outdated[] = (int) $usersId;
         }
      }
       sort($outdated);
       return $outdated;
   }
}
