<?php

namespace GlpiPlugin\Assetsign;

use Change;
use Change_Item;
use CommonDBTM;
use CommonGLPI;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Issue #222 : renouvellement du parc. Liste les materiels dont l'amortissement se termine dans
 * l'horizon choisi (fiche financiere GLPI : mise en service ou achat, duree d'amortissement), avec
 * le budget previsionnel par annee, un export CSV et un ticket de changement groupe.
 *
 * Rien n'est modifie sur les materiels : le changement liste les machines, l'equipe decide. Entree
 * de la rubrique Outils > « Pilotage du parc », visible avec le droit de lecture assetsign.
 */
class Renewal extends CommonGLPI
{
   public static function getTypeName($nb = 0): string {
       return __('Renouvellement du parc', 'assetsign');
   }

   public static function getMenuName(): string {
       return self::getTypeName();
   }

   public static function getIcon(): string {
       return 'ti ti-recycle';
   }

   /**
    * @return array{title: string, page: string, icon: string}|false
    */
   public static function getMenuContent() {
      if (!Session::haveRight(Profile::RIGHT_ASSETSIGN, READ)) {
          return false;
      }
       return [
           'title' => self::getMenuName(),
           'page'  => '/plugins/assetsign/front/renewal.php',
           'icon'  => self::getIcon(),
       ];
   }

   /**
    * Materiels a renouveler d'ici l'horizon, dans les entites actives (ou une entite et ses
    * sous-entites pour la tache planifiee), la fin d'amortissement la plus proche d'abord.
    *
    * @return list<array{itemtype: string, items_id: int, type: string, name: string, user: string,
    *     entities_id: int, start: ?string, end_of_life: string, value: float, url: string}>
    */
   public static function collect(int $horizonMonths, ?int $entitiesId = null): array {
       global $DB;

       $entityIds = $entitiesId === null
           ? (array) ($_SESSION['glpiactiveentities'] ?? [0])
           : array_values(getSonsOf('glpi_entities', $entitiesId));
       $config = Config::getForEntity($entitiesId ?? (int) Session::getActiveEntity());
       $defaultYears = (int) ($config->fields['renewal_default_years'] ?? 4);
       $today = date('Y-m-d');

       $rows = [];
      foreach ($config->getManagedItemtypes() as $itemtype) {
         if (!is_a($itemtype, CommonDBTM::class, true)) {
             continue;
         }
          $table = $itemtype::getTable();
          $where = ["$table.entities_id" => $entityIds];
         foreach (['is_deleted' => 0, 'is_template' => 0] as $field => $value) {
            if ($DB->fieldExists($table, $field)) {
               $where["$table.$field"] = $value;
            }
         }
          $select = ["$table.id", "$table.name", "$table.entities_id", 'glpi_infocoms.buy_date',
              'glpi_infocoms.use_date', 'glpi_infocoms.sink_time', 'glpi_infocoms.value'];
          if ($DB->fieldExists($table, 'users_id')) {
             $select[] = "$table.users_id";
          }
          foreach ($DB->request([
                 'SELECT'     => $select,
                 'FROM'       => $table,
                 'INNER JOIN' => [
                     'glpi_infocoms' => ['ON' => [
                         'glpi_infocoms' => 'items_id',
                         $table          => 'id',
                         ['AND' => ['glpi_infocoms.itemtype' => $itemtype]],
                     ]],
                 ],
                 'WHERE'      => $where,
             ]) as $row
          ) {
             $end = RenewalLogic::endOfLife($row['use_date'], $row['buy_date'], (float) $row['sink_time'], $defaultYears);
             if (!RenewalLogic::isDue($end, $today, $horizonMonths)) {
                continue;
             }
             $rows[] = [
                 'itemtype'    => $itemtype,
                 'items_id'    => (int) $row['id'],
                 'type'        => $itemtype::getTypeName(1),
                 'name'        => (string) ($row['name'] ?: '#' . $row['id']),
                 'user'        => (int) ($row['users_id'] ?? 0) > 0 ? getUserName((int) $row['users_id']) : '',
                 'entities_id' => (int) $row['entities_id'],
                 'start'       => $row['use_date'] ?: $row['buy_date'],
                 'end_of_life' => (string) $end,
                 'value'       => (float) $row['value'],
                 'url'         => $itemtype::getFormURLWithID((int) $row['id']) . '&forcetab=Infocom$1',
             ];
          }
      }
       usort($rows, static fn (array $a, array $b): int => [$a['end_of_life'], $a['name']] <=> [$b['end_of_life'], $b['name']]);
       return $rows;
   }

   /**
    * Ticket de changement groupe : la liste des materiels et le budget, chaque materiel lie au
    * changement. Creation uniquement.
    *
    * @param list<array{itemtype: string, items_id: int, type: string, name: string, user: string,
    *     entities_id: int, start: ?string, end_of_life: string, value: float, url: string}> $rows
    */
   public static function createChange(array $rows, int $horizonMonths, int $entitiesId): int {
      if ($rows === []) {
          return 0;
      }
       $budget = RenewalLogic::budgetByYear($rows);
       $total = array_sum(array_column($budget, 'budget'));
       $content = TemplateRenderer::getInstance()->render('@assetsign/renewal_change.html.twig', [
           'rows'    => $rows,
           'budget'  => $budget,
           'horizon' => $horizonMonths,
       ]);
       $changeId = (int) (new Change())->add([
           'name'        => sprintf(
               __('Renouvellement du parc : %1$d matériel(s), budget estimé %2$s', 'assetsign'),
               count($rows),
               \Html::formatNumber($total) . ' €'
           ),
           'content'     => $content,
           'entities_id' => $entitiesId,
       ]);
      if ($changeId <= 0) {
          return 0;
      }
      foreach ($rows as $row) {
          (new Change_Item())->add(['changes_id' => $changeId, 'itemtype' => $row['itemtype'], 'items_id' => $row['items_id']]);
      }
       return $changeId;
   }

   /**
    * CSV (separateur « ; », comme les exports de GLPI) pour le budget.
    *
    * @param list<array{type: string, name: string, user: string, start: ?string, end_of_life: string, value: float}> $rows
    */
   public static function toCsv(array $rows): string {
       $out = fopen('php://temp', 'r+');
       fputcsv($out, [
           __('Type', 'assetsign'), __('Matériel', 'assetsign'), __('Utilisateur', 'assetsign'),
           __('Mise en service', 'assetsign'), __('Fin d\'amortissement', 'assetsign'), __('Valeur d\'achat', 'assetsign'),
       ], ';', '"', '');
      foreach ($rows as $row) {
          fputcsv($out, [$row['type'], $row['name'], $row['user'], (string) $row['start'], $row['end_of_life'], number_format($row['value'], 2, ',', '')], ';', '"', '');
      }
       rewind($out);
       return "\xEF\xBB\xBF" . stream_get_contents($out);
   }

   /**
    * @return array{description: string}|array{}
    */
   public static function cronInfo(string $name): array {
       return $name === 'assetsignRenewal'
           ? ['description' => __('Renouvellement du parc : un ticket de changement mensuel listant les matériels en fin d\'amortissement (si activé)', 'assetsign')]
           : [];
   }

   /**
    * Pour chaque entite qui l'a active, un changement par mois (horizon 12 mois).
    */
   public static function cronAssetsignRenewal(CronTask $task): int {
       global $DB;

       $count = 0;
       $month = date('Y-m');
      foreach ($DB->request(['FROM' => Config::getTable(), 'WHERE' => ['renewal_monthly_change' => 1]]) as $config) {
         if (substr((string) ($config['renewal_last_change'] ?? ''), 0, 7) === $month) {
             continue;
         }
          $entitiesId = (int) $config['entities_id'];
          $changeId = self::createChange(self::collect(12, $entitiesId), 12, $entitiesId);
          $DB->update(Config::getTable(), ['renewal_last_change' => date('Y-m-d')], ['id' => $config['id']]);
         if ($changeId > 0) {
             $count++;
         }
      }
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }
}
