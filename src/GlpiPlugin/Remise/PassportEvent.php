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
      if ($item->getType() === 'User') {
          $count = countElementsInTable(self::getTable(), ['users_id' => $item->getID()]);
          return self::createTabEntry(__('Passeport utilisateur', 'remise'), $count);
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID()]);
       return self::createTabEntry(__('Passeport matériel', 'remise'), $count);
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
              Remise::class      => $CFG_GLPI['root_doc'] . '/plugins/remise/front/remise.form.php?id=' . $row['source_items_id'],
              Maintenance::class => $CFG_GLPI['root_doc'] . '/plugins/remise/front/maintenance.form.php?id=' . $row['source_items_id'],
              default            => null,
          };
      }
       unset($row);

       // getLivesForItem() sur les evenements REELS uniquement : les pseudo-evenements
       // Infocom ci-dessous n'ont pas de event_type/users_id, jamais mélangés à la
       // logique des "vies" - purement un ajout d'affichage sur la frise.
       $lives = self::getLivesForItem($item->getType(), (int) $item->getID(), $rows);

       $timelineRows = array_values($rows);
      if ($config->fields['show_infocom_dates']) {
          $timelineRows = array_merge($timelineRows, self::getInfocomPseudoEvents($item, $config));
          usort($timelineRows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
      }

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/passport_tab.html.twig', [
           'events'        => array_reverse($timelineRows), // le plus recent en premier dans la frise
           'lives'         => $lives,
           'itemtype'      => $item->getType(),
           'items_id'      => $item->getID(),
           'can_backfill'  => \Session::haveRight(self::$rightname, UPDATE),
           'csrf_token'    => \Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Dates Infocom du materiel (achat, commande, livraison, mise en service,
     * garantie, reforme), fusionnees dans la frise du Passeport materiel SANS
     * jamais etre copiees dans glpi_plugin_remise_events - repond a "que
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
           'order_date'    => __('Commande', 'remise'),
           'delivery_date' => __('Livraison', 'remise'),
           'buy_date'      => __('Achat', 'remise'),
           'use_date'      => __('Mise en service', 'remise'),
           'warranty_date' => __('Début de garantie', 'remise'),
           'decommission_date' => __('Réforme', 'remise'),
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
                      __('Prix d\'achat : %s %s', 'remise'),
                      number_format((float) $infocom->fields['value'], 2, ',', ' '),
                      $config->fields['currency_symbol'] ?: '€'
                  )
                  : '',
          ];
       }

       $warrantyMonths = (int) ($infocom->fields['warranty_duration'] ?? 0);
       if (!empty($infocom->fields['warranty_date']) && $warrantyMonths > 0) {
          $events[] = [
              'type_label'    => __('Fin de garantie', 'remise'),
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
              Remise::class      => $CFG_GLPI['root_doc'] . '/plugins/remise/front/remise.form.php?id=' . $row['source_items_id'],
              Maintenance::class => $CFG_GLPI['root_doc'] . '/plugins/remise/front/maintenance.form.php?id=' . $row['source_items_id'],
              default            => null,
          };
          [$row['device_name'], $row['device_serial']] = self::resolveDeviceDisplay($row['itemtype'], (int) $row['items_id']);
      }
       unset($row);

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/passport_user_tab.html.twig', [
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
     * compte (jamais dupliquees dans glpi_plugin_remise_events, meme principe
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
     * en direct (`Remise::handleUserBasedTrigger()`/`handleStateBasedTrigger()`),
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
              'comment'              => __('Reconstitué automatiquement depuis l\'historique GLPI.', 'remise'),
          ]);
          $count++;
      }

       return $count;
   }

    /**
     * Rejoue chronologiquement les changements de `users_id`/`states_id` de
     * `glpi_logs` (id_search_option 70 et 31, stables dans le coeur GLPI pour
     * tous les itemtypes geres) pour en deduire les evenements qu'aurait
     * produits Remise::handleItemAssignment() s'il avait existe a l'epoque.
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
           'name'  => trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? '')),
           'email' => \UserEmail::getDefaultForUser($users_id) ?: '',
       ];
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
