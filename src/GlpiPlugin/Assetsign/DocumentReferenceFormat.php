<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #273 : regles pures de la reference de document (format a marqueurs, validation, rendu),
 * testees sans base de donnees (tests/DocumentReferenceFormatTest.php). La reservation du numero,
 * elle, est dans DocumentReference (base de donnees).
 *
 * La reference apparait sur le PDF, dans le commentaire du Document GLPI, dans l'historique du
 * materiel et dans la liste des attributions : le format ne peut donc contenir que des lettres
 * non accentuees, des chiffres, le tiret et le souligne, en plus des marqueurs. Ni balise, ni
 * barre oblique (chemin), ni signe de formule (=, +, @) ; une reference ne commence jamais par un
 * tiret ou un souligne (formule dans un tableur).
 */
final class DocumentReferenceFormat
{
   public const MARKERS = ['prefixe', 'annee', 'mois', 'jour', 'seq', 'type', 'aleatoire'];

   public const MAX_FORMAT_LENGTH = 64;
   public const MAX_PREFIX_LENGTH = 20;
   public const DEFAULT_SEQ_DIGITS = 6;
   public const MAX_SEQ_DIGITS = 12;
   public const RANDOM_LENGTH = 6;

   /** Sans caracteres ambigus (0/O, 1/I/L) : la reference se lit et se recopie a la main. */
   public const RANDOM_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

   public const ERROR_LENGTH = 'length';
   public const ERROR_CHARACTERS = 'characters';
   public const ERROR_UNKNOWN_MARKER = 'unknown_marker';
   public const ERROR_NO_UNIQUE_PART = 'no_unique_part';
   public const ERROR_YEARLY_RESET_WITHOUT_YEAR = 'yearly_reset_without_year';
   public const ERROR_PREFIX = 'prefix';

   /** Code du type de fiche, marqueur {type}. Fixe (jamais traduit) : la reference est un identifiant. */
   public const TYPE_CODES = [
       Assetsign::TYPE_HANDOVER    => 'REM',
       Assetsign::TYPE_RETURN      => 'RES',
       Assetsign::TYPE_DON         => 'DON',
       Assetsign::TYPE_VENTE       => 'VEN',
       Assetsign::TYPE_DESTRUCTION => 'DES',
   ];

   /** Format vide : comportement historique, la reference est l'identifiant interne de la fiche. */
   public static function isDefault(string $format): bool {
       return trim($format) === '';
   }

   /**
    * Null si le format (et le prefixe) sont acceptables, sinon le code d'erreur (ERROR_*).
    */
   public static function validate(string $format, string $prefix = '', bool $yearlyReset = false): ?string {
      if (!self::isValidPrefix($prefix)) {
          return self::ERROR_PREFIX;
      }
      if (self::isDefault($format)) {
          return null;
      }
      if (strlen($format) > self::MAX_FORMAT_LENGTH) {
          return self::ERROR_LENGTH;
      }
       preg_match_all('/\{([^{}]*)\}/', $format, $matches);
      foreach ($matches[1] as $marker) {
         if (!in_array($marker, self::MARKERS, true)) {
             return self::ERROR_UNKNOWN_MARKER;
         }
      }
       $literal = (string) preg_replace('/\{(' . implode('|', self::MARKERS) . ')\}/', '', $format);
      if (!preg_match('/^[A-Za-z0-9_-]*$/', $literal)) {
          return self::ERROR_CHARACTERS;
      }
      if (!str_contains($format, '{seq}') && !str_contains($format, '{aleatoire}')) {
          return self::ERROR_NO_UNIQUE_PART;
      }
       // Sans l'annee dans le format, recommencer a 1 chaque annee reemettrait les memes references.
      if ($yearlyReset && str_contains($format, '{seq}') && !str_contains($format, '{annee}')) {
          return self::ERROR_YEARLY_RESET_WITHOUT_YEAR;
      }
       return null;
   }

   public static function isValidPrefix(string $prefix): bool {
       return preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,' . (self::MAX_PREFIX_LENGTH - 1) . '})?$/', $prefix) === 1;
   }

   public static function normalizeSeqDigits(int $digits): int {
       return max(1, min(self::MAX_SEQ_DIGITS, $digits));
   }

   public static function usesSequence(string $format): bool {
       return str_contains($format, '{seq}');
   }

   public static function usesRandom(string $format): bool {
       return str_contains($format, '{aleatoire}');
   }

   public static function typeCode(int $type): string {
       return self::TYPE_CODES[$type] ?? 'DOC';
   }

   /** Compteur de l'annee (recommencer a 1 chaque annee) ou compteur unique (0). */
   public static function period(bool $yearlyReset, int $timestamp): int {
       return $yearlyReset ? (int) date('Y', $timestamp) : 0;
   }

   public static function randomPart(): string {
       $part = '';
       $max = strlen(self::RANDOM_ALPHABET) - 1;
      for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
          $part .= self::RANDOM_ALPHABET[random_int(0, $max)];
      }
       return $part;
   }

   /**
    * Reference produite par un format deja valide. Toute valeur qui sortirait du jeu de caracteres
    * autorise est retiree, par securite, meme si validate() l'a deja refusee a l'enregistrement.
    */
   public static function render(string $format, string $prefix, int $type, int $sequence, int $seqDigits, int $timestamp, string $random): string {
       $reference = strtr($format, [
           '{prefixe}'   => $prefix,
           '{annee}'     => date('Y', $timestamp),
           '{mois}'      => date('m', $timestamp),
           '{jour}'      => date('d', $timestamp),
           '{seq}'       => str_pad((string) max(0, $sequence), self::normalizeSeqDigits($seqDigits), '0', STR_PAD_LEFT),
           '{type}'      => self::typeCode($type),
           '{aleatoire}' => $random,
       ]);
       $reference = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $reference);
       return ltrim($reference, '-_');
   }
}
