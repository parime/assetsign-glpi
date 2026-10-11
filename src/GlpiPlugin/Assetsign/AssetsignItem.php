<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Issue #166 : objets du parc GLPI (ecran, station d'accueil, casque, telephone...) remis AVEC le
 * materiel principal d'une fiche, en plus des accessoires « catalogue » (AssetsignAccessory, un
 * simple nom). Le PDF reprend pour chacun type, nom, marque/modele, n° de serie et n° d'inventaire,
 * lus dans GLPI et jamais ressaisis.
 *
 * Ces valeurs sont recopiees dans la ligne (instantane) : rafraichies a chaque regeneration du PDF
 * non signe (refreshSnapshots()), puis figees une fois la fiche signee — le document signe reste
 * la preuve de ce qui a ete remis, meme si la fiche GLPI de l'accessoire change ensuite.
 */
class AssetsignItem extends Compat\Base\AssetsignItemBase
{
   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   public const ERROR_UNKNOWN_TYPE   = 'unknown_type';
   public const ERROR_NOT_FOUND      = 'not_found';
   public const ERROR_MAIN_ITEM      = 'main_item';
   public const ERROR_ALREADY_LISTED = 'already_listed';
   public const ERROR_ON_OTHER_SHEET = 'on_other_sheet';

   /** Materiel physique natif que l'on remet a une personne (pas de licence, certificat, local...). */
   private const PHYSICAL_ITEMTYPES = ['Computer', 'Monitor', 'Peripheral', 'Phone', 'Printer', 'NetworkEquipment'];

    /**
     * Types proposables comme accessoires : le materiel physique natif de GLPI et les actifs
     * personnalises (Glpi\Asset\Asset), parmi les types d'actifs actifs ($CFG_GLPI['asset_types']).
     * Licences, certificats, applicatifs, locaux, actifs non geres... sont exclus : on ne les remet
     * pas en main propre.
     *
     * @return list<class-string<CommonDBTM>>
     */
   public static function getAllowedItemtypes(): array {
       global $CFG_GLPI;

       $types = [];
      foreach ((array) ($CFG_GLPI['asset_types'] ?? []) as $itemtype) {
         if (!is_string($itemtype) || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
             continue;
         }
         if (in_array($itemtype, self::PHYSICAL_ITEMTYPES, true) || is_a($itemtype, \Glpi\Asset\Asset::class, true)) {
             $types[] = $itemtype;
         }
      }
       return $types;
   }

    /**
     * Valeurs reprises sur le PDF, lues dans GLPI au moment de l'appel.
     *
     * @return array{name: string, type_label: string, manufacturer: string, model: string, serial: string, otherserial: string}|null
     */
   public static function snapshot(string $itemtype, int $items_id): ?array {
      if (!in_array($itemtype, self::getAllowedItemtypes(), true)) {
          return null;
      }
       $item = new $itemtype();
      if ($items_id <= 0 || !$item->getFromDB($items_id)) {
          return null;
      }

       return [
           'name'         => (string) ($item->fields['name'] ?? ''),
           'type_label'   => Assetsign::getCanonicalItemtypeLabel($itemtype),
           'manufacturer' => Assetsign::resolveManufacturerName($item),
           'model'        => Assetsign::resolveModelName($item),
           'serial'       => (string) ($item->fields['serial'] ?? ''),
           'otherserial'  => (string) ($item->fields['otherserial'] ?? ''),
       ];
   }

    /**
     * Ajoute un objet GLPI a une fiche. Refuse le materiel principal de la fiche, un doublon, et un
     * objet deja present sur une AUTRE fiche encore en attente de signature (un meme ecran ne peut
     * pas etre remis deux fois en parallele).
     *
     * @return string|null null si ajoute, sinon un code ERROR_*
     */
   public static function attach(Assetsign $assetsign, string $itemtype, int $items_id, bool $automatic = false): ?string {
       global $DB;

       $snapshot = self::snapshot($itemtype, $items_id);
      if ($snapshot === null) {
          return in_array($itemtype, self::getAllowedItemtypes(), true) ? self::ERROR_NOT_FOUND : self::ERROR_UNKNOWN_TYPE;
      }
      if ($itemtype === $assetsign->fields['itemtype'] && $items_id === (int) $assetsign->fields['items_id']) {
          return self::ERROR_MAIN_ITEM;
      }

       $sameItem = ['itemtype' => $itemtype, 'items_id' => $items_id];
      if (count($DB->request(['FROM' => self::getTable(), 'WHERE' => $sameItem + ['plugin_assetsign_assetsigns_id' => $assetsign->getID()]])) > 0) {
          return self::ERROR_ALREADY_LISTED;
      }
      if (self::getPendingSheetIdFor($itemtype, $items_id, $assetsign->getID()) !== null) {
          return self::ERROR_ON_OTHER_SHEET;
      }

       (new self())->add($sameItem + $snapshot + [
           'plugin_assetsign_assetsigns_id' => $assetsign->getID(),
           'is_automatic'                   => (int) $automatic,
       ]);
       return null;
   }

    /** Fiche encore modifiable (hors $exceptAssetsignsId) qui liste deja cet objet, s'il y en a une. */
   public static function getPendingSheetIdFor(string $itemtype, int $items_id, int $exceptAssetsignsId = 0): ?int {
       global $DB;

       $link = self::getTable();
       $sheet = Assetsign::getTable();
      foreach ($DB->request([
          'SELECT'     => ["$link.plugin_assetsign_assetsigns_id"],
          'FROM'       => $link,
          'INNER JOIN' => [$sheet => ['FKEY' => [$link => 'plugin_assetsign_assetsigns_id', $sheet => 'id']]],
          'WHERE'      => [
              "$link.itemtype" => $itemtype,
              "$link.items_id" => $items_id,
              "$sheet.status"  => Assetsign::STATUSES_STILL_EDITABLE,
              'NOT'            => ["$link.plugin_assetsign_assetsigns_id" => $exceptAssetsignsId],
          ],
          'LIMIT'      => 1,
      ]) as $row) {
          return (int) $row['plugin_assetsign_assetsigns_id'];
      }
       return null;
   }

   public static function detach(int $assetsigns_id, int $id): void {
       global $DB;
       $DB->delete(self::getTable(), ['id' => $id, 'plugin_assetsign_assetsigns_id' => $assetsigns_id]);
   }

    /** @return list<array<string, mixed>> lignes de la fiche, dans l'ordre d'ajout */
   public static function getForAssetsign(int $assetsigns_id): array {
       global $DB;
       return array_values(iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_assetsign_assetsigns_id' => $assetsigns_id],
           'ORDER' => 'id',
       ])));
   }

    /**
     * Relit dans GLPI les valeurs de chaque ligne (n° de serie corrige entre-temps...). A n'appeler
     * que tant que la fiche est modifiable : apres signature, l'instantane fait foi.
     */
   public static function refreshSnapshots(int $assetsigns_id): void {
       global $DB;
      foreach (self::getForAssetsign($assetsigns_id) as $row) {
          $snapshot = self::snapshot((string) $row['itemtype'], (int) $row['items_id']);
         if ($snapshot !== null) {
             $DB->update(self::getTable(), $snapshot, ['id' => (int) $row['id']]);
         }
      }
   }

    /**
     * Peripheriques, ecrans, telephones, imprimantes... connectes nativement a ce materiel dans GLPI
     * (onglet « Connexions », table glpi_assets_assets_peripheralassets, presente en GLPI 11 et 12).
     *
     * @return list<array{itemtype: string, items_id: int}>
     */
   public static function getConnectedItems(CommonDBTM $item): array {
       global $DB;

       $table = 'glpi_assets_assets_peripheralassets';
      if (!$DB->tableExists($table)) {
          return [];
      }
       $allowed = self::getAllowedItemtypes();
       $connected = [];
      foreach ($DB->request([
          'SELECT' => ['itemtype_peripheral', 'items_id_peripheral'],
          'FROM'   => $table,
          'WHERE'  => [
              'itemtype_asset' => $item->getType(),
              'items_id_asset' => $item->getID(),
              'is_deleted'     => 0,
          ],
          'ORDER'  => ['itemtype_peripheral', 'items_id_peripheral'],
      ]) as $row) {
         if (in_array($row['itemtype_peripheral'], $allowed, true)) {
             $connected[] = ['itemtype' => (string) $row['itemtype_peripheral'], 'items_id' => (int) $row['items_id_peripheral']];
         }
      }
       return $connected;
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL,
                `name` varchar(255) NOT NULL DEFAULT '',
                `type_label` varchar(255) NOT NULL DEFAULT '',
                `manufacturer` varchar(255) NOT NULL DEFAULT '',
                `model` varchar(255) NOT NULL DEFAULT '',
                `serial` varchar(255) NOT NULL DEFAULT '',
                `otherserial` varchar(255) NOT NULL DEFAULT '',
                `is_automatic` tinyint NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_assetsigns_id`,`itemtype`,`items_id`),
                KEY `item` (`itemtype`,`items_id`),
                CONSTRAINT `fk_ai_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
