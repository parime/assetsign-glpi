<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Migration;

/**
 * Socle du "Passeport materiel" (cf. ROADMAP.md, "Vision produit a long terme") : une
 * ligne = un evenement metier immuable sur un materiel gere par le plugin (attribution,
 * restitution, don, vente, maintenance). Assetsign/Maintenance restent les seules sources de
 * verite pour leur propre workflow (PDF, preuve de signature...) — cette classe ne fait
 * qu'agreger, jamais dupliquer, via recordForAssetsign()/recordForMaintenance() appelees
 * depuis ces classes au moment ou l'evenement a reellement lieu.
 */
class PassportEvent extends CommonDBTM
{
   public const TYPE_ATTRIBUTION = 0;
   public const TYPE_RETURN      = 1;
   public const TYPE_DON         = 2;
   public const TYPE_VENTE       = 3;
   public const TYPE_MAINTENANCE = 4;

   public static $rightname = Profile::RIGHT_ASSETSIGN;

    /**
     * Nom de table force explicitement : la derivation automatique de GLPI a partir du nom
     * de classe n'insere pas de underscore aux frontieres de casse interne (deja rencontre
     * et documente ailleurs dans ce plugin) — autant ne jamais s'y fier.
     */
   public static function getTable($classname = null): string {
       return 'glpi_plugin_assetsign_events';
   }

   public static function getTypeName($nb = 0): string {
       return _n('Événement du passeport matériel', 'Événements du passeport matériel', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-timeline';
   }

    /** @return array<int, string> Libelle de chaque type d'evenement, par constante TYPE_*. */
   public static function getTypeLabels(): array {
       return [
           self::TYPE_ATTRIBUTION => __('Attribution', 'assetsign'),
           self::TYPE_RETURN      => __('Restitution', 'assetsign'),
           self::TYPE_DON         => __('Don', 'assetsign'),
           self::TYPE_VENTE       => __('Vente', 'assetsign'),
           self::TYPE_MAINTENANCE => __('Maintenance', 'assetsign'),
       ];
   }

    /**
     * Enregistre l'evenement correspondant a une Assetsign (attribution, restitution, don ou
     * vente) — appelee depuis Assetsign::launchWorkflow(), point de passage commun a toutes
     * les voies de creation (automatique par affectation/Etat, manuelle Don/Vente). Le
     * snapshot beneficiaire est resolu via Assetsign::getBeneficiary(), deja unifie pour un
     * beneficiaire interne (compte GLPI) ou externe (texte libre) — cf. son propre
     * commentaire, meme forme de tableau dans les deux cas.
     */
   public static function recordForAssetsign(Assetsign $assetsign): void {
       $typeMap = [
           Assetsign::TYPE_HANDOVER => self::TYPE_ATTRIBUTION,
           Assetsign::TYPE_RETURN   => self::TYPE_RETURN,
           Assetsign::TYPE_DON      => self::TYPE_DON,
           Assetsign::TYPE_VENTE    => self::TYPE_VENTE,
       ];
       $eventType = $typeMap[(int) $assetsign->fields['type']] ?? null;
       if ($eventType === null) {
          return;
       }

       $entitiesId = (int) $assetsign->fields['entities_id'];
       if (!Config::getForEntity($entitiesId)->fields['enable_passport']) {
          return;
       }

       $beneficiary = $assetsign->getBeneficiary();
       $isExternal = (int) ($assetsign->fields['beneficiary_type'] ?? Assetsign::BENEFICIARY_INTERNAL) === Assetsign::BENEFICIARY_EXTERNAL;

       (new self())->add([
           'itemtype'             => $assetsign->fields['itemtype'],
           'items_id'             => $assetsign->fields['items_id'],
           'entities_id'          => $entitiesId,
           'event_type'           => $eventType,
           'source_itemtype'      => Assetsign::class,
           'source_items_id'      => $assetsign->getID(),
           'date'                 => $assetsign->fields['date_creation'] ?: date('Y-m-d H:i:s'),
           'users_id'             => $isExternal ? 0 : (int) $assetsign->fields['users_id'],
           'snapshot_name'        => trim(\formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? '')),
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
      if ($item->getType() === 'User') {
          $count = countElementsInTable(self::getTable(), ['users_id' => $item->getID()]);
          return self::createTabEntry(__('Passeport utilisateur', 'assetsign'), $count);
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
       return self::createTabEntry(__('Passeport matériel', 'assetsign'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!($item instanceof CommonDBTM) || !self::isEnabledForItem($item)) {
          return false;
      }
      if ($item->getType() === 'User') {
          self::showForUser($item);
      } else {
          self::showForItem($item);
      }
       return true;
   }

    /**
     * Onglet disponible pour ce type d'item (materiel gere, OU 'User' pour la
     * vue symetrique cote beneficiaire) ET la fonctionnalite activee pour son
     * entite. Pour 'User', `entities_id` designe l'entite par defaut du
     * compte (colonne native `glpi_users.entities_id`) : simplification
     * assumee pour le MVP, un utilisateur pouvant en pratique relever de
     * plusieurs entites via ses profils.
     */
   private static function isEnabledForItem(CommonDBTM $item): bool {
      if ($item->getType() !== 'User' && !in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return false;
      }
       return (bool) Config::getForEntity((int) ($item->fields['entities_id'] ?? 0))->fields['enable_passport'];
   }

   public static function showForItem(CommonDBTM $item): void {
       global $DB, $CFG_GLPI;

       self::backfillFromLogs($item->getType(), $item->getID());

       $config = Config::getForEntity((int) $item->fields['entities_id']);
       $visibleTypes = $config->getPassportVisibleTypes();

       // $visibleTypes vide (administrateur ayant decoche tous les types dans
       // Configuration > Passeport materiel) : IN() vide fait echouer la requete
       // cote coeur GLPI ("Empty IN are not allowed") - un tableau vide de
       // resultats est le comportement attendu ici, pas une erreur fatale.
       $rows = $visibleTypes === [] ? [] : iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'event_type' => $visibleTypes],
           'ORDER' => 'date ASC',
       ]));

       $typeLabels = self::getTypeLabels();
      foreach ($rows as &$row) {
          $row['type_label'] = $typeLabels[(int) $row['event_type']] ?? '';
          $row['source_url'] = match ($row['source_itemtype'] ?? null) {
              Assetsign::class      => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/assetsign.form.php?id=' . $row['source_items_id'],
              Maintenance::class => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/maintenance.form.php?id=' . $row['source_items_id'],
              default            => null,
          };
      }
       unset($row);
       self::attachChecklistSummaries($rows);

       // getLivesForItem() sur les evenements REELS uniquement : les pseudo-evenements
       // Infocom ci-dessous n'ont pas de event_type/users_id, jamais mélangés à la
       // logique des "vies" - purement un ajout d'affichage sur la frise.
       $lives = self::getLivesForItem($item->getType(), (int) $item->getID(), $rows);

       $timelineRows = array_values($rows);
      if ($config->fields['show_infocom_dates']) {
          $timelineRows = array_merge($timelineRows, self::getInfocomPseudoEvents($item, $config));
      }
      if ($config->fields['show_linked_tickets']) {
          $timelineRows = array_merge($timelineRows, self::getLinkedTicketPseudoEvents($item));
      }
      if ($config->fields['show_infocom_dates'] || $config->fields['show_linked_tickets']) {
          usort($timelineRows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
      }

       $health = $config->fields['enable_health_score'] ? self::getHealthScore($item, $config, $lives) : null;
      if ($health !== null) {
           $health['color'] = $config->getHealthScoreColor($health['score']);
      }

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetsign/passport_tab.html.twig', [
           'events'        => array_reverse($timelineRows), // le plus recent en premier dans la frise
           'lives'         => $lives,
           'identity'      => self::getIdentityCard($item, $lives),
           'health'        => $health,
           'itemtype'      => $item->getType(),
           'items_id'      => $item->getID(),
           'can_backfill'  => \Session::haveRight(self::$rightname, UPDATE),
           'csrf_token'    => \Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Fiche d'identite : vue d'ensemble immediate du materiel sans avoir a
     * derouler toute la frise (cf. ROADMAP.md, tableau V1) — pure agregation
     * de donnees deja natives GLPI (modele, fabricant, n° serie, Etat,
     * utilisateur/entite ACTUELS), aucune nouvelle donnee ni table. Achat et
     * fin de garantie reutilises depuis Infocom (meme lecture que
     * getInfocomPseudoEvents(), sans dependre de Config::show_infocom_dates -
     * la fiche d'identite reste utile meme si la frise Infocom est
     * desactivee). Champ de modele et classe deduits par convention GLPI
     * (`<itemtype minuscule>models_id`, ex: `computermodels_id` sur
     * `glpi_computers` - PAS un generique `models_id` ; `<Itemtype>Model`,
     * ex: ComputerModel) - absents pour certains types personnalises, geres
     * en repli (chaine vide, jamais une erreur). Indicateurs temporels (cf.
     * ROADMAP.md, V2) calcules ici aussi : age physique (depuis l'achat
     * Infocom si connu, sinon depuis l'entree dans GLPI - jamais une valeur
     * inventee, le libelle precise toujours la source reelle), temps
     * reellement utilise (somme des "vies" deja calculees par
     * getLivesForItem(), jamais une deuxieme requete), temps en stock (le
     * reste : age moins temps utilise, jamais negatif).
     * @return array{name: string, serial: string, model: string, manufacturer: string, state: string, user: string, entity: string, buy_date: ?string, warranty_end: ?string, age: ?string, used_duration: ?string, used_percent: ?int, stock_duration: ?string}
     */
   private static function getIdentityCard(CommonDBTM $item, array $lives): array {
       $modelClass = $item->getType() . 'Model';
       $modelField = strtolower($item->getType()) . 'models_id';
       $modelName = '';
      if (!empty($item->fields[$modelField]) && is_subclass_of($modelClass, CommonDBTM::class)) {
          $modelName = \Dropdown::getDropdownName($modelClass::getTable(), (int) $item->fields[$modelField]);
      }

       $buyDate = null;
       $warrantyEnd = null;
       $infocom = new \Infocom();
      if (\Infocom::canApplyOn($item->getType()) && $infocom->getFromDBforDevice($item->getType(), $item->getID())) {
          $buyDate = $infocom->fields['buy_date'] ?: null;
          $warrantyMonths = (int) ($infocom->fields['warranty_duration'] ?? 0);
         if (!empty($infocom->fields['warranty_date']) && $warrantyMonths > 0) {
             $warrantyEnd = date('Y-m-d', strtotime($infocom->fields['warranty_date'] . " +{$warrantyMonths} months"));
         }
      }

       $ageStart = $buyDate ?: ($item->fields['date_creation'] ?? null);
       $ageSourceLabel = $buyDate ? __('depuis l\'achat', 'assetsign') : __('depuis l\'entrée dans GLPI', 'assetsign');

       $age = null;
       $usedDuration = null;
       $usedPercent = null;
       $stockDuration = null;
      if (!empty($ageStart)) {
          $ageDays = max(0, (int) floor((time() - strtotime($ageStart)) / DAY_TIMESTAMP));
          $age = sprintf('%s (%s)', self::formatDurationYearsMonths($ageDays), $ageSourceLabel);

           $usedDays = array_sum(array_column($lives, 'duration_days'));
         if ($usedDays > 0) {
             $usedDuration = self::formatDurationYearsMonths($usedDays);
             $usedPercent = $ageDays > 0 ? (int) round(min(100, $usedDays / $ageDays * 100)) : null;
         }

           $stockDays = max(0, $ageDays - $usedDays);
         if ($stockDays > 0) {
             $stockDuration = self::formatDurationYearsMonths($stockDays);
         }
      }

       return [
           'name'           => (string) ($item->fields['name'] ?? ''),
           'serial'         => (string) ($item->fields['serial'] ?? ''),
           'model'          => $modelName,
           'manufacturer'   => empty($item->fields['manufacturers_id']) ? '' : \Dropdown::getDropdownName('glpi_manufacturers', (int) $item->fields['manufacturers_id']),
           'state'          => empty($item->fields['states_id']) ? '' : \Dropdown::getDropdownName('glpi_states', (int) $item->fields['states_id']),
           'user'           => empty($item->fields['users_id']) ? '' : \User::getFriendlyNameById((int) $item->fields['users_id']),
           'entity'         => \Dropdown::getDropdownName('glpi_entities', (int) ($item->fields['entities_id'] ?? 0)),
           'buy_date'       => $buyDate,
           'warranty_end'   => $warrantyEnd,
           'age'            => $age,
           'used_duration'  => $usedDuration,
           'used_percent'   => $usedPercent,
           'stock_duration' => $stockDuration,
       ];
   }

    /**
     * Score de sante (0-100, 100 = etat ideal), premiere brique du moteur
     * d'indicateurs V2 (cf. ROADMAP.md — methodologie et sources documentees
     * dans ROADMAP.md, section dediee). Formule standard du secteur ITAM :
     * `100 - Σ(poids_i × degradation_i)`, chaque degradation_i normalisee sur
     * 0-100 puis ponderee par un poids RELATIF (pas force a sommer a 100 :
     * chaque poids compte proportionnellement au total des poids actifs,
     * plus simple a regler pour un administrateur qu'un partage exact de
     * 100%). Quatre facteurs retenus, sur les six suggeres a l'origine par la
     * roadmap - "controles" (checklists) et "batterie" volontairement omis,
     * aucune donnee fiable disponible dans ce plugin/GLPI pour les alimenter :
     * - Age : degradation lineaire jusqu'a 5 ans (seuil fixe, pas encore
     *   ajustable - seul le POIDS du facteur l'est pour l'instant).
     * - Incidents : nombre BRUT de tickets lies (tous statuts confondus,
     *   jamais filtre par droit ici - un simple compteur agrege dans un
     *   score n'expose aucun contenu de ticket, contrairement a
     *   getLinkedTicketPseudoEvents() qui, elle, doit rester filtree).
     * - Etat physique : marqueurs de degat de l'etat des lieux visuel
     *   (glpi_plugin_assetsign_damagemarkers), un mineur = 1 point, un majeur =
     *   2 points.
     * - Mouvements : nombre de "vies" (changements de detenteur) - une
     *   rotation frequente use davantage un materiel qu'une affectation stable.
     * @return array{score: int, breakdown: array<string, array{label: string, degradation: int, weight: int}>}|null null si aucun poids actif (tous a 0) ou aucun facteur calculable.
     */
   private static function getHealthScore(CommonDBTM $item, Config $config, array $lives): ?array {
       global $DB;

       $weights = [
           'age'        => max(0, (int) $config->fields['health_weight_age']),
           'incidents'  => max(0, (int) $config->fields['health_weight_incidents']),
           'damage'     => max(0, (int) $config->fields['health_weight_damage']),
           'movements'  => max(0, (int) $config->fields['health_weight_movements']),
       ];
       $totalWeight = array_sum($weights);
       if ($totalWeight <= 0) {
          return null; // Administrateur ayant mis tous les poids a 0 : le score n'a plus de sens.
       }

       $degradations = [];

       // Age : degradation lineaire jusqu'a 5 ans (constante PLUGIN_ASSETSIGN_HEALTH_AGE_FULL_DEGRADATION_DAYS).
       $ageStart = null;
       $infocom = new \Infocom();
       if (\Infocom::canApplyOn($item->getType()) && $infocom->getFromDBforDevice($item->getType(), $item->getID()) && !empty($infocom->fields['buy_date'])) {
          $ageStart = $infocom->fields['buy_date'];
       } else if (!empty($item->fields['date_creation'])) {
          $ageStart = $item->fields['date_creation'];
       }
       if ($ageStart !== null) {
          $ageDays = max(0, (int) floor((time() - strtotime($ageStart)) / DAY_TIMESTAMP));
          $degradations['age'] = ['label' => __('Âge', 'assetsign'), 'degradation' => min(100, (int) round($ageDays / PLUGIN_ASSETSIGN_HEALTH_AGE_FULL_DEGRADATION_DAYS * 100))];
       }

       // Incidents : compteur brut (tous statuts), volontairement PAS filtre par
       // droit (cf. docblock) - jamais de contenu de ticket expose ici.
       $ticketCount = (int) $DB->request([
           'COUNT'  => 'c',
           'FROM'   => 'glpi_items_tickets',
           'WHERE'  => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
       ])->current()['c'];
       $degradations['incidents'] = ['label' => __('Incidents', 'assetsign'), 'degradation' => min(100, (int) round($ticketCount / PLUGIN_ASSETSIGN_HEALTH_INCIDENTS_FULL_DEGRADATION_COUNT * 100))];

       // Etat physique : marqueurs de degat, relies a l'item via Assetsign OU Maintenance.
       $damagePoints = (int) $DB->request([
           'SELECT'     => [new \QueryExpression('COALESCE(SUM(`severity` + 1), 0) AS points')],
           'FROM'       => 'glpi_plugin_assetsign_damagemarkers',
           'LEFT JOIN'  => [
               'glpi_plugin_assetsign_assetsigns'      => ['FKEY' => ['glpi_plugin_assetsign_damagemarkers' => 'plugin_assetsign_assetsigns_id', 'glpi_plugin_assetsign_assetsigns' => 'id']],
               'glpi_plugin_assetsign_maintenances'  => ['FKEY' => ['glpi_plugin_assetsign_damagemarkers' => 'plugin_assetsign_maintenances_id', 'glpi_plugin_assetsign_maintenances' => 'id']],
           ],
           'WHERE'      => [
               'OR' => [
                   ['glpi_plugin_assetsign_assetsigns.itemtype' => $item->getType(), 'glpi_plugin_assetsign_assetsigns.items_id' => $item->getID()],
                   ['glpi_plugin_assetsign_maintenances.itemtype' => $item->getType(), 'glpi_plugin_assetsign_maintenances.items_id' => $item->getID()],
               ],
           ],
       ])->current()['points'];
       $degradations['damage'] = ['label' => __('État physique', 'assetsign'), 'degradation' => min(100, (int) round($damagePoints / PLUGIN_ASSETSIGN_HEALTH_DAMAGE_FULL_DEGRADATION_POINTS * 100))];

       // Mouvements : nombre de "vies" deja calculees, aucune requete supplementaire.
       $movementCount = count($lives);
       $degradations['movements'] = ['label' => __('Mouvements', 'assetsign'), 'degradation' => min(100, (int) round($movementCount / PLUGIN_ASSETSIGN_HEALTH_MOVEMENTS_FULL_DEGRADATION_COUNT * 100))];

       $weightedDegradation = 0.0;
       $breakdown = [];
      foreach ($degradations as $key => $data) {
          $breakdown[$key] = ['label' => $data['label'], 'degradation' => $data['degradation'], 'weight' => $weights[$key]];
         if ($weights[$key] > 0) {
             $weightedDegradation += $data['degradation'] * ($weights[$key] / $totalWeight);
         }
      }

       $score = max(0, min(100, (int) round(100 - $weightedDegradation)));

       return ['score' => $score, 'breakdown' => $breakdown];
   }

    /**
     * Dates Infocom du materiel (achat, commande, livraison, mise en service,
     * garantie, reforme), fusionnees dans la frise du Passeport materiel SANS
     * jamais etre copiees dans glpi_plugin_assetsign_events - repond a "que
     * s'est-il passe avant meme la premiere attribution ?" (cf. ROADMAP.md).
     * Pseudo-evenements au meme format que les lignes reelles (`type_label`,
     * `date`, `source_url` toujours null - aucune fiche a afficher), pour que
     * passport_tab.html.twig les affiche sans aucune logique conditionnelle
     * supplementaire. `event_type` volontairement absent (jamais un entier,
     * meme pas -1) : getLivesForItem() ne doit jamais pouvoir les confondre
     * avec un evenement reel, meme par erreur de comparaison de type.
     * @return list<array{type_label: string, date: string, source_url: null, snapshot_name: string, is_anonymized: int, comment: string}>
     */
   private static function getInfocomPseudoEvents(CommonDBTM $item, Config $config): array {
      if (!\Infocom::canApplyOn($item->getType())) {
          return []; // Itemtype absent de $CFG_GLPI['infocom_types'] (reglage coeur GLPI) : Infocom n'existe meme pas pour ce type de materiel.
      }
       $infocom = new \Infocom();
      if (!$infocom->getFromDBforDevice($item->getType(), $item->getID())) {
          return [];
      }

       $dateFields = [
           'order_date'    => __('Commande', 'assetsign'),
           'delivery_date' => __('Livraison', 'assetsign'),
           'buy_date'      => __('Achat', 'assetsign'),
           'use_date'      => __('Mise en service', 'assetsign'),
           'warranty_date' => __('Début de garantie', 'assetsign'),
           'decommission_date' => __('Réforme', 'assetsign'),
       ];

       $events = [];
       foreach ($dateFields as $field => $label) {
          $date = $infocom->fields[$field] ?? null;
          if (empty($date)) {
             continue;
          }
          $events[] = [
              'type_label'    => $label,
              'date'          => substr((string) $date, 0, 10) . ' 00:00:00', // colonne DATE (pas DATETIME) : heure neutre pour trier/afficher comme les evenements reels.
              'source_url'    => null,
              'snapshot_name' => '',
              'is_anonymized' => 0,
              'comment'       => $field === 'buy_date' && (float) $infocom->fields['value'] > 0
                  // Meme formatage que templates/pdf/handover.html.twig (number_format
                  // Twig) : 2 decimales, virgule, espace des milliers, symbole configurable.
                  ? sprintf(
                      __('Prix d\'achat : %s %s', 'assetsign'),
                      number_format((float) $infocom->fields['value'], 2, ',', ' '),
                      $config->fields['currency_symbol'] ?: '€'
                  )
                  : '',
          ];
       }

       $warrantyMonths = (int) ($infocom->fields['warranty_duration'] ?? 0);
       if (!empty($infocom->fields['warranty_date']) && $warrantyMonths > 0) {
          $events[] = [
              'type_label'    => __('Fin de garantie', 'assetsign'),
              'date'          => date('Y-m-d', strtotime($infocom->fields['warranty_date'] . " +{$warrantyMonths} months")) . ' 00:00:00',
              'source_url'    => null,
              'snapshot_name' => '',
              'is_anonymized' => 0,
              'comment'       => '',
          ];
       }

       return $events;
   }

    /**
     * Tickets lies au materiel, fusionnes dans la frise EN LECTURE SEULE
     * (cf. ROADMAP.md, tableau V1) : contrairement a Assetsign/Maintenance, qui
     * ecrivent dans glpi_plugin_assetsign_events, un Ticket n'est JAMAIS copie
     * ni stocke ici - juste lu a l'affichage, comme les dates Infocom.
     * Respecte les droits reels du lecteur courant sur chaque ticket
     * (`Ticket::can(..., READ)`, jamais un simple droit generique) : deux
     * personnes consultant le meme Passeport materiel peuvent donc voir un
     * nombre de tickets different, exactement comme si elles consultaient
     * directement l'onglet Ticket du materiel.
     * @return list<array{type_label: string, date: string, source_url: string, snapshot_name: string, is_anonymized: int, comment: string}>
     */
   private static function getLinkedTicketPseudoEvents(CommonDBTM $item): array {
       global $DB;

      if (!\Session::haveRight('ticket', READ)) {
          return [];
      }

       $rows = iterator_to_array($DB->request([
           'SELECT'     => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.date', 'glpi_tickets.status'],
           'FROM'       => 'glpi_items_tickets',
           'INNER JOIN' => [
               'glpi_tickets' => ['FKEY' => ['glpi_items_tickets' => 'tickets_id', 'glpi_tickets' => 'id']],
           ],
           'WHERE'      => ['glpi_items_tickets.itemtype' => $item->getType(), 'glpi_items_tickets.items_id' => $item->getID()],
       ]));

       $statusLabels = \Ticket::getAllStatusArray();
       $events = [];
      foreach ($rows as $row) {
          $ticket = new \Ticket();
         if (!$ticket->getFromDB((int) $row['id']) || !$ticket->can((int) $row['id'], READ)) {
             continue; // Droit reel sur CE ticket precis, jamais suppose depuis le droit generique verifie plus haut.
         }
          $events[] = [
              'type_label'    => __('Ticket', 'assetsign'),
              'date'          => (string) $row['date'],
              'source_url'    => $ticket->getLinkURL(),
              'snapshot_name' => (string) $row['name'],
              'is_anonymized' => 0,
              'comment'       => $statusLabels[(int) $row['status']] ?? '',
          ];
      }

       return $events;
   }

    /**
     * Onglet "Passeport utilisateur" cote beneficiaire (fiche Administration >
     * Utilisateurs) : agrege les memes evenements que showForItem(), mais
     * filtres par users_id (le beneficiaire) plutot que par itemtype/items_id —
     * une meme personne ayant pu recevoir/rendre/acheter plusieurs materiels
     * differents dans le temps. Les evenements de maintenance n'ont jamais de
     * users_id renseigne (pas de notion de beneficiaire), ils sont donc deja
     * naturellement absents de cette frise, sans filtre explicite necessaire.
     */
   public static function showForUser(CommonDBTM $item): void {
       global $DB, $CFG_GLPI;

       self::backfillUserHistoryFromLogs($item->getID());

       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['users_id' => $item->getID()],
           'ORDER' => 'date ASC',
       ]));

       $typeLabels = self::getTypeLabels();
      foreach ($rows as &$row) {
          $row['type_label'] = $typeLabels[(int) $row['event_type']] ?? '';
          $row['source_url'] = match ($row['source_itemtype'] ?? null) {
              Assetsign::class      => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/assetsign.form.php?id=' . $row['source_items_id'],
              Maintenance::class => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/maintenance.form.php?id=' . $row['source_items_id'],
              default            => null,
          };
          [$row['device_name'], $row['device_serial']] = self::resolveDeviceDisplay($row['itemtype'], (int) $row['items_id']);
      }
       unset($row);
       self::attachChecklistSummaries($rows);

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetsign/passport_user_tab.html.twig', [
           'events'       => array_reverse($rows), // le plus recent en premier dans la frise
           'account'      => self::getAccountBounds($item),
           'users_id'     => $item->getID(),
           'can_backfill' => \Session::haveRight(self::$rightname, UPDATE),
           'csrf_token'   => \Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Nom + numero de serie d'un materiel arbitraire, pour la frise du
     * Passeport utilisateur. Un materiel peut avoir ete purge depuis, et
     * certains itemtypes n'ont pas de champ serie : les deux se traitent
     * independamment (chaine vide plutot qu'une valeur inventee), c'est au
     * gabarit d'afficher un repli explicite pour chacun separement.
     * @return array{0: string, 1: string} Nom, numero de serie.
     */
   private static function resolveDeviceDisplay(string $itemtype, int $items_id): array {
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          return ['', ''];
      }
       $device = new $itemtype();
      if (!$device->getFromDB($items_id)) {
          return ['', ''];
      }
       return [(string) ($device->fields['name'] ?? ''), (string) ($device->fields['serial'] ?? '')];
   }

    /**
     * Bornes de la frise du Passeport utilisateur, lues directement sur le
     * compte (jamais dupliquees dans glpi_plugin_assetsign_events, meme principe
     * que la lecture d'Infocom pour le Passeport materiel) : `begin_date` si
     * renseignee (date d'entree explicite, cf. Administration > Utilisateurs),
     * sinon `date_creation` (toujours disponible). Fin de frise UNIQUEMENT si
     * le compte est reellement desactive/supprime (jamais pour un compte actif
     * — la frise reste "en cours", meme principe que getLivesForItem() cote
     * materiel) : `end_date` si renseignee, sinon `date_mod` en repli — le
     * coeur GLPI ne conserve aucune date de desactivation explicite, ce repli
     * reste donc approximatif par construction.
     * @return array{start: ?string, end: ?string, is_deleted: bool}
     */
   public static function getAccountBounds(CommonDBTM $user): array {
       $isInactive = !$user->fields['is_active'] || $user->fields['is_deleted'];

       return [
           'start'      => $user->fields['begin_date'] ?: $user->fields['date_creation'],
           'end'        => $isInactive ? ($user->fields['end_date'] ?: $user->fields['date_mod']) : null,
           'is_deleted' => (bool) $user->fields['is_deleted'],
       ];
   }

    /**
     * Retro-remplit les evenements manquants d'un materiel a partir de l'historique
     * natif GLPI (`glpi_logs`), pour tout ce qui a eu lieu AVANT l'installation de
     * cette fonctionnalite. Rejoue exactement la meme logique que le declenchement
     * en direct (`Assetsign::handleUserBasedTrigger()`/`handleStateBasedTrigger()`),
     * sur les memes reglages d'Etats declencheurs (`Config::getHandoverStates()`
     * et consorts) — un seul endroit a regler, coherent avec ce qui declenche deja
     * les remises automatiques (cf. ROADMAP.md, choix explicite de l'utilisateur).
     * Idempotent par evenement candidat (jamais par simple compteur global) : peut
     * etre rappele sans risque, y compris via `$force` (bouton "Forcer la
     * recherche"), sans jamais dupliquer un evenement deja present.
     * @return int Nombre d'evenements effectivement ajoutes.
     */
   public static function backfillFromLogs(string $itemtype, int $items_id, bool $force = false): int {
      if (!$force && countElementsInTable(self::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id]) > 0) {
          return 0;
      }
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          return 0;
      }
       $item = new $itemtype();
      if (!$item->getFromDB($items_id)) {
          return 0;
      }
       $entitiesId = (int) ($item->fields['entities_id'] ?? 0);
       $config = Config::getForEntity($entitiesId);

       $count = 0;
      foreach (self::deriveHistoricalEventsFromLogs($itemtype, $items_id, $config) as $candidate) {
          $exists = countElementsInTable(self::getTable(), [
              'itemtype'   => $itemtype,
              'items_id'   => $items_id,
              'event_type' => $candidate['event_type'],
              'users_id'   => $candidate['users_id'],
              'date'       => $candidate['date'],
          ]) > 0;
         if ($exists) {
             continue;
         }

          $snapshot = self::resolveUserSnapshot($candidate['users_id']);
          (new self())->add([
              'itemtype'             => $itemtype,
              'items_id'             => $items_id,
              'entities_id'          => $entitiesId,
              'event_type'           => $candidate['event_type'],
              'date'                 => $candidate['date'],
              'users_id'             => $candidate['users_id'],
              'snapshot_name'        => $snapshot['name'],
              'snapshot_email'       => $snapshot['email'],
              'snapshot_entity_name' => \Dropdown::getDropdownName('glpi_entities', $entitiesId),
              'comment'              => __('Reconstitué automatiquement depuis l\'historique GLPI.', 'assetsign'),
          ]);
          $count++;
      }

       return $count;
   }

    /**
     * Rejoue chronologiquement les changements de `users_id`/`states_id` de
     * `glpi_logs` (id_search_option 70 et 31, stables dans le coeur GLPI pour
     * tous les itemtypes geres) pour en deduire les evenements qu'aurait
     * produits Assetsign::handleItemAssignment() s'il avait existe a l'epoque.
     * Regroupes par `date_mod` : une meme sauvegarde peut modifier les deux
     * champs a la fois, avec le meme timestamp sur les deux lignes de log qui
     * en resultent — le declenchement par affectation reste alors prioritaire
     * sur celui par Etat pour ce meme groupe, exactement comme en direct.
     * @return list<array{event_type: int, users_id: int, date: string}>
     */
   private static function deriveHistoricalEventsFromLogs(string $itemtype, int $items_id, Config $config): array {
       global $DB;

       $logs = iterator_to_array($DB->request([
           'FROM'  => 'glpi_logs',
           'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id, 'id_search_option' => [31, 70]],
           'ORDER' => 'date_mod ASC, id ASC',
       ]));

       $groups = [];
      foreach ($logs as $log) {
          $groups[$log['date_mod']][(int) $log['id_search_option']] = $log;
      }

       $events = [];
       $currentUser = 0; // Reconstitue au fil de l'eau, necessaire au declenchement par Etat.

      foreach ($groups as $date => $fieldsChanged) {
          $userLog = $fieldsChanged[70] ?? null;
         if ($userLog !== null) {
             $oldUser = (int) $userLog['old_id'];
             $newUser = (int) $userLog['new_id'];

            if ($oldUser === 0 && $newUser !== 0 && $config->fields['sign_on_assignment']) {
                $events[] = ['event_type' => self::TYPE_ATTRIBUTION, 'users_id' => $newUser, 'date' => $date];
            } else if ($oldUser !== 0 && $newUser === 0 && $config->fields['sign_on_return']) {
                $events[] = ['event_type' => self::TYPE_RETURN, 'users_id' => $oldUser, 'date' => $date];
            } else if ($oldUser !== 0 && $newUser !== 0 && $config->fields['sign_on_reassignment']) {
                $events[] = ['event_type' => self::TYPE_ATTRIBUTION, 'users_id' => $newUser, 'date' => $date];
            }

             $currentUser = $newUser;
             continue; // Priorite affectation : l'Etat n'est jamais evalue pour ce meme groupe.
         }

          $stateLog = $fieldsChanged[31] ?? null;
         if ($stateLog === null || $currentUser === 0) {
             continue; // Pas de detenteur connu a cette date : rien a rattacher, meme regle qu'en direct.
         }

          $newState = (int) $stateLog['new_id'];
         if (in_array($newState, $config->getHandoverStates(), true)) {
             $events[] = ['event_type' => self::TYPE_ATTRIBUTION, 'users_id' => $currentUser, 'date' => $date];
         } else if (in_array($newState, $config->getReturnStates(), true)) {
             $events[] = ['event_type' => self::TYPE_RETURN, 'users_id' => $currentUser, 'date' => $date];
         } else if (in_array($newState, $config->getDonationStates(), true)) {
             $events[] = ['event_type' => self::TYPE_DON, 'users_id' => $currentUser, 'date' => $date];
         } else if (in_array($newState, $config->getVenteStates(), true)) {
             $events[] = ['event_type' => self::TYPE_VENTE, 'users_id' => $currentUser, 'date' => $date];
         }
      }

       return $events;
   }

    /**
     * Retro-remplit TOUS les materiels qu'un utilisateur a pu avoir avant
     * l'installation de cette fonctionnalite : `glpi_logs` est indexe par
     * materiel, jamais par utilisateur — il faut donc d'abord retrouver quels
     * materiels le concernent (comme ancien OU nouveau detenteur) avant de
     * rejouer leur historique un par un via backfillFromLogs().
     * @return int Nombre d'evenements effectivement ajoutes, tous materiels confondus.
     */
   public static function backfillUserHistoryFromLogs(int $users_id, bool $force = false): int {
       global $DB;

      if (!$force && countElementsInTable(self::getTable(), ['users_id' => $users_id]) > 0) {
          return 0;
      }

       $itemPairs = iterator_to_array($DB->request([
           'SELECT'   => ['itemtype', 'items_id'],
           'DISTINCT' => true,
           'FROM'     => 'glpi_logs',
           'WHERE'    => [
               'id_search_option' => 70,
               'itemtype'         => Config::getAllManageableItemtypes(),
               'OR'               => ['old_id' => $users_id, 'new_id' => $users_id],
           ],
       ]));

       $count = 0;
      foreach ($itemPairs as $pair) {
          $count += self::backfillFromLogs((string) $pair['itemtype'], (int) $pair['items_id'], true);
      }

       return $count;
   }

    /** @return array{name: string, email: string} Identite ACTUELLE du beneficiaire (glpi_logs ne conserve aucun snapshot historique du nom/e-mail). */
   private static function resolveUserSnapshot(int $users_id): array {
      if ($users_id <= 0) {
          return ['name' => '', 'email' => ''];
      }
       $user = new \User();
      if (!$user->getFromDB($users_id)) {
          return ['name' => '', 'email' => ''];
      }
       return [
           'name'  => trim(\formatUserName(0, $user->fields['name'] ?? '', $user->fields['realname'] ?? '', $user->fields['firstname'] ?? '')),
           'email' => \UserEmail::getDefaultForUser($users_id) ?: '',
       ];
   }

    /**
     * Ajoute un resume de checklist qualite ('checklist_summary' : done/total/color)
     * a chaque ligne d'evenement provenant d'une Assetsign (cf. ROADMAP.md V1, issue
     * #74) - PUREMENT une agregation a l'affichage, exactement comme
     * getInfocomPseudoEvents()/getLinkedTicketPseudoEvents() : rien n'est jamais copie
     * dans glpi_plugin_assetsign_events, la source de verite reste
     * glpi_plugin_assetsign_checklistvalues (via source_itemtype/source_items_id, deja
     * present sur chaque ligne). Batch en DEUX requetes au total (jamais une par
     * evenement affiche) : le nombre de points APPLICABLES est mis en cache par type
     * de mouvement le temps de l'appel (peu de types distincts), le nombre de points
     * REMPLIS est recupere en une seule requete groupee sur tous les
     * source_items_id concernes - meme souci de performance que documente dans
     * ROADMAP.md ("Performance des indicateurs calcules... jamais recalcules a
     * chaque affichage") applique ici a l'echelle d'un seul appel plutot qu'un cache
     * persistant, un historique de materiel restant de taille raisonnable.
     * Absent (`checklist_summary` jamais ajoute) si aucun point de controle n'est
     * configure pour ce type de mouvement : l'absence de checklist n'est pas un
     * defaut a signaler, contrairement a une checklist configuree mais incomplete.
     * @param array<int, array<string, mixed>> $rows Modifie par reference.
     */
   private static function attachChecklistSummaries(array &$rows): void {
       global $DB;

       // PassportEvent::TYPE_* -> Assetsign::TYPE_* (numerotations differentes,
       // cf. recordForAssetsign() dont ceci est l'inverse).
       $eventTypeToAssetsignType = [
           self::TYPE_ATTRIBUTION => Assetsign::TYPE_HANDOVER,
           self::TYPE_RETURN      => Assetsign::TYPE_RETURN,
           self::TYPE_DON         => Assetsign::TYPE_DON,
           self::TYPE_VENTE       => Assetsign::TYPE_VENTE,
       ];

       $assetsignIds = [];
       foreach ($rows as $row) {
          if (($row['source_itemtype'] ?? null) === Assetsign::class && array_key_exists((int) $row['event_type'], $eventTypeToAssetsignType)) {
             $assetsignIds[] = (int) $row['source_items_id'];
          }
       }
       if ($assetsignIds === []) {
          return;
       }

       $filledCounts = [];
       foreach ($DB->request([
           'SELECT'  => ['plugin_assetsign_assetsigns_id', new \QueryExpression('COUNT(*) AS filled')],
           'FROM'    => 'glpi_plugin_assetsign_checklistvalues',
           'WHERE'   => ['plugin_assetsign_assetsigns_id' => array_values(array_unique($assetsignIds))],
           'GROUPBY' => 'plugin_assetsign_assetsigns_id',
       ]) as $row) {
          $filledCounts[(int) $row['plugin_assetsign_assetsigns_id']] = (int) $row['filled'];
       }

       $totalsByMovementType = [];
       foreach ($rows as &$row) {
          $eventType = (int) $row['event_type'];
          if (($row['source_itemtype'] ?? null) !== Assetsign::class || !array_key_exists($eventType, $eventTypeToAssetsignType)) {
             continue;
          }
          $movementType = $eventTypeToAssetsignType[$eventType];
          if (!array_key_exists($movementType, $totalsByMovementType)) {
             $totalsByMovementType[$movementType] = count(ChecklistItem::getActiveItemsForMovementType($movementType));
          }
          $total = $totalsByMovementType[$movementType];
          if ($total === 0) {
             continue;
          }
          $done = $filledCounts[(int) $row['source_items_id']] ?? 0;
          $row['checklist_summary'] = [
              'done'  => $done,
              'total' => $total,
              'color' => match (true) {
                  $done >= $total => '#2fb344', // vert : tous les points remplis
                  $done > 0       => '#f76707', // orange : partiellement rempli
                  default         => '#6c757d', // gris : configure mais rien de rempli
              },
          ];
       }
       unset($row);
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
              'name'        => $row['snapshot_name'] ?: __('Utilisateur retiré', 'assetsign'),
              'is_external' => (bool) $row['snapshot_is_external'],
              'start'       => $row['date'],
              'end'         => null,
          ];
      }

       // Duree lisible par vie (cf. ROADMAP.md, V2 "Indicateurs temporels") : une
       // vie encore en cours (`end` null) est comptee jusqu'a maintenant, jamais
       // figee a sa date de debut.
      foreach ($lives as &$life) {
          $endTimestamp = $life['end'] !== null ? strtotime($life['end']) : time();
          $durationDays = max(0, (int) floor(($endTimestamp - strtotime($life['start'])) / DAY_TIMESTAMP));
          $life['duration_days'] = $durationDays;
          $life['duration'] = self::formatDurationYearsMonths($durationDays);
      }
       unset($life);

       return $lives;
   }

    /**
     * Convertit un nombre de jours en duree lisible ("2 ans 3 mois", "5 mois",
     * "12 jours") - Html::timestampToString() du coeur GLPI existe deja mais
     * formate en jours/heures/minutes/secondes, illisible pour l'age d'un
     * materiel (ex: "823 jours 4 heures 12 minutes" plutot que "2 ans 3 mois").
     */
   private static function formatDurationYearsMonths(int $days): string {
      if ($days < 30) {
          return sprintf(_n('%d jour', '%d jours', $days, 'assetsign'), $days);
      }

       $months = (int) round($days / 30.44);
      if ($months < 12) {
          return sprintf(_n('%d mois', '%d mois', $months, 'assetsign'), $months);
      }

       $years = intdiv($months, 12);
       $remainingMonths = $months % 12;
       $yearsLabel = sprintf(_n('%d an', '%d ans', $years, 'assetsign'), $years);
      if ($remainingMonths === 0) {
          return $yearsLabel;
      }

       return $yearsLabel . ' ' . sprintf(_n('%d mois', '%d mois', $remainingMonths, 'assetsign'), $remainingMonths);
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

       // Index composite couvrant la requete reellement executee par showForItem()
       // (WHERE itemtype = ? AND items_id = ? AND event_type IN (...) ORDER BY date) :
       // cf. docs/design/ADR-passeport-v1.md, risque "Volume de la table d'evenements"
       // (issue #76). L'index 'item' pose a la creation de la table (itemtype, items_id)
       // seul ne couvre pas le tri par date, qui retombe alors sur un filesort des que
       // l'historique d'un materiel grossit - Migration::addKey() est idempotent
       // (hasKey() verifie deja avant d'agir), donc sans effet sur une base ou cet
       // index existe deja.
       $migration->addKey($table, ['itemtype', 'items_id', 'date'], 'item_date');
   }
}
