<?php

namespace GlpiPlugin\Assetsign;

use Migration;

/**
 * Issue #273 : reference de document lisible (ex. RM-2026-000124), reservee a la creation de la
 * fiche selon le format de l'entite (Config::reference_*, regles dans DocumentReferenceFormat).
 *
 * Deux tables, volontairement conservees a la desinstallation du plugin (cf. hook.php) :
 * - les compteurs, un par entite qui porte le reglage et par periode (annee, ou 0 sans remise a
 *   zero annuelle) ;
 * - le registre des references emises, avec la fiche qui l'a recue : un numero reserve par une
 *   fiche jamais signee, annulee ou purgee y reste trace, et aucune reference n'est reemise, meme
 *   apres une reinstallation.
 *
 * Concurrence : le numero est pris par une mise a jour conditionnelle du compteur (compare et
 * echange : UPDATE ... WHERE next_number = valeur lue), qui ne reussit que pour une seule des
 * signatures simultanees ; l'autre relit le compteur et recommence. La cle unique du registre sur
 * la reference est le dernier garde-fou : deux fiches ne peuvent jamais enregistrer la meme.
 */
final class DocumentReference
{
   public const COUNTERS_TABLE = 'glpi_plugin_assetsign_referencecounters';
   public const REGISTRY_TABLE = 'glpi_plugin_assetsign_documentreferences';

   /** Tentatives avant d'abandonner (concurrence extreme ou numeros deja pris en serie). */
   private const MAX_ATTEMPTS = 200;

   /**
    * Reserve et enregistre la reference d'une fiche qui vient d'etre creee, puis la trace dans
    * l'historique du materiel. Format par defaut (vide) : l'identifiant interne, comme avant.
    */
   public static function assign(Assetsign $assetsign): string {
       global $DB;

       $config = Config::getForEntity((int) $assetsign->fields['entities_id']);
       $reference = self::reserve(
           $config,
           (int) $assetsign->fields['type'],
           $assetsign->getID(),
           (string) $assetsign->fields['itemtype'],
           (int) $assetsign->fields['items_id'],
           (int) $assetsign->fields['entities_id']
       );

       $DB->update(Assetsign::getTable(), ['reference' => $reference], ['id' => $assetsign->getID()]);
       $assetsign->fields['reference'] = $reference;

       $itemtype = (string) $assetsign->fields['itemtype'];
      if ($itemtype !== '' && (int) $assetsign->fields['items_id'] > 0) {
          \Log::history(
              (int) $assetsign->fields['items_id'],
              $itemtype,
              ['0', '', sprintf(
                  __('Fiche %1$s créée par AssetSign, référence %2$s', 'assetsign'),
                  Assetsign::getTypes()[(int) $assetsign->fields['type']] ?? '',
                  $reference
              )],
              0,
              \Log::HISTORY_LOG_SIMPLE_MESSAGE
          );
      }

       return $reference;
   }

   /**
    * @param int|null $timestamp Date de la fiche (maintenant par defaut), pour {annee}/{mois}/{jour}.
    */
   public static function reserve(Config $config, int $type, int $assetsignsId, string $itemtype, int $itemsId, int $entitiesId, ?int $timestamp = null): string {
       $format = (string) ($config->fields['reference_format'] ?? '');
       $prefix = (string) ($config->fields['reference_prefix'] ?? '');
       $yearlyReset = (bool) ($config->fields['reference_yearly_reset'] ?? 0);
       $digits = (int) ($config->fields['reference_seq_digits'] ?? DocumentReferenceFormat::DEFAULT_SEQ_DIGITS);
       $timestamp ??= time();

       // Un reglage invalide ne peut pas etre enregistre (Config::upsertForEntity()) ; s'il l'etait
       // quand meme (ecriture directe en base), retour au comportement historique.
      if (DocumentReferenceFormat::isDefault($format) || DocumentReferenceFormat::validate($format, $prefix, $yearlyReset) !== null) {
          return (string) $assetsignsId;
      }

       $counterEntity = (int) ($config->fields['entities_id'] ?? 0);
       $period = DocumentReferenceFormat::period($yearlyReset, $timestamp);

      for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
          $sequence = DocumentReferenceFormat::usesSequence($format) ? self::takeNextNumber($counterEntity, $period) : 0;
          $random = DocumentReferenceFormat::usesRandom($format) ? DocumentReferenceFormat::randomPart() : '';
          $reference = DocumentReferenceFormat::render($format, $prefix, $type, $sequence, $digits, $timestamp, $random);
         if ($reference !== '' && self::record($reference, $entitiesId, $assetsignsId, $itemtype, $itemsId)) {
             return $reference;
         }
          // Reference deja emise (prochain numero abaisse, entite soeur au meme format...) : le
          // numero est consomme, on passe au suivant.
      }

       throw new \RuntimeException('Plugin assetsign : aucune référence de document libre après ' . self::MAX_ATTEMPTS . ' tentatives.');
   }

   /** Prochain numero qui sera donne pour ce compteur (1 s'il n'a encore jamais servi). */
   public static function peekNextNumber(int $counterEntity, int $period): int {
       $row = self::getCounter($counterEntity, $period);
       return $row === null ? 1 : max(1, (int) $row['next_number']);
   }

   /** « Prochain numero » saisi dans la configuration. */
   public static function setNextNumber(int $counterEntity, int $period, int $next): void {
       global $DB;

       $next = max(1, $next);
       $row = self::getCounter($counterEntity, $period);
      if ($row !== null) {
          $DB->update(self::COUNTERS_TABLE, ['next_number' => $next, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
          return;
      }
      try {
          $DB->insert(self::COUNTERS_TABLE, [
              'entities_id' => $counterEntity,
              'period'      => $period,
              'next_number' => $next,
              'date_mod'    => date('Y-m-d H:i:s'),
          ]);
      } catch (\RuntimeException $e) {
          // Cree entre-temps par une signature : on le met a jour.
          $DB->update(self::COUNTERS_TABLE, ['next_number' => $next, 'date_mod' => date('Y-m-d H:i:s')], [
              'entities_id' => $counterEntity,
              'period'      => $period,
          ]);
      }
   }

   /**
    * Compare et echange : passe le compteur de $expected a $expected + 1, seulement s'il vaut encore
    * $expected. Faux si une autre signature l'a pris entre-temps.
    */
   public static function claim(int $counterId, int $expected): bool {
       global $DB;

       $DB->update(
           self::COUNTERS_TABLE,
           ['next_number' => $expected + 1, 'date_mod' => date('Y-m-d H:i:s')],
           ['id' => $counterId, 'next_number' => $expected]
       );
       return $DB->affectedRows() === 1;
   }

   /** Registre : fiche (meme purgee depuis) qui a recu cette reference, null si jamais emise. */
   public static function findInRegistry(string $reference): ?array {
       global $DB;

       $row = $DB->request(['FROM' => self::REGISTRY_TABLE, 'WHERE' => ['reference' => $reference], 'LIMIT' => 1])->current();
       return is_array($row) ? $row : null;
   }

   private static function takeNextNumber(int $counterEntity, int $period): int {
       global $DB;

      for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
          $row = self::getCounter($counterEntity, $period);
         if ($row === null) {
            try {
                $DB->insert(self::COUNTERS_TABLE, [
                    'entities_id' => $counterEntity,
                    'period'      => $period,
                    'next_number' => 1,
                    'date_mod'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\RuntimeException $e) {
                // Cree au meme instant par une autre signature (cle unique) : on le relit.
                continue;
            }
             continue;
         }
          $expected = (int) $row['next_number'];
         if (self::claim((int) $row['id'], $expected)) {
             return $expected;
         }
      }

       throw new \RuntimeException('Plugin assetsign : compteur de références indisponible.');
   }

   private static function getCounter(int $counterEntity, int $period): ?array {
       global $DB;

       $row = $DB->request([
           'FROM'  => self::COUNTERS_TABLE,
           'WHERE' => ['entities_id' => $counterEntity, 'period' => $period],
           'LIMIT' => 1,
       ])->current();
       return is_array($row) ? $row : null;
   }

   /** Inscrit la reference au registre ; faux si elle est deja prise (registre ou fiche existante). */
   private static function record(string $reference, int $entitiesId, int $assetsignsId, string $itemtype, int $itemsId): bool {
       global $DB;

      if (countElementsInTable(Assetsign::getTable(), ['reference' => $reference, ['NOT' => ['id' => $assetsignsId]]]) > 0) {
          return false;
      }
      try {
          $DB->insert(self::REGISTRY_TABLE, [
              'reference'                      => $reference,
              'entities_id'                    => $entitiesId,
              'plugin_assetsign_assetsigns_id' => $assetsignsId,
              'itemtype'                       => $itemtype,
              'items_id'                       => $itemsId,
              'date_creation'                  => date('Y-m-d H:i:s'),
          ]);
      } catch (\RuntimeException $e) {
          // Cle unique `reference` : deja emise.
          return false;
      }
       return true;
   }

   public static function install(Migration $migration): void {
       global $DB;

      if (!$DB->tableExists(self::COUNTERS_TABLE)) {
          $migration->displayMessage('Création de la table ' . self::COUNTERS_TABLE);
          $DB->doQuery("CREATE TABLE `" . self::COUNTERS_TABLE . "` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `period` int unsigned NOT NULL DEFAULT 0,
                `next_number` bigint unsigned NOT NULL DEFAULT 1,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `period`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
      if (!$DB->tableExists(self::REGISTRY_TABLE)) {
          $migration->displayMessage('Création de la table ' . self::REGISTRY_TABLE);
          $DB->doQuery("CREATE TABLE `" . self::REGISTRY_TABLE . "` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `reference` varchar(255) NOT NULL,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `reference` (`reference`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_assetsign_assetsigns_id` (`plugin_assetsign_assetsigns_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
