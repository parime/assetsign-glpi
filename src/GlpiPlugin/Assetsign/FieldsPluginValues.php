<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Plugin;

/**
 * Issue #229 : valeurs des champs du plugin Fields (pluginsGLPI/fields) d'un matériel, pour la
 * fiche PDF et les variables {champ:nom} du texte du gabarit.
 *
 * Opt-in par bloc : la fiche est remise au bénéficiaire, et un bloc Fields peut porter des
 * informations internes (prix d'achat, code analytique). Seuls les blocs cochés dans la
 * configuration de l'entité (Config::fields_containers) sont lus. Sans le plugin Fields, ou sans
 * bloc coché, rien n'est lu ni affiché et aucune erreur n'est levée.
 *
 * Lecture directe des tables de Fields (définitions dans glpi_plugin_fields_containers/_fields, une
 * table de valeurs par bloc et type d'élément, nommée par PluginFieldsContainer::getClassname()),
 * comme le fait Fields lui-même pour afficher ses blocs.
 */
final class FieldsPluginValues
{
   private const CONTAINERS_TABLE = 'glpi_plugin_fields_containers';
   private const FIELDS_TABLE     = 'glpi_plugin_fields_fields';

   public static function isAvailable(): bool {
       global $DB;

       return Plugin::isPluginActive('fields')
           && class_exists('PluginFieldsContainer')
           && method_exists('PluginFieldsContainer', 'getClassname')
           && $DB->tableExists(self::CONTAINERS_TABLE)
           && $DB->tableExists(self::FIELDS_TABLE);
   }

    /**
     * Blocs actifs de l'instance, pour le choix dans la configuration.
     *
     * Chaque bloc liste ses champs (hors titres) avec la variable {champ:nom} à copier dans le
     * texte du gabarit : le nom système d'un champ Fields (ex. « bondecommandefield ») ne se devine
     * pas.
     *
     * @return list<array{id: int, label: string, itemtypes: list<string>,
     *     fields: list<array{label: string, placeholder: string}>, itemtype_labels: list<string>}>
     */
   public static function getContainers(): array {
       global $DB;

      if (!self::isAvailable()) {
          return [];
      }
       $containers = [];
      foreach ($DB->request(['FROM' => self::CONTAINERS_TABLE, 'WHERE' => ['is_active' => 1], 'ORDER' => 'label']) as $row) {
          $itemtypes = json_decode((string) $row['itemtypes'], true);
          $fields = [];
         foreach ($DB->request([
             'FROM'  => self::FIELDS_TABLE,
             'WHERE' => ['plugin_fields_containers_id' => $row['id'], 'is_active' => 1, 'NOT' => ['type' => 'header']],
             'ORDER' => 'ranking',
         ]) as $field) {
             $fields[] = [
                 'label'       => self::labelFor($field),
                 'placeholder' => '{' . FieldsPluginLogic::placeholderKey((string) $field['name']) . '}',
             ];
         }
          $containers[] = [
              'id'        => (int) $row['id'],
              'label'     => (string) $row['label'],
              'itemtypes' => is_array($itemtypes) ? array_values(array_map('strval', $itemtypes)) : [],
              'fields'    => $fields,
              // Libelles traduits des types (« Ordinateurs » plutot que « Computer »).
              'itemtype_labels' => array_map(
                  static fn ($itemtype): string => is_a((string) $itemtype, \CommonGLPI::class, true)
                      ? (string) $itemtype::getTypeName(2)
                      : (string) $itemtype,
                  is_array($itemtypes) ? array_values($itemtypes) : []
              ),
          ];
      }
       return $containers;
   }

    /**
     * @return list<int>
     */
   public static function getContainerIds(): array {
       return array_column(self::getContainers(), 'id');
   }

    /**
     * Valeurs renseignées des blocs retenus pour ce matériel, dans l'ordre des blocs puis des champs.
     *
     * @param list<int> $containerIds blocs cochés dans la configuration
     * @return list<array{name: string, label: string, value: string}>
     */
   public static function getValues(string $itemtype, int $itemsId, array $containerIds): array {
       global $DB;

      if ($containerIds === [] || $itemsId <= 0 || !self::isAvailable()) {
          return [];
      }
       $values = [];
      foreach (self::getContainers() as $container) {
         if (!in_array($container['id'], $containerIds, true) || !in_array($itemtype, $container['itemtypes'], true)) {
             continue;
         }
          $row = self::getValueRow($itemtype, $itemsId, $container['id']);
         if ($row === null) {
             continue;
         }
         foreach ($DB->request([
             'FROM'  => self::FIELDS_TABLE,
             'WHERE' => ['plugin_fields_containers_id' => $container['id'], 'is_active' => 1],
             'ORDER' => 'ranking',
         ]) as $field) {
             $value = self::formatValue($field, $row);
            if ($value !== '') {
                $values[] = ['name' => (string) $field['name'], 'label' => self::labelFor($field), 'value' => $value];
            }
         }
      }
       return $values;
   }

    /**
     * @return array<string, mixed>|null
     */
   private static function getValueRow(string $itemtype, int $itemsId, int $containersId): ?array {
       global $DB;

       $container = $DB->request(['FROM' => self::CONTAINERS_TABLE, 'WHERE' => ['id' => $containersId]])->current();
      if (!$container) {
          return null;
      }
       $classname = \PluginFieldsContainer::getClassname($itemtype, (string) $container['name']);
       $table = getTableForItemType($classname);
      if (!$DB->tableExists($table)) {
          return null;
      }
       $row = $DB->request([
           'FROM'  => $table,
           'WHERE' => ['plugin_fields_containers_id' => $containersId, 'items_id' => $itemsId],
           'LIMIT' => 1,
       ])->current();
       return $row ?: null;
   }

    /**
     * @param array<string, mixed> $field
     */
   private static function labelFor(array $field): string {
      if (class_exists('PluginFieldsLabelTranslation') && method_exists('PluginFieldsLabelTranslation', 'getLabelFor')) {
          return (string) \PluginFieldsLabelTranslation::getLabelFor($field);
      }
       return (string) $field['label'];
   }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $row
     */
   private static function formatValue(array $field, array $row): string {
       $type = (string) $field['type'];
       $name = (string) $field['name'];

      if ($type === 'header') {
          return '';
      }
      if ($type === 'dropdown') {
          $table = 'glpi_plugin_fields_' . $name . 'dropdowns';
          return self::dropdownNames($table, $row['plugin_fields_' . $name . 'dropdowns_id'] ?? null, (bool) $field['multiple']);
      }
      if (preg_match('/^dropdown-(.+)$/', $type, $matches) === 1) {
          $class = $matches[1];
         if (!class_exists($class) || !is_a($class, CommonDBTM::class, true)) {
              return '';
         }
          return self::dropdownNames($class::getTable(), $row[$name] ?? null, (bool) $field['multiple']);
      }
      if ($type === 'glpi_item') {
          $itemtype = (string) ($row['itemtype_' . $name] ?? '');
          $item = $itemtype !== '' && is_a($itemtype, CommonDBTM::class, true) ? new $itemtype() : null;
          return $item !== null && $item->getFromDB((int) ($row['items_id_' . $name] ?? 0)) ? (string) $item->getName() : '';
      }
       return (string) FieldsPluginLogic::formatScalar($type, $row[$name] ?? null, [
           'yes' => __('Oui', 'assetsign'),
           'no'  => __('Non', 'assetsign'),
       ]);
   }

   private static function dropdownNames(string $table, mixed $raw, bool $multiple): string {
       $ids = $multiple ? json_decode((string) $raw, true) : [$raw];
      if (!is_array($ids)) {
          return '';
      }
       $names = [];
      foreach ($ids as $id) {
         if (is_numeric($id) && (int) $id > 0) {
             $names[] = (string) \Dropdown::getDropdownName($table, (int) $id);
         }
      }
       return implode(', ', array_filter($names, static fn (string $name): bool => $name !== '' && $name !== '&nbsp;'));
   }
}
