<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #221 (arrivée d'un salarié) : règles pures, sans GLPI, du dossier d'arrivée Arrival —
 * détection des déclencheurs et liste des tâches du ticket. Testées sans base de données
 * (tests/ArrivalLogicTest.php).
 *
 * Pas de connecteur propre à un logiciel RH : les SIRH alimentent déjà l'annuaire (LDAP, Entra ID)
 * synchronisé avec les comptes GLPI. Le dossier part donc de la vie du compte GLPI : création,
 * réactivation, approche de sa date de début.
 */
final class ArrivalLogic
{
   public const TRIGGER_MANUAL       = 0;
   public const TRIGGER_CREATION     = 1;
   public const TRIGGER_REACTIVATION = 2;
   public const TRIGGER_BEGIN_DATE   = 3;

   /**
    * Un compte créé avec une date de début plus ancienne que ce délai est un salarié déjà présent
    * (premier import de l'annuaire, rattrapage) : pas de dossier d'arrivée.
    */
   public const CREATION_MAX_PAST_DAYS = 30;

   public const MAX_TASKS = 20;

   /** Tâches proposées par défaut (une par ligne dans la configuration). */
   public const DEFAULT_TASKS = "Commander ou préparer le poste de travail\n"
      . "Attribuer les licences logicielles\n"
      . "Créer les accès (badge, comptes applicatifs, messagerie)\n"
      . "Remettre le matériel et faire signer la fiche d'attribution";

    /**
     * Réactivation d'un compte : `is_active` passe de 0 à 1 lors de cette mise à jour.
     *
     * @param array<string, mixed> $oldValues valeurs AVANT mise à jour (CommonDBTM::$oldvalues)
     * @param array<string, mixed> $fields    valeurs APRÈS mise à jour
     */
   public static function isReactivation(array $oldValues, array $fields): bool {
       return array_key_exists('is_active', $oldValues)
           && (int) $oldValues['is_active'] === 0
           && (int) ($fields['is_active'] ?? 0) === 1;
   }

    /**
     * Un compte qui vient d'être créé ouvre un dossier s'il est actif et ne correspond pas à un
     * salarié déjà présent (date de début absente ou récente).
     *
     * @param array<string, mixed> $fields champs du compte créé
     */
   public static function isNewcomerAccount(array $fields, int $now): bool {
      if ((int) ($fields['is_active'] ?? 1) !== 1 || !empty($fields['is_deleted'])) {
          return false;
      }
       $begin = (string) ($fields['begin_date'] ?? '');
      if ($begin === '') {
          return true;
      }
       $time = strtotime($begin);
       return $time === false || $time >= $now - self::CREATION_MAX_PAST_DAYS * 86400;
   }

    /**
     * Date de début du compte (`glpi_users.begin_date`) dans les $days prochains jours (ou passée
     * de moins d'un jour, pour ne pas rater une arrivée si la tâche n'a pas tourné la veille).
     */
   public static function beginDateWithinWindow(?string $beginDate, int $days, int $now): bool {
      if ($beginDate === null || $beginDate === '' || $days < 0) {
          return false;
      }
       $begin = strtotime($beginDate);
      if ($begin === false) {
          return false;
      }
       return $begin >= $now - 86400 && $begin <= $now + $days * 86400;
   }

    /**
     * Tâches du ticket d'arrivée : une par ligne non vide, sans doublon, au plus MAX_TASKS, 255
     * caractères chacune. Configuration vide : $defaults (la liste traduite, cf.
     * Arrival::defaultTasksText()), à défaut DEFAULT_TASKS.
     *
     * @return list<string>
     */
   public static function parseTasks(?string $text, ?string $defaults = null): array {
       $source = trim((string) $text) === '' ? ($defaults ?? self::DEFAULT_TASKS) : (string) $text;
       $tasks = [];
      foreach (preg_split('/\R/u', $source) ?: [] as $line) {
          $line = trim($line);
         if ($line === '' || in_array($line, $tasks, true)) {
             continue;
         }
          $tasks[] = mb_substr($line, 0, 255);
         if (count($tasks) >= self::MAX_TASKS) {
             break;
         }
      }
       return $tasks;
   }
}
