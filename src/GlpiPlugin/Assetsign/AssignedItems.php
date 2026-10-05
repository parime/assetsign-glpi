<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;

/**
 * Matériels gérés par assetsign actuellement affectés à une personne (champ natif `users_id`
 * de chaque type géré par l'entité). Partagé par le départ d'un salarié (issue #157, Departure)
 * et l'attestation annuelle de détention (issue #158, Campaign/Attestation).
 */
final class AssignedItems
{
   /**
    * @return list<CommonDBTM>
    */
   public static function find(int $usersId, int $entitiesId): array {
       global $DB;

       $items = [];
      foreach (Config::getForEntity($entitiesId)->getManagedItemtypes() as $itemtype) {
         if (!is_subclass_of($itemtype, CommonDBTM::class)) {
             continue;
         }
          $table = $itemtype::getTable();
         if (!$DB->fieldExists($table, 'users_id')) {
             continue;
         }
          $where = ['users_id' => $usersId];
         foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($table, $flag)) {
               $where[$flag] = 0;
            }
         }
         foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
             $item = new $itemtype();
            if ($item->getFromDB((int) $row['id'])) {
                $items[] = $item;
            }
         }
      }

       return $items;
   }

   /**
    * Instantané affichable/archivable d'un matériel (liste figée d'une attestation, PDF).
    *
    * @return array{itemtype: string, items_id: int, type_label: string, name: string, serial: string, otherserial: string}
    */
   public static function snapshot(CommonDBTM $item): array {
       return [
           'itemtype'    => $item->getType(),
           'items_id'    => $item->getID(),
           'type_label'  => Assetsign::getCanonicalItemtypeLabel($item->getType()),
           'name'        => (string) ($item->fields['name'] ?? ('#' . $item->getID())),
           'serial'      => (string) ($item->fields['serial'] ?? ''),
           'otherserial' => (string) ($item->fields['otherserial'] ?? ''),
       ];
   }
}
