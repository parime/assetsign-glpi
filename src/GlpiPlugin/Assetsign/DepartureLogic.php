<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #157 (départ d'un salarié) : règles pures, sans GLPI, du « dossier groupé » Departure —
 * détection des déclencheurs, statut du dossier d'après ses fiches de restitution, échéance des
 * relances. Testées sans base de données (tests/DepartureLogicTest.php).
 */
final class DepartureLogic
{
   public const TRIGGER_MANUAL       = 0;
   public const TRIGGER_DEACTIVATION = 1;
   public const TRIGGER_END_DATE     = 2;

   public const STATUS_OPEN      = 0;
   public const STATUS_SIGNED    = 1;
   public const STATUS_CANCELLED = 2;

    /**
     * Désactivation d'un compte : `is_active` passe de 1 à 0 lors de cette mise à jour.
     *
     * @param array<string, mixed> $oldValues valeurs AVANT mise à jour (CommonDBTM::$oldvalues)
     * @param array<string, mixed> $fields    valeurs APRÈS mise à jour
     */
   public static function isDeactivation(array $oldValues, array $fields): bool {
       return array_key_exists('is_active', $oldValues)
           && (int) $oldValues['is_active'] === 1
           && (int) ($fields['is_active'] ?? 1) === 0;
   }

    /**
     * Date de fin de compte (`glpi_users.end_date`) dans les $days prochains jours (ou déjà
     * passée de moins d'un jour, pour ne pas rater un départ si la tâche n'a pas tourné la veille).
     */
   public static function endDateWithinWindow(?string $endDate, int $days, int $now): bool {
      if ($endDate === null || $endDate === '' || $days < 0) {
          return false;
      }
       $end = strtotime($endDate);
      if ($end === false) {
          return false;
      }

       return $end >= $now - 86400 && $end <= $now + $days * 86400;
   }

    /**
     * Statut du dossier d'après celui de ses fiches : signé quand plus aucune n'attend de
     * signature et qu'au moins une a été signée ; annulé si toutes ont été annulées.
     *
     * @param list<int> $childStatuses statuts Assetsign::STATUS_* des fiches du dossier
     */
   public static function statusFromChildren(array $childStatuses): int {
       $awaiting = array_intersect(
           $childStatuses,
           [Assetsign::STATUS_DRAFT, Assetsign::STATUS_PENDING, ...Assetsign::STATUSES_AWAITING_SIGNATURE]
       );
      if ($childStatuses === [] || $awaiting !== []) {
          return self::STATUS_OPEN;
      }
      if (array_unique($childStatuses) === [Assetsign::STATUS_CANCELLED]) {
          return self::STATUS_CANCELLED;
      }

       return self::STATUS_SIGNED;
   }

    /**
     * Même calendrier que les relances individuelles (Assetsign::runReminders()) : la n-ième
     * relance est due quand le nombre de jours écoulés atteint la somme des n premiers délais, le
     * dernier délai se répétant ensuite. Jamais deux relances rapprochées pour « rattraper » un
     * retard (tâche planifiée arrêtée quelques jours) : il faut aussi que le délai de l'étape
     * courante se soit écoulé depuis la relance précédente.
     *
     * @param list<int> $delays délais configurés (Config::getReminderDelays())
     */
   public static function reminderDue(
       int $daysSinceCreation,
       ?int $daysSinceLastReminder,
       int $reminderCount,
       array $delays,
       int $maxReminders
   ): bool {
      if ($delays === [] || ($maxReminders > 0 && $reminderCount >= $maxReminders)) {
          return false;
      }
       $due = 0;
      for ($i = 0; $i <= $reminderCount; $i++) {
          $due += $delays[min($i, count($delays) - 1)];
      }
       $stepDelay = $delays[min($reminderCount, count($delays) - 1)];

       return $daysSinceCreation >= $due
           && ($daysSinceLastReminder === null || $daysSinceLastReminder >= $stepDelay);
   }
}
