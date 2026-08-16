<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use MassiveAction;
use Migration;
use NotificationEvent;
use Session;

/**
 * Objet central du plugin : une ligne = une instance de workflow de remise
 * (assetsign initiale, restitution ou echange) pour un couple (materiel, utilisateur).
 */
class Assetsign extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

    /**
     * Jeton de signature brut, valide uniquement le temps de la requete en cours
     * (jamais persiste, jamais relu depuis la base). Alimente par
     * Provider\CanvasProvider::createRequest() puis lu par
     * NotificationTargetAssetsign::addDataForTemplate() pour construire ##assetsign.sign_url##.
     * Declare explicitement (plutot que propriete dynamique) pour rester compatible
     * avec la depreciation des proprietes dynamiques depuis PHP 8.2.
     */
   public ?string $_current_raw_token = null;

    // --- Statuts ------------------------------------------------------------------
   public const STATUS_DRAFT     = 0;
   public const STATUS_PENDING   = 1;
   public const STATUS_SENT      = 2;
   public const STATUS_VIEWED    = 3;
   public const STATUS_SIGNED    = 4;
    // 5 volontairement inutilise (ancien STATUS_REFUSED, retire : pas de refus possible)
   public const STATUS_EXPIRED   = 6;
   public const STATUS_CANCELLED = 7;
    /**
     * Don/vente a un beneficiaire EXTERNE (pas de compte GLPI, cf.
     * BENEFICIARY_EXTERNAL) : aucune signature electronique n'est possible
     * dans ce cas (choix assume, pas de lien de signature sans connexion —
     * cf. USER_GUIDE.md), le document genere devient directement le document final.
     * Statut terminal distinct de STATUS_SIGNED expres : une vraie signature
     * electronique n'a jamais eu lieu, melanger les deux serait trompeur pour
     * qui consulte l'historique (preuve de signature, export...).
     */
   public const STATUS_COMPLETED_NO_SIGNATURE = 8;

    // --- Beneficiaire ---------------------------------------------------------------
    /** Beneficiaire = un compte utilisateur GLPI existant (`users_id`). */
   public const BENEFICIARY_INTERNAL = 0;
    /**
     * Beneficiaire = une personne EXTERIEURE a l'entreprise, sans compte GLPI
     * (nom/contact en texte libre, cf. external_beneficiary_name/_contact) —
     * pertinent pour un Don ou une Vente (createManual() uniquement, jamais
     * pour une Assetsign/Restitution automatique qui suppose toujours un vrai
     * utilisateur GLPI affecte au materiel).
     */
   public const BENEFICIARY_EXTERNAL = 1;

    // --- Types --------------------------------------------------------------------
   public const TYPE_HANDOVER = 0; // assetsign
   public const TYPE_RETURN   = 1; // restitution
    // 2 volontairement inutilise (ancien TYPE_EXCHANGE, retire : un transfert direct
    // entre deux personnes est desormais traite comme une remise (TYPE_HANDOVER)
    // au nouveau detenteur, cf. handleUserBasedTrigger())
   public const TYPE_DON   = 3; // don de materiel (declenchement manuel, cf. createManual())
   public const TYPE_VENTE = 4; // vente de materiel (declenchement manuel, prix/date cf. VenteDetails)

    /**
     * Types autorises pour une creation MANUELLE (cf. createManual()) : la
     * remise/restitution restent exclusivement automatiques (hooks
     * d'affectation/etat, cf. handleItemAssignment()) — un technicien ne doit
     * pas pouvoir en creer une par ce canal, qui contournerait la deduplication
     * faite par cancelPendingAssetsignsFor() et pourrait laisser des fiches
     * orphelines pour un meme materiel.
     */
   private const MANUALLY_CREATABLE_TYPES = [self::TYPE_DON, self::TYPE_VENTE];

    /**
     * Statuts "envoyee mais pas encore signee" : utilise par le bouton "Relancer
     * maintenant", les crons de relance/expiration, et le widget "En attente" du
     * tableau de bord (Dashboard\CardProvider::pending()) — une seule definition
     * partagee plutot que ce couple de statuts recopie a chaque endroit.
     */
   public const STATUSES_AWAITING_SIGNATURE = [self::STATUS_SENT, self::STATUS_VIEWED];

    /**
     * Statuts pour lesquels le PDF non signe peut encore etre modifie (accessoires,
     * regeneration...) : tout ce qui precede une vraie signature. Une fois
     * SIGNED/EXPIRED/CANCELLED, le PDF ne doit plus bouger (preuve figee).
     */
   private const STATUSES_STILL_EDITABLE = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_VIEWED];

   public static function getTypeName($nb = 0): string {
       return _n('Assetsign', 'Assetsigns', $nb, 'assetsign');
   }

   public static function getIcon(): string {
       // 'ti-file-signature' n'existe pas dans la version de Tabler Icons
       // livree avec GLPI 11 (verifie directement dans public/lib/tabler.css) :
       // l'icone du menu Outils > Assetsigns restait silencieusement vide, sans
       // la moindre erreur. 'ti-signature' existe bien.
       return 'ti ti-signature';
   }

    /**
     * Enregistre sous le secteur 'tools' (Outils), pas 'admin' (cf.
     * Hooks::MENU_TOADD dans setup.php) : sans cette declaration, tout code
     * coeur GLPI qui construit le fil d'Ariane via getHeaderParameters()
     * (cf. CommonGLPI::getHeaderParameters(), appelee par
     * Glpi\Controller\GenericListController pour les listes d'intitules)
     * chercherait Assetsign dans le secteur 'admin', ou elle n'existe pas.
     */
   public static function getSectorizedDetails(): array {
       return ['tools', self::class];
   }

    /**
     * Etend le menu par defaut (recherche/ajout de Assetsign) avec des entrees
     * pour Template et Accessory : sans "options", le fil d'Ariane de ces
     * deux intitules (aujourd'hui geres via Configuration > Intitulés) ne
     * peut jamais afficher de segment "Gabarits de remise"/"Accessoires de
     * remise" — cf. templates/layout/parts/breadcrumbs.html.twig du coeur,
     * qui lit exactement menu[sector]['content'][item]['options'][option].
     */
   public static function getMenuContent(): array {
       $menu = parent::getMenuContent();
      if (!$menu) {
          return [];
      }

      if (Template::canView()) {
          $menu['options'][Template::class] = [
              'title' => Template::getTypeName(Session::getPluralNumber()),
              'page'  => Template::getSearchURL(false),
              'icon'  => Template::getIcon(),
              'links' => [
                  'search' => Template::getSearchURL(false),
                  'add'    => Template::getFormURL(false),
              ],
          ];
      }
      if (Accessory::canView()) {
          $menu['options'][Accessory::class] = [
              'title' => Accessory::getTypeName(Session::getPluralNumber()),
              'page'  => Accessory::getSearchURL(false),
              'icon'  => Accessory::getIcon(),
              'links' => [
                  'search' => Accessory::getSearchURL(false),
                  'add'    => Accessory::getFormURL(false),
              ],
          ];
      }

       return $menu;
   }

    /**
     * Nommee rawSearchOptions() (pas getSearchOptions(), qui n'existe pas dans
     * l'API de recherche de GLPI 11) : CommonDBTM::searchOptions() — la
     * methode reellement appelee par Search::getOptions() — est declaree
     * `final`, donc impossible a surcharger ; c'est rawSearchOptions() qui est
     * le point d'extension prevu. Bug reel corrige ici : sous l'ancien nom,
     * ces colonnes n'etaient jamais vues par GLPI — la liste "Gestion des
     * fiches" affichait ses lignes (case a cocher, pagination correcte) mais
     * aucune colonne de donnee, ni le moindre en-tete, sans la moindre erreur
     * visible. Constate en conditions reelles (page reellement chargee,
     * <tbody> ne contenant qu'une case a cocher par ligne).
     */
   public function rawSearchOptions(): array {
       $tab = [];

       $tab[] = [
           'id'   => 'common',
           'name' => self::getTypeName(1),
       ];

       $tab[] = [
           'id'       => 1,
           'table'    => self::getTable(),
           'field'    => 'id',
           'name'     => __('ID'),
           'datatype' => 'number',
       ];
       $tab[] = [
           'id'       => 2,
           'table'    => self::getTable(),
           'field'    => 'itemtype',
           'name'     => __('Type de matériel', 'assetsign'),
           'datatype' => 'itemtype',
       ];
       $tab[] = [
           'id'       => 3,
           'table'    => self::getTable(),
           'field'    => 'items_id',
           'name'     => __('Matériel', 'assetsign'),
           'datatype' => 'itemlink',
           'itemlink_type' => '',
       ];
       $tab[] = [
           'id'       => 4,
           'table'    => 'glpi_users',
           'field'    => 'name',
           'linkfield' => 'users_id',
           'name'     => __('Bénéficiaire', 'assetsign'),
           'datatype' => 'itemlink',
           'itemlink_type' => 'User',
       ];
       $tab[] = [
           'id'       => 5,
           'table'    => self::getTable(),
           'field'    => 'status',
           'name'     => __('Statut', 'assetsign'),
           'datatype' => 'specific',
           'searchtype' => 'equals',
       ];
       $tab[] = [
           'id'       => 6,
           'table'    => self::getTable(),
           'field'    => 'type',
           'name'     => __('Type de remise', 'assetsign'),
           'datatype' => 'specific',
           'searchtype' => 'equals',
       ];
       $tab[] = [
           'id'       => 7,
           'table'    => self::getTable(),
           'field'    => 'date_sent',
           'name'     => __('Date d\'envoi', 'assetsign'),
           'datatype' => 'datetime',
       ];
       $tab[] = [
           'id'       => 8,
           'table'    => self::getTable(),
           'field'    => 'date_signed',
           'name'     => __('Date de signature', 'assetsign'),
           'datatype' => 'datetime',
       ];
       $tab[] = [
           'id'       => 9,
           'table'    => self::getTable(),
           'field'    => 'reminder_count',
           'name'     => __('Nombre de relances', 'assetsign'),
           'datatype' => 'number',
       ];
       // 'nosearch' : un ID de Document interne n'a aucun sens a filtrer/rechercher,
       // ces deux colonnes ne servent qu'a afficher un lien de telechargement direct
       // depuis la liste (cf. getSpecificValueToDisplay()), sans avoir a ouvrir
       // chaque fiche pour retrouver le PDF correspondant.
       $tab[] = [
           'id'       => 10,
           'table'    => self::getTable(),
           'field'    => 'document_id_unsigned',
           'name'     => __('PDF non signé', 'assetsign'),
           'datatype' => 'specific',
           'nosearch' => true,
       ];
       $tab[] = [
           'id'       => 11,
           'table'    => self::getTable(),
           'field'    => 'document_id_signed',
           'name'     => __('PDF signé', 'assetsign'),
           'datatype' => 'specific',
           'nosearch' => true,
       ];
       // Colonne dediee (plutot que de recycler la colonne 4, jointe sur
       // glpi_users) : un beneficiaire EXTERNE (cf. BENEFICIARY_EXTERNAL) n'a
       // justement pas de ligne dans glpi_users, la colonne 4 y serait donc
       // toujours vide pour ces lignes. 'string' simple sur la propre table
       // de Assetsign : pas de jointure, filtrable/triable nativement.
       $tab[] = [
           'id'       => 12,
           'table'    => self::getTable(),
           'field'    => 'external_beneficiary_name',
           'name'     => __('Bénéficiaire externe', 'assetsign'),
           'datatype' => 'string',
       ];

       return $tab;
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
      if ($field === 'status') {
          return self::getStatuses()[$values['status']] ?? $values['status'];
      }
      if ($field === 'type') {
          return self::getTypes()[$values['type']] ?? $values['type'];
      }
      if ($field === 'document_id_unsigned' || $field === 'document_id_signed') {
          $documents_id = (int) ($values[$field] ?? 0);
         if ($documents_id <= 0) {
             return '';
         }
          global $CFG_GLPI;
          $label = $field === 'document_id_signed' ? __('Télécharger (signé)', 'assetsign') : __('Télécharger (non signé)', 'assetsign');
          return '<a href="' . $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documents_id . '" target="_blank">' . $label . '</a>';
      }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

    /**
     * Fiche en lecture seule : une Assetsign est un enregistrement genere
     * automatiquement (jamais saisi manuellement), le formulaire n'a donc
     * pas vocation a etre editable comme un CommonDBTM classique.
     */
   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);

       TemplateRenderer::getInstance()->display('@assetsign/assetsign_form.html.twig', [
           'item'         => $this,
           'params'       => $options,
           'statuses'     => self::getStatuses(),
           'types'        => self::getTypes(),
           'beneficiary'  => $this->isNewID($ID) ? [] : $this->getBeneficiary(),
           'target_item'  => $this->isNewID($ID) ? [] : $this->getTargetItem(),
           'reminders'    => $this->isNewID($ID) ? 0 : Reminder::countForAssetsign((int) $ID),
           'can_remind'   => !$this->isNewID($ID)
               && in_array((int) $this->fields['status'], self::STATUSES_AWAITING_SIGNATURE, true)
               && \Session::haveRight(self::$rightname, UPDATE),
           'accessories'          => $this->isNewID($ID) ? [] : $this->getAccessories(),
           'can_edit_accessories' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Le champ Observations est desactivable globalement (Config::enable_observations) :
           // une entite qui n'en a pas l'usage ne voit meme pas la section.
           'observations_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_observations'],
           // Etat des lieux visuel (etat_des_lieux) : meme reglage/permission que
           // les accessoires (isStillEditable() + droit UPDATE).
           'damage_annotation_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_damage_annotation'],
           'damage_views'   => DamageMarker::getViewLabels(),
           'damage_images'  => DamageMarker::getViewImageFilenames(),
           'damage_markers_by_view' => $this->isNewID($ID) ? [] : self::groupMarkersByView(DamageMarker::getForAssetsign((int) $ID)),
           'can_edit_damage_markers' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Prix/date de vente : editables apres coup, notamment pour une Vente
           // declenchee automatiquement par changement d'Etat (aucun prix connu
           // a la creation, cf. handleStateBasedTrigger()).
           'type_vente'       => self::TYPE_VENTE,
           'vente_details'    => (!$this->isNewID($ID) && (int) $this->fields['type'] === self::TYPE_VENTE)
               ? VenteDetails::getForAssetsign((int) $ID)
               : null,
           'can_edit_vente_details' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Preuve de signature (adresse IP, empreinte du document...) : la seule
           // trace de ces informations jusqu'ici etait a l'interieur du PDF signe
           // lui-meme (cf. handover.html.twig) — rien ne les affichait cote GLPI,
           // alors que glpi_plugin_assetsign_signatures les enregistre a chaque
           // signature (Signature::recordProofForAssetsign(), appele par markSigned()).
           'signature_proof' => (!$this->isNewID($ID) && (int) $this->fields['status'] === self::STATUS_SIGNED)
               ? Signature::getForAssetsign((int) $ID)
               : null,
           'csrf_token'   => \Session::getNewCSRFToken(),
       ]);

       return true;
   }

    /** @return array<int, array> Marqueurs de dommages regroupes par view_index, pour assetsign_form.html.twig et front/sign.php. */
   public static function groupMarkersByView(array $markers): array {
       $byView = [];
      foreach ($markers as $marker) {
          $byView[(int) $marker['view_index']][] = $marker;
      }
       return $byView;
   }

    /**
     * Action groupee "Relancer" depuis la liste des remises (Search::show(),
     * cf. front/assetsign.php) : evite d'ouvrir chaque fiche une par une pour
     * relancer plusieurs beneficiaires en attente de signature.
     */
   public function getSpecificMassiveActions($checkitem = null) {
       $actions = parent::getSpecificMassiveActions($checkitem);

      if (Session::haveRight(self::$rightname, UPDATE)) {
          $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'send_reminder']
              = __('Relancer maintenant', 'assetsign');
          $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'cancel_request']
              = __('Annuler la demande', 'assetsign');
      }

       return $actions;
   }

   public static function showMassiveActionsSubForm(MassiveAction $ma) {
      switch ($ma->getAction()) {
         case 'send_reminder':
            echo __('Envoyer une relance de signature aux assetsigns sélectionnées ?', 'assetsign');
            echo '<br><br>' . \Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);
              return true;
         case 'cancel_request':
             echo __('Annuler les demandes de signature sélectionnées ? Le lien envoyé au bénéficiaire deviendra invalide.', 'assetsign');
             echo '<br><br>' . \Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);
              return true;
      }
       return parent::showMassiveActionsSubForm($ma);
   }

   public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids) {
      switch ($ma->getAction()) {
         case 'send_reminder':
            foreach ($ids as $id) {
               if (!$item->getFromDB($id)) {
                  $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                  continue;
               }
                // can($id, UPDATE) regroupe le droit GLPI natif ET l'acces a
                // l'entite de CETTE remise precise - sans ce controle, le
                // framework MassiveAction ne filtre PAS lui-meme les ids par
                // entite pour une action specifique de plugin (verifie en
                // conditions reelles) : le seul droit generique du plugin
                // (deja verifie ailleurs, jamais restreint par entite)
                // suffisait a relancer/annuler N'IMPORTE QUELLE assetsign de
                // N'IMPORTE QUELLE entite - meme faille que celle corrigee
                // dans createManual(), cf. TROUBLESHOOTING.md.
               if (!$item->can($id, UPDATE)) {
                  $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                  continue;
               }
               try {
                    $item->sendReminderNow();
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
               } catch (\RuntimeException $e) {
                   // Deja signee/expiree : la relance ne s'applique pas a cette
                   // assetsign precise, ce n'est pas une erreur bloquante pour le
                   // reste de la selection (cf. sendReminderNow()).
                   $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                   $ma->addMessage($e->getMessage());
               }
            }
              return;
         case 'cancel_request':
            foreach ($ids as $id) {
               if (!$item->getFromDB($id)) {
                   $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                   continue;
               }
               if (!$item->can($id, UPDATE)) {
                   $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                   continue;
               }
               try {
                   $item->cancelRequest();
                   $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
               } catch (\RuntimeException $e) {
                   $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                   $ma->addMessage($e->getMessage());
               }
            }
              return;
      }
       parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if ($item->getType() === 'User') {
          $count = countElementsInTable(self::getTable(), ['users_id' => $item->getID(), 'is_deleted' => 0]);
          return self::createTabEntry(__('Assetsigns', 'assetsign'), $count);
      }
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return '';
      }
       $count = countElementsInTable(
           self::getTable(),
           ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_deleted' => 0]
       );
       return self::createTabEntry(__('Assetsigns', 'assetsign'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!($item instanceof CommonDBTM)) {
          return false;
      }
      if ($item->getType() === 'User') {
          self::showForUser((int) $item->getID());
          return true;
      }
       self::showForItem($item);
       return true;
   }

   public static function showForItem(CommonDBTM $item): void {
       global $DB;

       $rows = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_deleted' => 0],
           'ORDER' => 'date_creation DESC',
       ]);

       // Types a declenchement manuel disponibles depuis cet onglet (Don, puis
       // Vente...), chacun derriere son propre reglage d'entite — un technicien
       // ne voit le formulaire que si au moins un type est active ET qu'il a le
       // droit de modification. Cf. Assetsign::createManual().
       $config = Config::getForEntity((int) $item->fields['entities_id']);
       $manualTypes = [];
      if ($config->fields['enable_don']) {
          $manualTypes[self::TYPE_DON] = Workflow\WorkflowTypeRegistry::get(self::TYPE_DON)->getLabel();
      }
      if ($config->fields['enable_vente']) {
          $manualTypes[self::TYPE_VENTE] = Workflow\WorkflowTypeRegistry::get(self::TYPE_VENTE)->getLabel();
      }

       // Pre-selection du type (Don/Vente) et ouverture immediate du formulaire,
       // quand on arrive ici via le lien "creez maintenant la fiche" propose par
       // handleStateBasedTrigger() (cf. assetsign_prefill_type dans ce lien, et le
       // script de assetsign_tab.html.twig qui declenche le clic correspondant) —
       // jamais fait confiance aveuglement a une valeur venue de l'URL : doit
       // correspondre a un type reellement propose ci-dessus.
       $prefillType = isset($_GET['assetsign_prefill_type']) ? (int) $_GET['assetsign_prefill_type'] : null;
      if ($prefillType !== null && !array_key_exists($prefillType, $manualTypes)) {
          $prefillType = null;
      }

       $twig = \Glpi\Application\View\TemplateRenderer::getInstance();
       $twig->display('@assetsign/assetsign_tab.html.twig', [
           'item'          => $item,
           'assetsigns'       => iterator_to_array($rows),
           'statuses'      => self::getStatuses(),
           'manual_types'  => $manualTypes,
           'type_vente'    => self::TYPE_VENTE,
           'can_create_manual' => $manualTypes !== [] && Session::haveRight(self::$rightname, UPDATE),
           'show_item_column' => false,
           'csrf_token'    => \Session::getNewCSRFToken(),
           'prefill_type'  => $prefillType,
       ]);
   }

    /**
     * Onglet Assetsigns cote beneficiaire (fiche d'un compte GLPI, Administration >
     * Utilisateurs) : filtre par `users_id` (le beneficiaire) plutot que par
     * itemtype/items_id (le materiel cible, sens inverse de showForItem()) -
     * une meme personne ayant pu recevoir plusieurs materiels differents dans
     * le temps, chaque ligne affiche donc EN PLUS quel materiel elle concerne
     * (cf. 'show_item_column', absent de showForItem() ou le materiel est
     * toujours le meme et deja visible dans le titre de la fiche). Lecture
     * seule uniquement : la creation manuelle (Don/Vente) exige de choisir un
     * materiel cible precis, hors de propos depuis la fiche d'un utilisateur.
     */
   public static function showForUser(int $users_id): void {
       global $DB, $CFG_GLPI;

       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['users_id' => $users_id, 'is_deleted' => 0],
           'ORDER' => 'date_creation DESC',
       ]));

      foreach ($rows as &$row) {
          $row['item_display'] = self::resolveItemDisplayName($row['itemtype'], (int) $row['items_id']);
      }
       unset($row);

       // Assigner un materiel directement depuis cet onglet (raccourci) :
       // meme droit que la creation manuelle Don/Vente ci-dessus, la vraie
       // portee de securite est de toute facon revalidee par $item->can($id,
       // UPDATE) cote serveur (cf. front/assign_user_asset.php).
       $canAssign = Session::haveRight(self::$rightname, UPDATE);
       $itemtypeDropdownHtml = '';
       $itemDropdownHtml = '';
      if ($canAssign) {
          $rand = mt_rand();

          ob_start();
          \Dropdown::showFromArray('itemtype', Config::getItemtypeLabels(), [
              'display_emptychoice' => true,
              'rand'                => $rand,
          ]);
          $itemtypeDropdownHtml = ob_get_clean();

          ob_start();
          echo '<span id="assetsign-assign-items-container">'
              . __('Choisissez d\'abord un type de matériel ci-dessus.', 'assetsign')
              . '</span>';
          \Ajax::updateItemOnSelectEvent(
              "dropdown_itemtype$rand",
              'assetsign-assign-items-container',
              $CFG_GLPI['root_doc'] . '/ajax/dropdownAllItems.php',
              [
                  'idtable' => '__VALUE__',
                  'name'    => 'items_id',
                  'rand'    => $rand,
              ]
          );
          $itemDropdownHtml = ob_get_clean();
      }

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetsign/assetsign_tab.html.twig', [
           'assetsigns'                => $rows,
           'statuses'               => self::getStatuses(),
           'manual_types'           => [],
           'can_create_manual'      => false,
           'show_item_column'       => true,
           'can_assign_asset'       => $canAssign,
           'target_user_id'         => $users_id,
           'itemtype_dropdown_html' => $itemtypeDropdownHtml,
           'item_dropdown_html'     => $itemDropdownHtml,
           'csrf_token'             => \Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Libelle lisible d'un materiel arbitraire (type canonique + nom), pour la
     * colonne "Materiel" de showForUser() — un materiel peut avoir ete purge
     * depuis, d'ou le repli sur "type #id" plutot qu'un nom vide ou une erreur.
     */
   private static function resolveItemDisplayName(string $itemtype, int $items_id): string {
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          return $itemtype . ' #' . $items_id;
      }
       $item = new $itemtype();
      if (!$item->getFromDB($items_id)) {
          return $itemtype . ' #' . $items_id;
      }
       return self::getCanonicalItemtypeLabel($itemtype) . ' — ' . ($item->fields['name'] ?: ('#' . $items_id));
   }

   public static function getStatuses(): array {
       return [
           self::STATUS_DRAFT     => __('Brouillon', 'assetsign'),
           self::STATUS_PENDING   => __('En attente', 'assetsign'),
           self::STATUS_SENT      => __('Envoyé', 'assetsign'),
           self::STATUS_VIEWED    => __('Consulté', 'assetsign'),
           self::STATUS_SIGNED    => __('Signé', 'assetsign'),
           self::STATUS_EXPIRED   => __('Expiré', 'assetsign'),
           self::STATUS_CANCELLED => __('Annulé', 'assetsign'),
           self::STATUS_COMPLETED_NO_SIGNATURE => __('Remis (bénéficiaire externe, sans signature)', 'assetsign'),
       ];
   }

    /**
     * @return array<int, string> id => libelle traduit, un par type enregistre
     *     aupres de Workflow\WorkflowTypeRegistry.
     */
   public static function getTypes(): array {
       $types = [];
      foreach (Workflow\WorkflowTypeRegistry::all() as $type) {
          $types[$type->getId()] = $type->getLabel();
      }
       return $types;
   }

    /**
     * Titres du PDF volontairement NON traduits via __() : contrairement a une
     * notification (localisee par destinataire, cf. NotificationTargetAssetsign),
     * le PDF est un document unique, archive, consulte a des dates differentes
     * par des personnes potentiellement differentes (le beneficiaire, mais
     * aussi un admin, un service RH...). Utiliser __() ici ferait dependre la
     * langue du CONTENU du PDF de la session du technicien qui a declenche la
     * remise au moment de sa creation — constate en conditions reelles :
     * "Equipment handover form" en-tete alors que le reste de la fiche
     * ("Bénéficiaire", "Conditions générales"...) reste en francais, car ces
     * autres textes sont deja fixes en dur dans le gabarit Twig
     * (handover.html.twig) et n'utilisent pas __(). Le texte du contrat/charte
     * lui-meme (saisi librement dans Template) reste dans la langue de
     * redaction de l'organisation, non traduit — meme logique.
     */
   public static function getPdfHeadings(int $type): array {
       return Workflow\WorkflowTypeRegistry::get($type)->getPdfHeadings();
   }

    // ================================================================================
    // Detection de l'affectation (appele depuis hook.php)
    // ================================================================================

   public static function handleItemAssignment(CommonDBTM $item): void {
       $config = Config::getForEntity((int) ($item->fields['entities_id'] ?? 0));

      if (!in_array($item->getType(), $config->getManagedItemtypes(), true)) {
          return;
      }

       // Le declenchement par affectation (users_id) est prioritaire : s'il a
       // agi, on n'evalue pas aussi le declenchement par Etat pour la meme
       // requete (evite de creer deux assetsigns si un technicien change les
       // deux champs en une seule fois).
      if (self::handleUserBasedTrigger($item, $config)) {
          return;
      }

       self::handleStateBasedTrigger($item, $config);
   }

    /** @return bool true si une remise a ete declenchee par ce mecanisme */
   private static function handleUserBasedTrigger(CommonDBTM $item, Config $config): bool {
       // Rien n'a change sur le champ users_id (ex: mise a jour d'un autre champ) ;
       // pour un nouvel element (item_add), il n'y a pas d'oldvalues du tout, on
       // considere alors l'ancien detenteur comme "aucun" (0).
      if (!array_key_exists('users_id', $item->oldvalues) && $item->isNewItem() === false) {
          return false;
      }

       $old_user = (int) ($item->oldvalues['users_id'] ?? 0);
       $new_user = (int) ($item->fields['users_id'] ?? 0);

      if ($old_user === $new_user) {
          return false;
      }

      if ($old_user === 0 && $new_user !== 0) {
         if ($config->fields['sign_on_assignment']) {
             self::createAssetsign($item, self::TYPE_HANDOVER, $new_user);
             return true;
         }
          return false;
      }

      if ($old_user !== 0 && $new_user === 0) {
         if ($config->fields['sign_on_return']) {
             self::createAssetsign($item, self::TYPE_RETURN, $old_user);
             return true;
         }
          return false;
      }

      // Toujours vrai a ce point precis (les 3 autres cas - egalite, old=0,
      // new=0 - viennent d'etre exclus ci-dessus) ; conserve pour la symetrie
      // de lecture avec les branches precedentes plutot qu'un simple else.
      // @phpstan-ignore notIdentical.alwaysTrue
      if ($old_user !== 0 && $new_user !== 0) {
          // Un transfert direct entre deux personnes (l'ancien detenteur n'est
          // jamais passe par 0) est traite comme une remise normale au nouveau
          // detenteur : pas de type "Echange" distinct (retire, cf. TYPE_EXCHANGE).
         if ($config->fields['sign_on_reassignment']) {
             self::createAssetsign($item, self::TYPE_HANDOVER, $new_user);
             return true;
         }
      }

       return false;
   }

    /**
     * Declenchement alternatif base sur le champ "Etat" (states_id) du materiel,
     * configurable dans Assetsign & signature > Configuration : utile pour les
     * organisations qui pilotent le cycle de vie du materiel via son Etat
     * (ex: "En prêt" / "Disponible") plutot que via l'affectation d'utilisateur
     * elle-meme — les listes d'Etats variant fortement d'un GLPI a l'autre,
     * rien n'est presuppose ici : l'administrateur choisit ses propres Etats.
     */
   private static function handleStateBasedTrigger(CommonDBTM $item, Config $config): void {
      if (!array_key_exists('states_id', $item->oldvalues)) {
          return;
      }

       $newState = (int) ($item->fields['states_id'] ?? 0);
       $oldState = (int) ($item->oldvalues['states_id'] ?? 0);
      if ($newState === $oldState) {
          return;
      }

       $currentUser = (int) ($item->fields['users_id'] ?? 0);

       // Don/Vente sans utilisateur assigne (materiel en stock, jamais affecte
       // a personne) : cas legitime — contrairement a Assetsign/Restitution, un don
       // ou une vente n'a pas forcement de "detenteur GLPI" prealable, et peut
       // tres bien concerner un beneficiaire EXTERNE (cf. BENEFICIARY_EXTERNAL,
       // createManual()). Le declenchement automatique, lui, ne connait que
       // l'utilisateur GLPI courant : sans lui, il ne peut rien creer tout seul.
       // Plutot que d'ignorer silencieusement ce changement d'Etat comme avant
       // (le technicien n'avait alors aucun moyen de savoir qu'une fiche etait
       // attendue), le message pointe directement vers le formulaire de creation
       // manuelle — seul canal qui sait gerer un beneficiaire externe ou un choix
       // explicite d'utilisateur. Impossible de rediriger reellement (Html::redirect())
       // depuis ce hook : il s'execute EN PLEIN MILIEU de la sauvegarde native de
       // l'item (CommonDBTM::update() -> Plugin::doHook()), avant que le controleur
       // GLPI (front/computer.form.php...) n'ait fini son propre travail post-
       // sauvegarde (autres hooks, historique, notifications, redirection finale) —
       // interrompre cette chaine ici casserait des choses hors du controle du
       // plugin. Le lien utilise le parametre standard `forcetab` du coeur GLPI
       // pour ouvrir directement l'onglet Assetsigns au clic, plus `tab_params`
       // (autre parametre standard du coeur, cf. CommonGLPI::showTabsContent()/
       // displayFullPageForItem() — meme convention que Reservation.php pour
       // preremplir un champ via un lien direct) pour transmettre
       // assetsign_prefill_type jusqu'a l'appel ajax qui charge reellement le
       // contenu de l'onglet (ajax/common.tabs.php, requete SEPAREE de la page
       // initiale : un simple `?assetsign_prefill_type=X` sur CE lien ne
       // survivrait pas au chargement asynchrone de l'onglet, verifie en
       // conditions reelles, cf. TROUBLESHOOTING.md). Lu par showForItem()
       // pour pre-selectionner le bon type et reveler tout de suite les champs
       // a completer (cf. assetsign_tab.html.twig) plutot que de laisser le
       // technicien chercher l'onglet puis le bouton lui-meme.
      if ($currentUser === 0
           && (in_array($newState, $config->getDonationStates(), true) || in_array($newState, $config->getVenteStates(), true))
       ) {
          $isDon = in_array($newState, $config->getDonationStates(), true);
          $targetType = $isDon ? self::TYPE_DON : self::TYPE_VENTE;
           $link = $item::getFormURLWithID($item->getID())
               . '&forcetab=' . urlencode(self::class . '$1')
               . '&tab_params[assetsign_prefill_type]=' . $targetType;
          \Session::addMessageAfterRedirect(
              sprintf(
                  __('Ce matériel n\'a pas d\'utilisateur assigné : <a href="%s">créez maintenant la fiche de %s</a> (bénéficiaire interne ou externe).', 'assetsign'),
                  htmlspecialchars($link, ENT_QUOTES),
                  $isDon ? __('don', 'assetsign') : __('vente', 'assetsign')
              ),
              false,
              INFO
          );
          return;
      }

      if ($currentUser === 0
           && (in_array($newState, $config->getHandoverStates(), true) || in_array($newState, $config->getReturnStates(), true))
       ) {
          // Contrairement a Don/Vente ci-dessus, aucun message ne peut orienter
          // vers une creation manuelle : Assetsign::MANUALLY_CREATABLE_TYPES exclut
          // volontairement Assetsign/Restitution de ce canal (cf. commentaire sur
          // cette constante) — une remise/restitution automatique a toujours
          // besoin d'un detenteur GLPI reel (avant ou apres le changement), que
          // seul le declenchement par affectation (handleUserBasedTrigger())
          // sait fournir. Le seul geste utile ici est donc d'assigner (ou de
          // retirer) l'utilisateur sur la fiche du materiel lui-meme.
          \Session::addMessageAfterRedirect(
              __('Ce matériel n\'a pas d\'utilisateur assigné : ce changement d\'État ne peut donc pas générer de fiche de remise ou de restitution. Assignez un utilisateur sur la fiche du matériel si une signature est attendue.', 'assetsign'),
              false,
              INFO
          );
          return;
      }

      if ($currentUser === 0) {
          return; // Assetsign/Restitution : personne a notifier
      }

      if (in_array($newState, $config->getHandoverStates(), true)) {
          self::createAssetsign($item, self::TYPE_HANDOVER, $currentUser);
          return;
      }

      if (in_array($newState, $config->getReturnStates(), true)) {
          self::createAssetsign($item, self::TYPE_RETURN, $currentUser);
          return;
      }

      if (in_array($newState, $config->getDonationStates(), true)) {
          self::createAssetsign($item, self::TYPE_DON, $currentUser);
          return;
      }

       // Vente ainsi declenchee : AUCUNE VenteDetails n'est creee ici (contrairement
       // a createManual(), qui recoit un prix/date saisis a la main) — le prix n'est
       // pas connu au moment d'un changement d'Etat. handover.html.twig masque deja
       // la section "Details de la vente" tant que vente_price est null ; un
       // technicien la complete ensuite via Assetsign::updateVenteDetails().
      if (in_array($newState, $config->getVenteStates(), true)) {
          self::createAssetsign($item, self::TYPE_VENTE, $currentUser);
      }
   }

   public static function archiveForPurgedItem(CommonDBTM $item): void {
       global $DB;

       // Le materiel est purge : on conserve l'historique des remises (preuve),
       // on ne les supprime jamais en cascade.
       $DB->update(self::getTable(), ['is_deleted' => 1], [
           'itemtype' => $item->getType(),
           'items_id' => $item->getID(),
       ]);
   }

   private static function createAssetsign(CommonDBTM $item, int $type, int $users_id): void {
       $config = Config::getForEntity((int) $item->fields['entities_id']);
       $template = Template::getDefaultFor($type, (int) $item->fields['entities_id']);

       self::cancelPendingAssetsignsFor($item);

       $assetsign = new self();
       $id = $assetsign->add([
           'entities_id'                 => $item->fields['entities_id'],
           'itemtype'                    => $item->getType(),
           'items_id'                    => $item->getID(),
           'users_id'                    => $users_id,
           'users_id_tech'               => Session::getLoginUserID() ?: 0,
           'plugin_assetsign_templates_id'  => $template ? $template->getID() : 0,
           'type'                        => $type,
           'status'                      => self::STATUS_PENDING,
       ]);

      if (!$id) {
          trigger_error('Plugin assetsign : echec de creation de la remise pour ' . $item->getType() . ' #' . $item->getID(), E_USER_WARNING);
          CreationFailure::record(
              $item->getType(),
              $item->getID(),
              (int) $item->fields['entities_id'],
              $type,
              'Echec de l\'insertion en base (add() a renvoyé 0).'
          );
          return;
      }

       $assetsign->getFromDB($id);
       $assetsign->launchWorkflow($config);
   }

    /**
     * Point d'entree PUBLIC pour les types a declenchement manuel (Don, Vente) :
     * contrairement a createAssetsign() (privee, appelee depuis les hooks
     * d'affectation/etat), rien ne signale automatiquement "ce materiel est
     * donne/vendu" — un technicien choisit explicitement de creer la fiche
     * depuis l'onglet Assetsign du materiel (cf. assetsign_tab.html.twig,
     * front/assetsign.form.php action "create_manual").
     *
     * @param array $extra Donnees specifiques au type ('price'/'sale_date' pour
     *                      TYPE_VENTE, cf. VenteDetails) — ignorees pour les
     *                      types qui n'en utilisent pas. 'beneficiary_type'
     *                      (BENEFICIARY_INTERNAL par defaut, ou
     *                      BENEFICIARY_EXTERNAL), et dans ce dernier cas
     *                      'external_name' (obligatoire)/'external_contact'
     *                      (facultatif, email ou telephone en texte libre) —
     *                      un don/une vente peut concerner quelqu'un hors de
     *                      l'entreprise, sans compte GLPI.
     *
     * Effet de bord : met aussi a jour l'Etat du materiel lui-meme si l'entite
     * a configure un Etat declencheur pour ce type (cf. syncItemStateAfterManualCreation()).
     * @throws \InvalidArgumentException si $itemtype n'est pas un itemtype GLPI valide,
     *                                    ou si le beneficiaire (interne ou externe) est manquant
     * @throws \RuntimeException si le materiel est introuvable ou la creation echoue
     */
   public static function createManual(string $itemtype, int $items_id, int $type, int $users_id, array $extra = []): self {
      if (!in_array($type, self::MANUALLY_CREATABLE_TYPES, true)) {
          throw new \InvalidArgumentException(__('Ce type de fiche ne peut pas être créé manuellement.', 'assetsign'));
      }

       $beneficiaryType = (int) ($extra['beneficiary_type'] ?? self::BENEFICIARY_INTERNAL);
       $externalName = trim((string) ($extra['external_name'] ?? ''));
       $externalContact = trim((string) ($extra['external_contact'] ?? ''));

      if ($beneficiaryType === self::BENEFICIARY_EXTERNAL) {
         if ($externalName === '') {
             throw new \InvalidArgumentException(__('Le nom du bénéficiaire externe est obligatoire.', 'assetsign'));
         }
          $users_id = 0;
      } else if ($users_id <= 0) {
          throw new \InvalidArgumentException(__('Le bénéficiaire (utilisateur GLPI) est obligatoire.', 'assetsign'));
      }

      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          throw new \InvalidArgumentException(sprintf(__('Type de matériel invalide : %s', 'assetsign'), $itemtype));
      }

       $item = new $itemtype();
       // !can($items_id, READ) regroupe a la fois le droit GLPI natif sur ce
       // type de materiel ET l'appartenance a une entite accessible par
       // l'utilisateur courant - sans ce second controle, le seul droit
       // "Assetsign & signature" (verifie par l'appelant, non restreint par
       // entite) suffisait a creer une fiche pour N'IMPORTE QUEL materiel de
       // N'IMPORTE QUELLE entite de l'instance, y compris hors de la portee
       // de l'utilisateur (faille reelle trouvee et corrigee en conditions
       // reelles, cf. TROUBLESHOOTING.md). Meme message que "introuvable" que
       // le materiel n'existe pas du tout, volontairement : ne pas laisser
       // deviner qu'un id existe ailleurs mais est hors de portee.
      if (!$item->getFromDB($items_id) || !$item->can($items_id, READ)) {
          throw new \RuntimeException(__('Matériel introuvable.', 'assetsign'));
      }

       $config = Config::getForEntity((int) $item->fields['entities_id']);
       $template = Template::getDefaultFor($type, (int) $item->fields['entities_id']);

       $assetsign = new self();
       $id = $assetsign->add([
           'entities_id'                   => $item->fields['entities_id'],
           'itemtype'                      => $itemtype,
           'items_id'                      => $items_id,
           'users_id'                      => $users_id,
           'users_id_tech'                 => Session::getLoginUserID() ?: 0,
           'beneficiary_type'              => $beneficiaryType,
           'external_beneficiary_name'     => $beneficiaryType === self::BENEFICIARY_EXTERNAL ? $externalName : '',
           'external_beneficiary_contact'  => $beneficiaryType === self::BENEFICIARY_EXTERNAL ? $externalContact : '',
           'plugin_assetsign_templates_id'    => $template ? $template->getID() : 0,
           'type'                          => $type,
           'status'                        => self::STATUS_PENDING,
       ]);

      if (!$id) {
          throw new \RuntimeException(__('Échec de la création de la fiche.', 'assetsign'));
      }

       $assetsign->getFromDB($id);

      if ($type === self::TYPE_VENTE) {
          // Cree AVANT launchWorkflow() : le PDF genere ci-dessous doit deja
          // pouvoir lire le prix/la date de vente (cf. VenteDetails, HandoverPdfBuilder).
          VenteDetails::createForAssetsign($id, (float) ($extra['price'] ?? 0), (string) ($extra['sale_date'] ?? date('Y-m-d')));
      }

       $assetsign->launchWorkflow($config);

       self::syncItemStateAfterManualCreation($item, $type, $config);

       return $assetsign;
   }

    /**
     * Sens "creation manuelle -> Etat du materiel" de la coherence bidirectionnelle
     * (cf. ROADMAP.md) : sans ca, l'inventaire GLPI pouvait afficher une autre
     * realite que la fiche qu'on vient de creer ("toujours en service" cote
     * materiel, "vendu" cote Assetsign). Met a jour states_id directement en SQL
     * (meme motif que cancelPendingAssetsignsFor()) plutot que via $item->update() :
     * un update() classique redeclencherait le hook item_update ->
     * handleItemAssignment() -> handleStateBasedTrigger() pour CE MEME
     * changement d'Etat, qui recreerait une deuxieme fiche (si un utilisateur
     * est assigne) ou reafficherait le message "creez maintenant" qu'on vient
     * justement de traiter (si aucun ne l'est) — cf. TROUBLESHOOTING.md.
     */
   private static function syncItemStateAfterManualCreation(CommonDBTM $item, int $type, Config $config): void {
       global $DB;

      if (!array_key_exists('states_id', $item->fields)) {
          return; // Itemtype sans notion d'Etat GLPI (ne devrait pas arriver pour les types geres, defensif).
      }

       $targetStates = $type === self::TYPE_DON ? $config->getDonationStates() : $config->getVenteStates();
      if ($targetStates === []) {
          return; // Aucun Etat declencheur configure pour ce type : rien a synchroniser.
      }

       // Plusieurs Etats peuvent etre configures comme declencheurs pour la meme
       // entite (ex: "Donné" et "Recyclé" tous deux declencheurs de Don) : le
       // premier de la liste sert de cible canonique, par convention documentee
       // (meme ordre que celui choisi par l'administrateur dans le reglage) —
       // aucun moyen de deviner lequel des plusieurs Etats correspond a CETTE
       // fiche precise.
       $targetState = $targetStates[0];
       $currentState = (int) $item->fields['states_id'];
      if ($currentState === $targetState) {
          return;
      }

       $DB->update($item::getTable(), ['states_id' => $targetState], ['id' => $item->getID()]);
       $item->fields['states_id'] = $targetState;

       \Log::history(
           $item->getID(),
           $item::class,
           ['0', '', sprintf(
               __('État mis à jour automatiquement (%s) suite à la création d\'une fiche par le plugin Assetsign & signature.', 'assetsign'),
               \Dropdown::getDropdownName('glpi_states', $targetState)
           )],
           0,
           \Log::HISTORY_LOG_SIMPLE_MESSAGE
       );
   }

    /**
     * Annule toute assetsign encore en attente de signature pour ce materiel (statuts
     * DRAFT/PENDING/SENT/VIEWED) et invalide son jeton de signature.
     *
     * Sans cela, un materiel reaffecte une deuxieme fois avant que le premier
     * beneficiaire ait signe laisserait son lien de signature actif : il pourrait
     * signer un document de remise pour un materiel qu'il ne detient plus, pendant
     * qu'une deuxieme assetsign part en parallele pour le nouveau detenteur.
     */
   private static function cancelPendingAssetsignsFor(CommonDBTM $item): void {
       global $DB;

      foreach ($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => [
               'itemtype'   => $item->getType(),
               'items_id'   => $item->getID(),
               'status'     => self::STATUSES_STILL_EDITABLE,
               'is_deleted' => 0,
           ],
       ]) as $row) {
          $DB->update(self::getTable(), ['status' => self::STATUS_CANCELLED], ['id' => $row['id']]);
          Token::invalidateForAssetsign((int) $row['id']);
          // Ecrit directement en SQL (pas via update()) pour eviter un aller-retour
          // complet par assetsign dans une boucle de reaffectation : l'historique natif
          // de CommonDBTM (declenche par update()) est donc court-circuite ici, on le
          // reconstitue explicitement pour que l'onglet Historique de la fiche
          // reflete quand meme cette annulation automatique.
          \Log::history(
              (int) $row['id'],
              self::class,
              ['0', '', __('Annulée automatiquement (matériel réaffecté avant signature)', 'assetsign')],
              0,
              \Log::HISTORY_LOG_SIMPLE_MESSAGE
          );
      }
   }

    /**
     * Annule manuellement une demande de signature encore en attente (bouton
     * "Annuler" sur la fiche, ou action groupee "cancel_request" depuis
     * Search::show(), cf. getSpecificMassiveActions()). Contrairement a
     * cancelPendingAssetsignsFor() (declenchee automatiquement lors d'une
     * reaffectation), celle-ci passe par update() : l'historique natif de
     * CommonDBTM capture donc deja le changement de statut sans appel explicite
     * a Log::history().
     *
     * @throws \RuntimeException si la remise est deja signee/expiree/annulee.
     */
   public function cancelRequest(): void {
      if (!$this->isStillEditable()) {
          throw new \RuntimeException(__('Cette demande ne peut plus être annulée (déjà signée, expirée ou déjà annulée).', 'assetsign'));
      }

       $this->update(['id' => $this->getID(), 'status' => self::STATUS_CANCELLED]);
       Token::invalidateForAssetsign($this->getID());
   }

    /**
     * Genere le PDF, cree le jeton de signature, envoie la notification initiale.
     */
   public function launchWorkflow(?Config $config = null): void {
       $config ??= Config::getForEntity((int) $this->fields['entities_id']);

       // Point de passage commun a toutes les voies de creation (automatique par
       // affectation/Etat, manuelle Don/Vente) : seul endroit ou enregistrer
       // l'evenement du Passeport materiel sans dupliquer cet appel dans chacune
       // de ces voies. Volontairement AVANT la construction du PDF : un echec de
       // rendu PDF ne doit pas empecher l'evenement d'exister.
       PassportEvent::recordForAssetsign($this);

       $builder = new Pdf\HandoverPdfBuilder();
       $document = $builder->build($this);

       $this->update([
           'id'                   => $this->getID(),
           'document_id_unsigned' => $document->getID(),
       ]);

      if ((int) $this->fields['beneficiary_type'] === self::BENEFICIARY_EXTERNAL) {
          // Beneficiaire externe (cf. BENEFICIARY_EXTERNAL) : pas de compte
          // GLPI, donc pas de connexion possible pour signer (le systeme de
          // signature exige une session GLPI authentifiee, cf.
          // Firewall::STRATEGY_AUTHENTICATED sur front/sign.php — choix
          // assume, cf. USER_GUIDE.md). Le document genere devient directement le
          // document final, sans jeton ni notification d'invitation a
          // signer. STATUS_COMPLETED_NO_SIGNATURE, jamais STATUS_SIGNED :
          // aucune signature electronique n'a reellement eu lieu.
          $this->update([
              'id'                 => $this->getID(),
              'document_id_signed' => $document->getID(),
              'status'             => self::STATUS_COMPLETED_NO_SIGNATURE,
              'date_signed'        => date('Y-m-d H:i:s'),
          ]);
          return;
      }

       // Delegue au fournisseur configure (uniquement le canvas natif pour l'instant,
       // cf. Provider\ProviderFactory).
       // Le jeton brut n'est jamais stocke : il transite en propriete volatile le temps
       // de construire l'e-mail de notification dans la meme requete (cf. Token::validate()).
       $provider = Provider\ProviderFactory::for($config);
       $provider->createRequest($this, GLPI_DOC_DIR . '/' . $document->fields['filepath']);

       $this->update([
           'id'        => $this->getID(),
           'status'    => self::STATUS_SENT,
           'date_sent' => date('Y-m-d H:i:s'),
       ]);

       NotificationEvent::raiseEvent('new', $this);
   }

    /**
     * Construit l'URL de signature a partir d'un jeton brut fraichement genere
     * (jamais relu depuis la base, cf. Token::validate()).
     */
   public function getSignUrl(?string $rawToken = null): string {
       $rawToken ??= $this->_current_raw_token ?? null;
      if ($rawToken === null) {
          return '';
      }
       $base = rtrim($GLOBALS['CFG_GLPI']['url_base'] ?? '', '/');
       return $base . '/plugins/assetsign/front/sign.php?t=' . urlencode($rawToken);
   }

   public function getExpiryDate(): ?string {
       return Token::getExpiryForAssetsign($this->getID());
   }

   public function getBeneficiary(): array {
      if ((int) ($this->fields['beneficiary_type'] ?? self::BENEFICIARY_INTERNAL) === self::BENEFICIARY_EXTERNAL) {
          // Beneficiaire externe (cf. BENEFICIARY_EXTERNAL) : pas de ligne
          // glpi_users a joindre, le nom/contact vient du texte libre saisi
          // a la creation (cf. createManual()). Meme forme de tableau que le
          // cas interne ci-dessous (firstname/realname/email) pour que les
          // gabarits (PDF, fiche admin) n'aient pas a distinguer les deux cas.
          return [
              'id'        => 0,
              'firstname' => '',
              'realname'  => (string) ($this->fields['external_beneficiary_name'] ?? ''),
              'name'      => '',
              'email'     => (string) ($this->fields['external_beneficiary_contact'] ?? ''),
          ];
      }

       $user = new \User();
       $user->getFromDB((int) $this->fields['users_id']);
       // glpi_users ne porte pas de colonne email (elle vit dans glpi_useremails) :
       // on la fusionne ici pour que les gabarits (PDF, notifications) l'utilisent simplement.
       $fields = $user->fields;
       $fields['email'] = \UserEmail::getDefaultForUser((int) $this->fields['users_id']) ?: '';
       return $fields;
   }

   public function getTargetItem(): array {
       $itemtype = $this->fields['itemtype'];
       $item = new $itemtype();
       $item->getFromDB((int) $this->fields['items_id']);

       $fields = $item->fields;
       $fields['manufacturer_name'] = self::resolveManufacturerName($item);
       $fields['model_name'] = self::resolveModelName($item);

       return $fields;
   }

    /**
     * Marque (glpi_manufacturers) : champ manufacturers_id commun a tous les
     * actifs standards (Computer, Monitor, Peripheral, Phone) et aux actifs
     * personnalises qui l'activent. Public : reutilise telle quelle par
     * Maintenance::getTargetItem(), meme logique de resolution quel que soit
     * le type de fiche parente.
     */
   public static function resolveManufacturerName(CommonDBTM $item): string {
       $manufacturers_id = (int) ($item->fields['manufacturers_id'] ?? 0);
      if ($manufacturers_id <= 0) {
          return '';
      }
       return \Dropdown::getDropdownName('glpi_manufacturers', $manufacturers_id, false, true, false);
   }

    /**
     * Modele : contrairement a la marque, la table/FK varie selon le type
     * (computermodels_id, monitormodels_id...) — CommonDBTM::getModelClass()
     * resout cette convention generiquement pour n'importe quel itemtype.
     * Public : reutilise telle quelle par Maintenance::getTargetItem().
     */
   public static function resolveModelName(CommonDBTM $item): string {
       $modelClass = $item->getModelClass();
      if ($modelClass === null) {
          return '';
      }

       $fk = $modelClass::getForeignKeyField();
       $modelId = (int) ($item->fields[$fk] ?? 0);
      if ($modelId <= 0) {
          return '';
      }

       return \Dropdown::getDropdownName($modelClass::getTable(), $modelId, false, true, false);
   }

    /**
     * Titre lisible utilise pour nommer les Documents GLPI (non signe et signe) :
     * prenom + nom du beneficiaire, puis le materiel concerne, pour pouvoir les
     * retrouver facilement dans la liste des documents sans avoir a ouvrir chacun.
     */
   public function getDocumentTitle(): string {
       $user = $this->getBeneficiary();
       $userName = trim(\formatUserName(0, $user['name'] ?? '', $user['realname'] ?? '', $user['firstname'] ?? ''));

       $item = $this->getTargetItem();
       $itemName = $item['name'] ?? '';

       $type = self::getCanonicalTypeLabel((int) $this->fields['type']);

       // Date en tete : plusieurs assetsigns pour le meme couple (personne, materiel)
       // dans le temps (retour, echange, reaffectation) partagent sinon un nom
       // identique dans la liste des Documents, et le tri alphabetique par nom
       // ne suit pas la chronologie sans elle.
       $date = $this->fields['date_creation'] ? date('Y-m-d', strtotime($this->fields['date_creation'])) : date('Y-m-d');

       return trim(sprintf('%s — %s — %s (%s)', $date, $userName ?: '?', $itemName ?: $this->fields['itemtype'], $type));
   }

    /**
     * Libelle de type dans UNE SEULE langue fixe, volontairement independant de
     * getTypes() (traduit via __(), donc dependant de la session active).
     *
     * Le nom d'un Document GLPI est fige au moment de sa creation : le PDF non
     * signe est nomme pendant le hook item_add/item_update (session du
     * technicien qui a fait l'affectation), le PDF signe pendant submit() de
     * la page de signature (session du BENEFICIAIRE). Utiliser __() ici ferait
     * donc dependre le nom de qui a declenche l'action, avec un resultat
     * incoherent constate en conditions reelles : un meme type de remise
     * apparaissant tantot "(Assetsign) [signée]", tantot "(Handover) [signed]"
     * selon la langue du compte de la personne agissante — deux documents
     * de la MEME assetsign pouvant meme finir dans deux langues differentes.
     * Le nom de fichier est un identifiant interne de classement, pas un
     * contenu utilisateur : il n'a pas besoin d'etre traduit.
     */
   private static function getCanonicalTypeLabel(int $type): string {
       return Workflow\WorkflowTypeRegistry::get($type)->getCanonicalLabel();
   }

    /**
     * Libelle de materiel dans le PDF, meme logique que getCanonicalTypeLabel() :
     * getTypeName() natif de GLPI (Computer::getTypeName()...) est traduit via
     * __() cote coeur GLPI, donc lui aussi dependant de la session active.
     * Fixe en dur pour les 4 types geres par defaut ; pour un actif personnalise,
     * on garde son nom tel que defini par l'admin (getTypeName()) — un actif
     * personnalise n'a pas cette ambiguite, son nom est saisi une fois par
     * l'organisation, pas traduit dynamiquement par GLPI.
     */
   public static function getCanonicalItemtypeLabel(string $itemtype): string {
       return match ($itemtype) {
           'Computer'   => 'Ordinateur',
           'Monitor'    => 'Écran',
           'Peripheral' => 'Périphérique',
           'Phone'      => 'Téléphone',
           default      => $itemtype::getTypeName(1),
       };
   }

   public function getAccessories(): array {
       global $DB;
       $rows = $DB->request([
           'SELECT' => [
               'glpi_plugin_assetsign_accessories.id AS accessories_id',
               'glpi_plugin_assetsign_accessories.name',
               'glpi_plugin_assetsign_assetsignaccessories.quantity',
               'glpi_plugin_assetsign_assetsignaccessories.comment',
           ],
           'FROM'   => 'glpi_plugin_assetsign_assetsignaccessories',
           'INNER JOIN' => [
               'glpi_plugin_assetsign_accessories' => [
                   'FKEY' => [
                       'glpi_plugin_assetsign_assetsignaccessories' => 'plugin_assetsign_accessories_id',
                       'glpi_plugin_assetsign_accessories'       => 'id',
                   ],
               ],
           ],
           'WHERE' => ['glpi_plugin_assetsign_assetsignaccessories.plugin_assetsign_assetsigns_id' => $this->getID()],
           'ORDER' => 'glpi_plugin_assetsign_accessories.name',
       ]);
       return iterator_to_array($rows);
   }

    /**
     * Types de statuts pour lesquels le document PDF non signe peut encore
     * etre modifie (accessoires, observations, marqueurs de dommages,
     * regeneration...) : tout ce qui precede une vraie signature. Une fois
     * SIGNED/EXPIRED/CANCELLED, le PDF ne doit plus bouger (preuve figee).
     * Publique : reutilisee hors de cette classe par front/damagemarker.php
     * (verification avant d'accepter un ajout/suppression de marqueur).
     */
   public function isStillEditable(): bool {
       return in_array((int) $this->fields['status'], self::STATUSES_STILL_EDITABLE, true);
   }

    /**
     * Ajoute (ou met a jour la quantite d') un accessoire a cette remise, puis
     * regenere immediatement le PDF non signe pour qu'il reflete la liste a
     * jour — sans quoi le beneficiaire verrait un document qui ne mentionne
     * jamais les accessoires ajoutes apres la creation automatique de la remise.
     * Sans effet si la remise est deja signee/expiree/annulee.
     */
   public function addAccessory(int $accessories_id, int $quantity, string $comment = ''): void {
      if (!$this->isStillEditable()) {
          return;
      }
       AssetsignAccessory::attach($this->getID(), $accessories_id, max(1, $quantity), $comment);
       $this->regenerateUnsignedPdf();
   }

    /** Retire un accessoire de cette remise et regenere le PDF non signe. */
   public function removeAccessory(int $accessories_id): void {
      if (!$this->isStillEditable()) {
          return;
      }
       AssetsignAccessory::detach($this->getID(), $accessories_id);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Met a jour le champ "Observations" (libre, ex: etat constate du materiel)
     * et regenere le PDF non signe pour qu'il reflete le nouveau texte — meme
     * logique que add/removeAccessory(). Sans effet si la remise est deja
     * signee/expiree/annulee (le PDF signe est une preuve figee, cf.
     * isStillEditable()) ; le reglage global "enable_observations" (cf. Config)
     * est verifie cote formulaire/front, pas ici, pour que cette methode reste
     * utilisable telle quelle si l'appelant a deja fait ce controle.
     */
   public function updateObservations(string $observations): void {
      if (!$this->isStillEditable()) {
          return;
      }
       $this->update(['id' => $this->getID(), 'observations' => $observations]);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Distinct de updateObservations() : rempli par le BENEFICIAIRE lui-meme
     * depuis la page de signature (front/sign.php), pas par le technicien.
     * Meme garde (isStillEditable()) et meme regeneration du PDF non signe.
     * Le controle d'identite (ce champ n'est modifiable que par le vrai
     * beneficiaire de CETTE remise) est fait cote appelant
     * (SignController::assertCurrentUserIsBeneficiary()), pas ici — comme
     * updateObservations() qui delegue de meme le controle du reglage
     * "enable_observations" a son appelant.
     */
   public function updateBeneficiaryComment(string $comment): void {
      if (!$this->isStillEditable()) {
          return;
      }
       $this->update(['id' => $this->getID(), 'beneficiary_comment' => $comment]);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Renseigne ou corrige le prix/la date d'une Vente, puis regenere le PDF
     * non signe — necessaire pour une Vente declenchee automatiquement par
     * changement d'Etat (cf. handleStateBasedTrigger()), qui ne connait aucun
     * prix au moment de sa creation. Sans effet sur un autre type de fiche ou
     * une fiche deja signee/expiree/annulee.
     */
   public function updateVenteDetails(float $price, string $saleDate): void {
      if ((int) $this->fields['type'] !== self::TYPE_VENTE || !$this->isStillEditable()) {
          return;
      }
       VenteDetails::upsertForAssetsign($this->getID(), $price, $saleDate);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Regenere le PDF non signe apres ajout/modification/suppression d'un
     * repere d'etat des lieux visuel (DamageMarker). Les reperes sont geres
     * directement par front/damagemarker.php (pas par des methodes dediees
     * sur Assetsign, contrairement aux accessoires/observations/details de
     * vente) : ce point d'entree public lui permet de declencher la meme
     * regeneration qu'addAccessory()/updateObservations() sans exposer
     * regenerateUnsignedPdf() elle-meme. Sans effet si la remise n'est plus
     * editable. **Bug reel corrige** : sans cet appel, un repere ajoute par
     * un technicien apres la creation de la remise n'apparaissait jamais
     * sur le vrai PDF (contrairement a l'apercu, qui re-rend a chaque appel)
     * — constate en conditions reelles par un premier usage humain.
     */
   public function refreshDamageAnnotationPdf(): void {
       $this->regenerateUnsignedPdf();
   }

    /**
     * Reconstruit le PDF non signe (nouveau Document, l'ancien est purge) a
     * partir de l'etat courant de la remise. Utilise apres toute modification
     * des accessoires ; n'a aucun effet sur une remise deja signee.
     */
   private function regenerateUnsignedPdf(): void {
      if (!$this->isStillEditable()) {
          return;
      }

       $oldDocumentId = (int) $this->fields['document_id_unsigned'];

       $builder = new Pdf\HandoverPdfBuilder();
       $document = $builder->build($this);

       $this->update([
           'id'                   => $this->getID(),
           'document_id_unsigned' => $document->getID(),
       ]);

      if ($oldDocumentId > 0 && $oldDocumentId !== (int) $document->getID()) {
          $old = new Document();
         if ($old->getFromDB($oldDocumentId)) {
            $old->delete(['id' => $oldDocumentId], true);
         }
      }
   }

   public function getTemplate(): ?Template {
      if (!$this->fields['plugin_assetsign_templates_id']) {
          return null;
      }
       $template = new Template();
       return $template->getFromDB($this->fields['plugin_assetsign_templates_id']) ? $template : null;
   }

    // ================================================================================
    // Transitions de statut
    // ================================================================================

   public function markViewed(): void {
      if ((int) $this->fields['status'] === self::STATUS_SENT) {
          $this->update([
              'id'         => $this->getID(),
              'status'     => self::STATUS_VIEWED,
              'date_viewed'=> date('Y-m-d H:i:s'),
          ]);
      }
   }

   public function markSigned(string $signedPdfPath, array $proof): void {
       // "[signée]" volontairement non traduit : cf. le commentaire de
       // getCanonicalTypeLabel() — traduire ici ferait dependre la langue du
       // nom de fichier de la session du beneficiaire qui signe, incoherent
       // avec le suffixe "[non signée]" du PDF non signe (deja fixe en dur).
       $document = $this->attachDocument($signedPdfPath, $this->getDocumentTitle() . ' [signée]');

       Signature::recordProofForAssetsign($this, $proof);

       $this->update([
           'id'                 => $this->getID(),
           'status'             => self::STATUS_SIGNED,
           'document_id_signed' => $document->getID(),
           'date_signed'        => date('Y-m-d H:i:s'),
       ]);

       Token::invalidateForAssetsign($this->getID());
       NotificationEvent::raiseEvent('signed', $this);
   }

   private function attachDocument(string $filePath, string $name): Document {
       // Document::moveDocument() lit le fichier source dans GLPI_TMP_DIR :
       // $filePath doit y avoir ete ecrit prealablement (cf. SignatureStamper::apply()).
       $document = new Document();
       $documents_id = $document->add([
           'name'          => $name,
           'entities_id'   => $this->fields['entities_id'],
           '_filename'     => [basename($filePath)],
           '_tag_filename' => [basename($filePath)],
       ]);
       $document->getFromDB($documents_id);

       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => $this->fields['itemtype'],
           'items_id'     => $this->fields['items_id'],
       ]);
       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => 'User',
           'items_id'     => $this->fields['users_id'],
       ]);

       return $document;
   }

    // ================================================================================
    // Crons
    // ================================================================================

   public static function cronInfo(string $name): array {
       return match ($name) {
           'assetsignReminders'     => ['description' => __('Envoie les relances de signature dues', 'assetsign')],
           'assetsignExpire'        => ['description' => __('Marque comme expirées les remises hors délai', 'assetsign')],
           'assetsignExpiryWarning' => ['description' => __('Alerte le technicien des remises sur le point d\'expirer', 'assetsign')],
           default               => [],
       };
   }

    /**
     * Envoie une relance immediatement pour CETTE remise, sans attendre le delai
     * planifie — utilise aussi bien par le bouton "Relancer maintenant" (action
     * manuelle d'un technicien) que par runReminders() (execution automatique).
     *
     * @throws \RuntimeException si le statut ne permet pas de relance (deja signee, expiree...)
     */
   public function sendReminderNow(): void {
      if (!in_array((int) $this->fields['status'], self::STATUSES_AWAITING_SIGNATURE, true)) {
          throw new \RuntimeException(__('Cette remise ne peut plus être relancée (déjà signée ou expirée).', 'assetsign'));
      }

       $config = Config::getForEntity((int) $this->fields['entities_id']);
       $provider = Provider\ProviderFactory::for($config);

       // Nouvelle demande a chaque relance : le jeton precedent est invalide dans le meme mouvement.
       $provider->createRequest($this, '');
       NotificationEvent::raiseEvent('reminder', $this);

       $newCount = (int) $this->fields['reminder_count'] + 1;
       Reminder::log($this);
       $this->update(['id' => $this->getID(), 'reminder_count' => $newCount]);
   }

    /**
     * Logique de relance automatique, independante de tout mecanisme de
     * planification — appelee par le CronTask GLPI (cronAssetsignReminders) et par
     * la commande console plugins:assetsign:run-reminders (cf. USER_GUIDE.md,
     * section "Alternative au cron GLPI").
     */
   public static function runReminders(): int {
       global $DB;

       $rows = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['status' => self::STATUSES_AWAITING_SIGNATURE, 'is_deleted' => 0],
       ]);

       $count = 0;
       $configByEntity = [];
      foreach ($rows as $row) {
          // $row porte deja toutes les colonnes (pas de restriction SELECT ci-dessus) :
          // reconstituer l'objet directement depuis la ligne evite une deuxieme
          // requete (equivalente) que ferait getFromDB($row['id']).
          $assetsign = new self();
          $assetsign->fields = $row;

         if (self::hasMissingDateSent($assetsign->fields)) {
             continue;
         }

          // Config resolue pour l'entite DE CETTE ASSETSIGN (pas un reglage global) :
          // deux entites peuvent avoir des delais de relance differents, cf.
          // INSTALLATION.md, section "3. Configurer". Mise en cache
          // le temps de cette boucle : plusieurs assetsigns partagent souvent la
          // meme entite, inutile de refaire la meme resolution pour chacune.
          $entityId = (int) $row['entities_id'];
          $assetsignConfig = $configByEntity[$entityId] ??= Config::getForEntity($entityId);

          $provider = Provider\ProviderFactory::for($assetsignConfig);
         if ($provider->managesReminders()) {
            continue; // le prestataire (SaaS) gere ses propres relances
         }

          $max = (int) $assetsignConfig->fields['max_reminders'];
         if ($max > 0 && (int) $assetsign->fields['reminder_count'] >= $max) {
             continue;
         }

          $delays = $assetsignConfig->getReminderDelays();
          $daysSinceSend = self::daysSince($assetsign->fields['date_sent']);
          $reminderIndex = (int) $assetsign->fields['reminder_count'];
          $delayIndex = min($reminderIndex, count($delays) - 1);
          $dueDay = array_sum(array_slice($delays, 0, $reminderIndex + 1, true)) ?: $delays[$delayIndex];

         if ($daysSinceSend >= $dueDay) {
             $assetsign->sendReminderNow();
             $count++;
         }
      }

       return $count;
   }

    /**
     * Logique d'expiration automatique, independante de tout mecanisme de
     * planification — appelee par le CronTask GLPI (cronAssetsignExpire) et par
     * la commande console plugins:assetsign:run-expiration.
     */
   public static function runExpiration(): int {
       global $DB;

       // Le filtre de delai ne peut plus se faire dans le SQL (comme avant) :
       // chaque remise doit etre comparee a la duree de validite resolue pour
       // SA PROPRE entite (Config::getForEntity()), pas une valeur globale
       // unique — cf. INSTALLATION.md, section "3. Configurer".
       $rows = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => [
               'status'     => self::STATUSES_AWAITING_SIGNATURE,
               'is_deleted' => 0,
           ],
       ]);

       $count = 0;
       $configByEntity = [];
      foreach ($rows as $row) {
          $assetsign = new self();
          $assetsign->fields = $row;

         if (self::hasMissingDateSent($assetsign->fields)) {
             continue;
         }

          $entityId = (int) $row['entities_id'];
          $assetsignConfig = $configByEntity[$entityId] ??= Config::getForEntity($entityId);
          $validity = (int) $assetsignConfig->fields['link_validity_days'];

          $daysSinceSend = self::daysSince($assetsign->fields['date_sent']);
         if ($daysSinceSend <= $validity) {
            continue;
         }

          $assetsign->update([
              'id'          => $assetsign->getID(),
              'status'      => self::STATUS_EXPIRED,
              'date_expired'=> date('Y-m-d H:i:s'),
          ]);
          Token::invalidateForAssetsign($assetsign->getID());
          NotificationEvent::raiseEvent('expired', $assetsign);
          $count++;
      }

       return $count;
   }

    /**
     * Alerte le technicien (users_id_tech) qu'une remise est sur le point
     * d'expirer, quelques jours avant l'echeance reelle — sans quoi la seule
     * notification liee a l'expiration arrive une fois le lien deja invalide
     * (cf. l'evenement "expired"), trop tard pour que le technicien agisse
     * autrement (appeler le beneficiaire, passer sur place...). Desactivable
     * par entite (`expiry_warning_days` = 0). Envoyee une seule fois par
     * assetsign (`expiry_warning_sent`), pas a chaque execution du cron.
     */
   public static function runExpiryWarnings(): int {
       global $DB;

       $rows = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => [
               'status'               => self::STATUSES_AWAITING_SIGNATURE,
               'is_deleted'           => 0,
               'expiry_warning_sent'  => 0,
           ],
       ]);

       $count = 0;
       $configByEntity = [];
      foreach ($rows as $row) {
          $assetsign = new self();
          $assetsign->fields = $row;

         if (self::hasMissingDateSent($assetsign->fields)) {
             continue;
         }

          $entityId = (int) $row['entities_id'];
          $assetsignConfig = $configByEntity[$entityId] ??= Config::getForEntity($entityId);
          $validity = (int) $assetsignConfig->fields['link_validity_days'];
          $warningDays = (int) $assetsignConfig->fields['expiry_warning_days'];

         if ($warningDays <= 0) {
            continue; // alerte desactivee pour cette entite
         }

          $daysSinceSend = self::daysSince($assetsign->fields['date_sent']);
         if ($daysSinceSend < $validity - $warningDays || $daysSinceSend > $validity) {
             continue; // pas encore dans la fenetre d'alerte, ou deja hors delai (l'expiration s'en charge)
         }

          $assetsign->update(['id' => $assetsign->getID(), 'expiry_warning_sent' => 1]);
          NotificationEvent::raiseEvent('expiring_soon', $assetsign);
          $count++;
      }

       return $count;
   }

    /** Nombre de jours pleins ecoules depuis une date (format GLPI 'Y-m-d H:i:s'). */
   private static function daysSince(string $dateTime): int {
       return (int) floor((time() - strtotime($dateTime)) / DAY_TIMESTAMP);
   }

    /**
     * Garde-fou defensif pour runReminders()/runExpiration()/runExpiryWarnings() :
     * une remise au statut Envoye/Consulte DOIT toujours avoir date_sent
     * renseignee (launchWorkflow() met a jour status et date_sent ensemble,
     * dans le meme update() - cf. plus haut), donc structurellement impossible
     * via le code reel du plugin. Mais une ligne corrompue ou importee a la
     * main (constate en pratique avec des donnees de test manipulees
     * directement en base) ferait sinon planter TOUT le lot avec une
     * TypeError (daysSince() exige un string, pas null), bloquant les
     * relances/expirations de toutes les AUTRES assetsigns du meme passage.
     * Ignoree et journalisee plutot que de laisser planter le lot entier.
     */
   private static function hasMissingDateSent(array $fields): bool {
      if ($fields['date_sent'] !== null) {
          return false;
      }
       \Toolbox::logInFile('assetsign', sprintf(
           "Assetsign #%d (statut %d) sans date_sent : donnée incohérente, ignorée pour les tâches planifiées.\n",
           $fields['id'],
           $fields['status']
       ));
       return true;
   }

   public static function cronAssetsignReminders(CronTask $task): int {
       $count = self::runReminders();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

   public static function cronAssetsignExpiryWarning(CronTask $task): int {
       $count = self::runExpiryWarnings();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

   public static function cronAssetsignExpire(CronTask $task): int {
       $count = self::runExpiration();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

    // ================================================================================
    // Installation
    // ================================================================================

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $query = "CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `users_id_tech` int unsigned NOT NULL DEFAULT 0,
                `beneficiary_type` tinyint unsigned NOT NULL DEFAULT 0,
                `external_beneficiary_name` varchar(255) DEFAULT NULL,
                `external_beneficiary_contact` varchar(255) DEFAULT NULL,
                `plugin_assetsign_templates_id` int unsigned NOT NULL DEFAULT 0,
                `type` tinyint NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 0,
                `document_id_unsigned` int unsigned NOT NULL DEFAULT 0,
                `document_id_signed` int unsigned NOT NULL DEFAULT 0,
                `reminder_count` int unsigned NOT NULL DEFAULT 0,
                `date_sent` timestamp NULL DEFAULT NULL,
                `date_viewed` timestamp NULL DEFAULT NULL,
                `date_signed` timestamp NULL DEFAULT NULL,
                `date_expired` timestamp NULL DEFAULT NULL,
                `expiry_warning_sent` tinyint NOT NULL DEFAULT 0,
                `observations` text,
                `beneficiary_comment` text,
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `users_id` (`users_id`),
                KEY `users_id_tech` (`users_id_tech`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `status` (`status`),
                KEY `type` (`type`),
                KEY `plugin_assetsign_templates_id` (`plugin_assetsign_templates_id`),
                KEY `is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
          $DB->doQuery($query);
      } else {
         if (!$DB->fieldExists($table, 'expiry_warning_sent')) {
             // 'bool' (pas 'tinyint') : seul un type "logique" reconnu par
             // Migration::fieldFormat() fait que 'value' produise reellement une
             // clause DEFAULT — cf. le commentaire equivalent dans Config::install().
             $migration->addField($table, 'expiry_warning_sent', 'bool', ['value' => 0, 'after' => 'date_expired']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'observations')) {
             $migration->addField($table, 'observations', 'text', ['after' => 'expiry_warning_sent']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'beneficiary_comment')) {
             // Distinct de 'observations' (rempli par le TECHNICIEN) : ce champ est
             // rempli par le BENEFICIAIRE lui-meme depuis la page de signature.
             $migration->addField($table, 'beneficiary_comment', 'text', ['after' => 'observations']);
             $migration->migrationOneTable($table);
         }
          // Audit code mort : 'locations_id' (archivage jamais recherche/affiche,
          // cf. l'ancien commentaire de maybeLocated()) et 'comment' (toujours
          // insere vide par createAssetsign()/createManual(), jamais lu nulle part)
          // n'ont jamais ete exploites — colonnes retirees.
         if ($DB->fieldExists($table, 'locations_id')) {
             $migration->dropField($table, 'locations_id');
         }
         if ($DB->fieldExists($table, 'comment')) {
             $migration->dropField($table, 'comment');
         }
         if (!$DB->fieldExists($table, 'beneficiary_type')) {
             // Don/Vente a un beneficiaire EXTERIEUR a l'entreprise (sans
             // compte GLPI), en plus du cas interne existant (users_id) —
             // cf. BENEFICIARY_INTERNAL/BENEFICIARY_EXTERNAL, createManual().
             // 'bool'-like ('integer', pas le type SQL brut 'tinyint') :
             // seul un type "logique" reconnu par Migration::fieldFormat()
             // fait que 'value' produise reellement une clause DEFAULT —
             // meme piege deja documenté sur expiry_warning_sent ci-dessus.
             $migration->addField($table, 'beneficiary_type', 'integer', ['value' => 0, 'after' => 'users_id_tech']);
             $migration->addField($table, 'external_beneficiary_name', 'string', ['after' => 'beneficiary_type']);
             $migration->addField($table, 'external_beneficiary_contact', 'string', ['after' => 'external_beneficiary_name']);
             $migration->migrationOneTable($table);
         }
      }

       self::seedDefaultDisplayPreferences();
   }

    /**
     * Sans ca, la liste "Gestion des fiches" (Administration > Assetsign &
     * signature > Assetsigns) n'affiche par defaut QUE la colonne ID (repli
     * standard de GLPI quand aucune preference d'affichage n'existe pour un
     * itemtype, cf. SearchOption::getDefaultToView()) — un administrateur
     * doit alors deviner qu'il faut ouvrir le selecteur de colonnes pour
     * retrouver, par exemple, les liens de telechargement des PDF. Seme une
     * seule fois (ligne users_id=0 = preference par defaut, cf.
     * DisplayPreference::getForTypeUser()) : ne s'execute plus des qu'au
     * moins une ligne existe deja pour cet itemtype, pour ne jamais ecraser
     * une personnalisation (globale ou par utilisateur) deja faite.
     */
   private static function seedDefaultDisplayPreferences(): void {
       global $DB;

       $alreadySeeded = $DB->request([
           'FROM'  => 'glpi_displaypreferences',
           'WHERE' => ['itemtype' => self::class],
           'LIMIT' => 1,
       ])->count() > 0;

      if ($alreadySeeded) {
          return;
      }

       $rank = 1;
      foreach ([4, 5, 6, 7, 10, 11, 12] as $searchOptionId) {
          $DB->insert('glpi_displaypreferences', [
              'itemtype'  => self::class,
              'num'       => $searchOptionId,
              'rank'      => $rank++,
              'users_id'  => 0,
              'interface' => 'central',
          ]);
      }
   }
}
