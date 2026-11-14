<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #322 : attestation d'effacement des données exigée avant qu'un matériel change de mains.
 *
 * Réglage par entité (Config::wipe_requirement, hérité comme le reste de la configuration),
 * désactivé par défaut : l'administrateur choisit d'avertir ou de bloquer, et pour quels types de
 * fiche (don, vente, destruction, réattribution à une autre personne). Rien n'est jamais modifié
 * automatiquement : une fiche bloquée n'est simplement pas créée (don, vente, destruction) ou
 * reste en brouillon (réattribution) jusqu'à ce qu'une attestation existe.
 */
final class WipeRequirement
{
   public const MODE_DISABLED = 0;
   public const MODE_WARN     = 1;
   public const MODE_BLOCK    = 2;

    /** Types de fiche concernés possibles (Assetsign::TYPE_*). Remise = réattribution seulement. */
   public const TYPES = [Assetsign::TYPE_DON, Assetsign::TYPE_VENTE, Assetsign::TYPE_DESTRUCTION, Assetsign::TYPE_HANDOVER];

    /** Types retenus par défaut quand l'administrateur active l'exigence. */
   public const DEFAULT_TYPES = [Assetsign::TYPE_DON, Assetsign::TYPE_VENTE];

    /** Statuts d'une remise qui prouvent que le matériel a été remis à quelqu'un. */
   private const HANDED_OVER_EXCLUDED_STATUSES = [Assetsign::STATUS_DRAFT, Assetsign::STATUS_PENDING, Assetsign::STATUS_CANCELLED];

    /** @return array<int, string> */
   public static function getModeLabels(): array {
       return [
           self::MODE_DISABLED => __('Désactivé', 'assetsign'),
           self::MODE_WARN     => __('Avertir (la fiche est créée, avec un avertissement)', 'assetsign'),
           self::MODE_BLOCK    => __('Bloquer (la fiche ne peut pas être envoyée)', 'assetsign'),
       ];
   }

    /** @return array<int, string> type de fiche => libellé du réglage */
   public static function getTypeLabels(): array {
       return [
           Assetsign::TYPE_DON         => __('Don', 'assetsign'),
           Assetsign::TYPE_VENTE       => __('Vente', 'assetsign'),
           Assetsign::TYPE_DESTRUCTION => __('Destruction', 'assetsign'),
           Assetsign::TYPE_HANDOVER    => __('Réattribution à une autre personne', 'assetsign'),
       ];
   }

   public static function normalizeMode(mixed $mode): int {
       $mode = (int) $mode;
       return in_array($mode, [self::MODE_WARN, self::MODE_BLOCK], true) ? $mode : self::MODE_DISABLED;
   }

    /**
     * @return list<int> types connus, sans doublon, dans l'ordre de TYPES
     */
   public static function normalizeTypes(mixed $types): array {
      if (is_string($types)) {
          $types = json_decode($types, true);
      }
      if (!is_array($types)) {
          return [];
      }
       $wanted = array_map('intval', $types);
       return array_values(array_filter(self::TYPES, static fn (int $type): bool => in_array($type, $wanted, true)));
   }

    /** Mode en vigueur pour ce type de fiche dans cette configuration (0 si non concerné). */
   public static function modeForType(Config $config, int $type): int {
       $mode = self::normalizeMode($config->fields['wipe_requirement'] ?? self::MODE_DISABLED);
      if ($mode === self::MODE_DISABLED) {
          return self::MODE_DISABLED;
      }
       return in_array($type, self::normalizeTypes($config->fields['wipe_required_types'] ?? '[]'), true) ? $mode : self::MODE_DISABLED;
   }

    /**
     * Exigence non satisfaite pour cette fiche à venir : MODE_WARN ou MODE_BLOCK, MODE_DISABLED si
     * rien n'est exigé ou si une attestation valide existe. Une remise n'est concernée que si le
     * matériel a déjà été remis à une autre personne (réattribution).
     */
   public static function check(string $itemtype, int $itemsId, int $entitiesId, int $type, int $beneficiaryUsersId = 0): int {
       $mode = self::modeForType(Config::getForEntity($entitiesId), $type);
      if ($mode === self::MODE_DISABLED) {
          return self::MODE_DISABLED;
      }
      if ($type === Assetsign::TYPE_HANDOVER && !self::isReassignment($itemtype, $itemsId, $beneficiaryUsersId)) {
          return self::MODE_DISABLED;
      }
       return WipeAttestation::getValidFor($itemtype, $itemsId) === null ? $mode : self::MODE_DISABLED;
   }

    /** Le matériel a déjà été remis à quelqu'un d'autre que le nouveau bénéficiaire. */
   public static function isReassignment(string $itemtype, int $itemsId, int $newUsersId): bool {
       global $DB;

       $criteria = [
           'itemtype'   => $itemtype,
           'items_id'   => $itemsId,
           'type'       => Assetsign::TYPE_HANDOVER,
           'is_deleted' => 0,
           'NOT'        => ['status' => self::HANDED_OVER_EXCLUDED_STATUSES],
       ];
      if ($newUsersId > 0) {
          $criteria[] = ['NOT' => ['users_id' => $newUsersId]];
      }
       return $DB->request(['SELECT' => ['id'], 'FROM' => Assetsign::getTable(), 'WHERE' => $criteria, 'LIMIT' => 1])->count() > 0;
   }

    /**
     * Date de la dernière remise effective du matériel (avant $before si fourni), ou null : une
     * attestation n'est valable que si l'effacement a eu lieu après.
     */
   public static function lastHandoverDate(string $itemtype, int $itemsId, ?string $before = null): ?string {
       global $DB;

       $criteria = [
           'itemtype'   => $itemtype,
           'items_id'   => $itemsId,
           'type'       => Assetsign::TYPE_HANDOVER,
           'is_deleted' => 0,
           'NOT'        => ['status' => self::HANDED_OVER_EXCLUDED_STATUSES],
       ];
      if ($before !== null) {
          $criteria[] = ['date_creation' => ['<', $before]];
      }
       $row = $DB->request([
           'SELECT' => ['date_sent', 'date_creation'],
           'FROM'   => Assetsign::getTable(),
           'WHERE'  => $criteria,
           'ORDER'  => 'date_creation DESC',
           'LIMIT'  => 1,
       ])->current();
      if ($row === null) {
          return null;
      }
       $date = (string) ($row['date_sent'] ?: $row['date_creation']);
       return $date !== '' ? $date : null;
   }

    /** Message affiché au technicien, selon le mode et le type de fiche. */
   public static function message(int $mode, int $type): string {
       $what = match ($type) {
           Assetsign::TYPE_DON         => __('ce don', 'assetsign'),
           Assetsign::TYPE_VENTE       => __('cette vente', 'assetsign'),
           Assetsign::TYPE_DESTRUCTION => __('cette destruction', 'assetsign'),
           default                     => __('cette réattribution', 'assetsign'),
       };
      if ($mode === self::MODE_BLOCK) {
          return sprintf(
              __('Attestation d\'effacement des données exigée avant %s : enregistrez-la d\'abord depuis l\'onglet « Effacement des données » du matériel.', 'assetsign'),
              $what
          );
      }
       return sprintf(
           __('Attention : aucune attestation d\'effacement des données n\'est enregistrée pour ce matériel avant %s. Pensez à l\'ajouter depuis l\'onglet « Effacement des données ».', 'assetsign'),
           $what
       );
   }
}
