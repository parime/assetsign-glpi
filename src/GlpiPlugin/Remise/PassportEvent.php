<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Migration;

/**
 * Socle du "Passeport materiel" (cf. ROADMAP.md, "Vision produit a long terme") : une
 * ligne = un evenement metier immuable sur un materiel gere par le plugin (attribution,
 * restitution, don, vente, maintenance). Remise/Maintenance restent les seules sources de
 * verite pour leur propre workflow (PDF, preuve de signature...) — cette classe ne fait
 * qu'agreger, jamais dupliquer, via recordForRemise()/recordForMaintenance() appelees
 * depuis ces classes au moment ou l'evenement a reellement lieu.
 */
class PassportEvent extends CommonDBTM
{
   public const TYPE_ATTRIBUTION = 0;
   public const TYPE_RETURN      = 1;
   public const TYPE_DON         = 2;
   public const TYPE_VENTE       = 3;
   public const TYPE_MAINTENANCE = 4;

   public static $rightname = Profile::RIGHT_REMISE;

    /**
     * Nom de table force explicitement : la derivation automatique de GLPI a partir du nom
     * de classe n'insere pas de underscore aux frontieres de casse interne (deja rencontre
     * et documente ailleurs dans ce plugin) — autant ne jamais s'y fier.
     */
   public static function getTable($classname = null): string {
       return 'glpi_plugin_remise_events';
   }

   public static function getTypeName($nb = 0): string {
       return _n('Événement du passeport matériel', 'Événements du passeport matériel', $nb, 'remise');
   }

   public static function getIcon(): string {
       return 'ti ti-timeline';
   }

    /** @return array<int, string> Libelle de chaque type d'evenement, par constante TYPE_*. */
   public static function getTypeLabels(): array {
       return [
           self::TYPE_ATTRIBUTION => __('Attribution', 'remise'),
           self::TYPE_RETURN      => __('Restitution', 'remise'),
           self::TYPE_DON         => __('Don', 'remise'),
           self::TYPE_VENTE       => __('Vente', 'remise'),
           self::TYPE_MAINTENANCE => __('Maintenance', 'remise'),
       ];
   }

    /**
     * Enregistre l'evenement correspondant a une Remise (attribution, restitution, don ou
     * vente) — appelee depuis Remise::launchWorkflow(), point de passage commun a toutes
     * les voies de creation (automatique par affectation/Etat, manuelle Don/Vente). Le
     * snapshot beneficiaire est resolu via Remise::getBeneficiary(), deja unifie pour un
     * beneficiaire interne (compte GLPI) ou externe (texte libre) — cf. son propre
     * commentaire, meme forme de tableau dans les deux cas.
     */
   public static function recordForRemise(Remise $remise): void {
       $typeMap = [
           Remise::TYPE_HANDOVER => self::TYPE_ATTRIBUTION,
           Remise::TYPE_RETURN   => self::TYPE_RETURN,
           Remise::TYPE_DON      => self::TYPE_DON,
           Remise::TYPE_VENTE    => self::TYPE_VENTE,
       ];
       $eventType = $typeMap[(int) $remise->fields['type']] ?? null;
       if ($eventType === null) {
          return;
       }

       $entitiesId = (int) $remise->fields['entities_id'];
       if (!Config::getForEntity($entitiesId)->fields['enable_passport']) {
          return;
       }

       $beneficiary = $remise->getBeneficiary();
       $isExternal = (int) ($remise->fields['beneficiary_type'] ?? Remise::BENEFICIARY_INTERNAL) === Remise::BENEFICIARY_EXTERNAL;

       (new self())->add([
           'itemtype'             => $remise->fields['itemtype'],
           'items_id'             => $remise->fields['items_id'],
           'entities_id'          => $entitiesId,
           'event_type'           => $eventType,
           'source_itemtype'      => Remise::class,
           'source_items_id'      => $remise->getID(),
           'date'                 => $remise->fields['date_creation'] ?: date('Y-m-d H:i:s'),
           'users_id'             => $isExternal ? 0 : (int) $remise->fields['users_id'],
           'snapshot_name'        => trim(($beneficiary['firstname'] ?? '') . ' ' . ($beneficiary['realname'] ?? '')),
           'snapshot_email'       => (string) ($beneficiary['email'] ?? ''),
           'snapshot_is_external' => $isExternal ? 1 : 0,
           'snapshot_entity_name' => \Dropdown::getDropdownName('glpi_entities', $entitiesId),
       ]);
   }

    /**
     * Enregistre l'evenement correspondant a une fiche de maintenance — appelee depuis
     * Maintenance::createWithChecklist(), une fois la fiche reellement creee. Pas de
     * snapshot utilisateur ici : une maintenance ne change pas le detenteur du materiel,
     * hors du perimetre RGPD que le snapshot beneficiaire adresse.
     */
   public static function recordForMaintenance(Maintenance $maintenance): void {
      if (!Config::getForEntity((int) $maintenance->fields['entities_id'])->fields['enable_passport']) {
          return;
      }

       (new self())->add([
           'itemtype'        => $maintenance->fields['itemtype'],
           'items_id'        => $maintenance->fields['items_id'],
           'entities_id'     => (int) $maintenance->fields['entities_id'],
           'event_type'      => self::TYPE_MAINTENANCE,
           'source_itemtype' => Maintenance::class,
           'source_items_id' => $maintenance->getID(),
           'date'            => $maintenance->fields['date_creation'] ?: date('Y-m-d H:i:s'),
           'comment'         => $maintenance->fields['comment'] ?? '',
       ]);
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if (!($item instanceof CommonDBTM) || !self::isEnabledForItem($item)) {
          return '';
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
       return self::createTabEntry(__('Passeport matériel', 'remise'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!($item instanceof CommonDBTM) || !self::isEnabledForItem($item)) {
          return false;
      }
       self::showForItem($item);
       return true;
   }

    /** Onglet disponible pour ce type d'item ET la fonctionnalite activee pour son entite. */
   private static function isEnabledForItem(CommonDBTM $item): bool {
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return false;
      }
       return (bool) Config::getForEntity((int) ($item->fields['entities_id'] ?? 0))->fields['enable_passport'];
   }

   public static function showForItem(CommonDBTM $item): void {
       global $DB, $CFG_GLPI;

       $visibleTypes = Config::getForEntity((int) $item->fields['entities_id'])->getPassportVisibleTypes();

       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'event_type' => $visibleTypes],
           'ORDER' => 'date ASC',
       ]));

       $typeLabels = self::getTypeLabels();
      foreach ($rows as &$row) {
          $row['type_label'] = $typeLabels[(int) $row['event_type']] ?? '';
          $row['source_url'] = match ($row['source_itemtype'] ?? null) {
              Remise::class      => $CFG_GLPI['root_doc'] . '/plugins/remise/front/remise.form.php?id=' . $row['source_items_id'],
              Maintenance::class => $CFG_GLPI['root_doc'] . '/plugins/remise/front/maintenance.form.php?id=' . $row['source_items_id'],
              default            => null,
          };
      }
       unset($row);

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/passport_tab.html.twig', [
           'events'   => array_reverse($rows), // le plus recent en premier dans la frise
           'lives'    => self::getLivesForItem($item->getType(), (int) $item->getID(), $rows),
           'itemtype' => $item->getType(),
       ]);
   }

    /**
     * Regroupe les evenements TYPE_ATTRIBUTION consecutifs par users_id : une nouvelle
     * "vie" commence a chaque fois que le beneficiaire change, jamais a chaque evenement
     * (une re-attribution au MEME utilisateur, par exemple apres une maintenance
     * intermediaire, prolonge la vie en cours plutot que d'en ouvrir une nouvelle).
     *
     * @param array|null $rows Evenements deja charges (ordre chronologique croissant),
     *        pour eviter une seconde requete depuis showForItem() — recharges depuis la
     *        base si non fournis (utilise par les tests/appels directs).
     * @return array<int, array{users_id: int, name: string, is_external: bool, start: string, end: ?string}>
     */
   public static function getLivesForItem(string $itemtype, int $items_id, ?array $rows = null): array {
       global $DB;

      if ($rows === null) {
          $rows = iterator_to_array($DB->request([
              'FROM'  => self::getTable(),
              'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
              'ORDER' => 'date ASC',
          ]));
      }

       $attributions = array_values(array_filter($rows, static fn ($r) => (int) $r['event_type'] === self::TYPE_ATTRIBUTION));

       $lives = [];
      foreach ($attributions as $row) {
          $last = end($lives);
         if ($last !== false && $last['users_id'] === (int) $row['users_id']) {
             continue; // meme beneficiaire que la vie en cours : ne prolonge pas une nouvelle entree
         }
         if ($last !== false) {
             $lives[array_key_last($lives)]['end'] = $row['date'];
         }
          $lives[] = [
              'users_id'    => (int) $row['users_id'],
              'name'        => $row['snapshot_name'] ?: __('Utilisateur retiré', 'remise'),
              'is_external' => (bool) $row['snapshot_is_external'],
              'start'       => $row['date'],
              'end'         => null,
          ];
      }

       return $lives;
   }

    /**
     * Vide snapshot_name/snapshot_email des evenements plus anciens que le delai configure
     * (Config::passport_retention_years, par entite ; 0 = jamais). users_id et
     * snapshot_entity_name ne sont JAMAIS effaces : le premier n'est qu'un entier sans
     * donnee personnelle en soi (necessaire pour que getLivesForItem() continue de
     * regrouper correctement les vies apres anonymisation), le second n'est pas une
     * donnee personnelle.
     * @return int Nombre de lignes anonymisees.
     */
   public static function anonymizeOldSnapshots(): int {
       global $DB;

       $count = 0;
       $entities = iterator_to_array($DB->request(['FROM' => 'glpi_entities']));
      foreach ($entities as $entity) {
          $retentionYears = (int) Config::getForEntity((int) $entity['id'])->fields['passport_retention_years'];
         if ($retentionYears <= 0) {
             continue;
         }
          $threshold = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));

          $DB->update(self::getTable(), [
              'snapshot_name'  => '',
              'snapshot_email' => '',
              'is_anonymized'  => 1,
          ], [
              'entities_id'   => (int) $entity['id'],
              'is_anonymized' => 0,
              'snapshot_name' => ['<>', ''],
              'date'          => ['<', $threshold],
          ]);
          // update() renvoie toujours true en cas de succes (pas le nombre de lignes,
          // cf. src/DBmysql.php du coeur) : affectedRows() juste apres est la seule
          // facon fiable de savoir combien de lignes ont reellement ete touchees.
          $count += $DB->affectedRows();
      }

       return $count;
   }

   public static function cronPassportAnonymize(CronTask $task): int {
       $count = self::anonymizeOldSnapshots();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `event_type` tinyint NOT NULL,
                `source_itemtype` varchar(100) DEFAULT NULL,
                `source_items_id` int unsigned NOT NULL DEFAULT 0,
                `date` timestamp NULL DEFAULT NULL,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `snapshot_name` varchar(255) NOT NULL DEFAULT '',
                `snapshot_email` varchar(255) NOT NULL DEFAULT '',
                `snapshot_is_external` tinyint NOT NULL DEFAULT 0,
                `snapshot_entity_name` varchar(255) NOT NULL DEFAULT '',
                `comment` text,
                `is_anonymized` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `entities_id` (`entities_id`),
                KEY `users_id` (`users_id`),
                KEY `date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
