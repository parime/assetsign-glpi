<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #229 (champs du plugin Fields sur la fiche) : règles pures, sans GLPI, testées sans base de
 * données (tests/FieldsPluginLogicTest.php). La lecture des valeurs elle-même est dans
 * FieldsPluginValues.
 */
final class FieldsPluginLogic
{
   /** Types de champ Fields affichés tels quels (texte), après nettoyage. */
   private const TEXT_TYPES = ['text', 'textarea', 'richtext', 'url', 'number'];

    /**
     * Liste des blocs retenus, enregistrée en JSON dans la configuration : seuls des identifiants
     * de blocs existants sont gardés, sans doublon, triés.
     *
     * @param mixed     $input        valeur postée (tableau d'identifiants, ou rien)
     * @param list<int> $availableIds blocs Fields actifs de l'instance
     */
   public static function normalizeContainerIds(mixed $input, array $availableIds): string {
      if (!is_array($input)) {
          return '[]';
      }
       $ids = array_values(array_unique(array_filter(
           array_map('intval', $input),
           static fn (int $id): bool => in_array($id, $availableIds, true)
       )));
       sort($ids);
       return json_encode($ids) ?: '[]';
   }

    /**
     * @return list<int>
     */
   public static function decodeContainerIds(?string $json): array {
       $ids = json_decode((string) $json, true);
       return is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [];
   }

    /**
     * Valeur lisible d'un champ « simple » (texte, nombre, URL, oui/non, date). Les listes
     * déroulantes et liens vers un élément GLPI sont résolus par FieldsPluginValues ; ce qui ne
     * l'est pas ici renvoie null.
     *
     * @param array{yes: string, no: string} $yesNo libellés traduits
     */
   public static function formatScalar(string $type, mixed $raw, array $yesNo): ?string {
      if ($raw === null || $raw === '' || is_array($raw)) {
          // Champ non renseigné : rien à imprimer.
          return '';
      }
      if (in_array($type, self::TEXT_TYPES, true)) {
          $text = str_replace("\u{a0}", ' ', html_entity_decode(
              strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $raw)),
              ENT_QUOTES | ENT_HTML5
          ));
          $text = trim($text);
          return preg_replace("/\n{2,}/", "\n", $text) ?? $text;
      }
      if ($type === 'yesno') {
          return (int) $raw === 1 ? $yesNo['yes'] : $yesNo['no'];
      }
      if ($type === 'date' || $type === 'datetime') {
          $time = strtotime((string) $raw);
         if ($time === false) {
             return '';
         }
          return date($type === 'date' ? 'd/m/Y' : 'd/m/Y H:i', $time);
      }
       return null;
   }

    /**
     * Clé utilisable dans le texte du gabarit : {champ:<nom système du champ>}.
     */
   public static function placeholderKey(string $fieldName): string {
       return 'champ:' . $fieldName;
   }
}
