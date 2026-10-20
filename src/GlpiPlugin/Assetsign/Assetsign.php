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
class Assetsign extends Compat\Base\AssetsignBase
{
   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

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
    /**
     * Signatures multiples (issue #143) : le beneficiaire a signe, la fiche
     * attend desormais la contre-signature du responsable hierarchique
     * (cf. cosigner_users_id, Config::enable_co_signature). Statut
     * intermediaire distinct de STATUS_SIGNED (qui reste reserve a une fiche
     * ENTIEREMENT signee) — voir markAwaitingCosignature()/markCosigned().
     */
   public const STATUS_AWAITING_COSIGNATURE = 9;

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
     * Destruction de materiel (issue #78, "fin de vie structuree") : meme
     * declenchement manuel que Don/Vente, prestataire/certificat propres a ce
     * type stockes dans DestructionDetails (meme motif 1-vers-1 que VenteDetails).
     */
   public const TYPE_DESTRUCTION = 5;

    /**
     * Types autorises pour une creation MANUELLE (cf. createManual()) : la
     * remise/restitution restent exclusivement automatiques (hooks
     * d'affectation/etat, cf. handleItemAssignment()) — un technicien ne doit
     * pas pouvoir en creer une par ce canal, qui contournerait la deduplication
     * faite par cancelPendingAssetsignsFor() et pourrait laisser des fiches
     * orphelines pour un meme materiel.
     */
   private const MANUALLY_CREATABLE_TYPES = [self::TYPE_DON, self::TYPE_VENTE, self::TYPE_DESTRUCTION];

    /**
     * Statuts "envoyee mais pas encore signee" : utilise par le bouton "Relancer
     * maintenant", les crons de relance/expiration, et le widget "En attente" du
     * tableau de bord (Dashboard\CardProvider::pending()) — une seule definition
     * partagee plutot que ce couple de statuts recopie a chaque endroit.
     *
     * STATUS_AWAITING_COSIGNATURE inclus (issue #143) : ces trois crons/widget
     * n'ont pas besoin d'un second jeu de champs "cosigner_*" pour la relance/
     * expiration (cf. le commentaire sur date_sent/date_viewed/reminder_count
     * dans install() plus bas) — ces colonnes sont reutilisees telles quelles
     * pour suivre l'etape EN COURS, que ce soit celle du beneficiaire ou celle
     * du responsable. sendReminderNow() distingue ensuite qui relancer/quel
     * jeton regenerer selon le statut exact.
     */
   public const STATUSES_AWAITING_SIGNATURE = [self::STATUS_SENT, self::STATUS_VIEWED, self::STATUS_AWAITING_COSIGNATURE];

    /**
     * Statuts pour lesquels le PDF non signe peut encore etre modifie (accessoires,
     * regeneration...) : tout ce qui precede une vraie signature. Une fois
     * SIGNED/EXPIRED/CANCELLED, le PDF ne doit plus bouger (preuve figee).
     */
   // Publique depuis l'issue #166 : AssetsignItem s'en sert pour refuser un accessoire deja
   // present sur une autre fiche en attente.
   public const STATUSES_STILL_EDITABLE = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_VIEWED];

   public static function getTypeName($nb = 0): string {
       return _n('Attribution', 'Attributions', $nb, 'assetsign');
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
       $menu = self::withoutAddLink(parent::getMenuContent());
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
      if (ChecklistItem::canView()) {
          $menu['options'][ChecklistItem::class] = [
              'title' => ChecklistItem::getTypeName(Session::getPluralNumber()),
              'page'  => ChecklistItem::getSearchURL(false),
              'icon'  => ChecklistItem::getIcon(),
              'links' => [
                  'search' => ChecklistItem::getSearchURL(false),
                  'add'    => ChecklistItem::getFormURL(false),
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

       // Row link (issue: la liste "Gestion des fiches" n'offrait aucun moyen de revenir a
       // showForm() : la colonne 'id' etait un simple nombre non cliquable, et items_id (colonne 3
       // ci-dessous) pointe vers le materiel cible, pas vers cette fiche Assetsign elle-meme) -
       // 'itemlink' n'a besoin que d'*un* champ sur cette table pour deriver getFormURLWithID(),
       // meme pattern que PluginVulnerabilitymanagerRisk::rawSearchOptions() (plugin jumeau).
       $tab[] = [
           'id'       => 1,
           'table'    => self::getTable(),
           'field'    => 'id',
           'name'     => __('ID'),
           'datatype' => 'itemlink',
           'itemtype' => self::class,
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
           'name'     => __('Type d\'attribution', 'assetsign'),
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
       // Delegue actuel (issue #115) : meme motif que la colonne 4 (jointe sur
       // glpi_users), colonne dediee car il s'agit d'une jointure DIFFERENTE
       // (linkfield delegated_users_id, pas users_id) - vide pour toute fiche
       // non deleguee.
       $tab[] = [
           'id'       => 13,
           'table'    => 'glpi_users',
           'field'    => 'name',
           'linkfield' => 'delegated_users_id',
           'name'     => __('Signature déléguée à', 'assetsign'),
           'datatype' => 'itemlink',
           'itemlink_type' => 'User',
       ];

       // Issue #193 : pret temporaire, vide pour une attribution definitive.
       $tab[] = [
           'id'       => 14,
           'table'    => self::getTable(),
           'field'    => 'loan_until',
           'name'     => __('Retour prévu (prêt)', 'assetsign'),
           'datatype' => 'date',
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
           // Signature sur place (issue #152), cf. canSignInPerson()/startInPersonSignature().
           'can_sign_in_person' => !$this->isNewID($ID)
               && \Session::haveRight(self::$rightname, UPDATE)
               && $this->canSignInPerson(),
           'accessories'          => $this->isNewID($ID) ? [] : $this->getAccessories(),
           'can_edit_accessories' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Issue #193 : pret temporaire, modifiable meme apres signature (prolongation).
           'can_edit_loan'        => !$this->isNewID($ID)
               && (int) $this->fields['type'] === self::TYPE_HANDOVER
               && !in_array((int) $this->fields['status'], [self::STATUS_CANCELLED, self::STATUS_EXPIRED], true)
               && \Session::haveRight(self::$rightname, UPDATE),
           'loan_returned'        => !$this->isNewID($ID) && !empty($this->fields['loan_until']) && Loan::isReturned($this->fields),
           'today'                => date('Y-m-d'),
           // Issue #166 : objets du parc remis avec le materiel, et peripheriques connectes pas encore
           // ajoutes (proposes tant que la fiche est modifiable).
           'inventoried_items'    => $this->isNewID($ID) ? [] : $this->getInventoriedItems(),
           'suggested_items'      => $this->isNewID($ID) || !$this->isStillEditable() ? [] : $this->getSuggestedConnectedItems(),
           'inventoried_itemtypes' => AssetsignItem::getAllowedItemtypes(),
           // Issue #178 : controle du retour (feuilles de restitution).
           'is_return'            => (int) ($this->fields['type'] ?? -1) === self::TYPE_RETURN,
           'return_states'        => AssetsignItem::getReturnStates(),
           // Issue #180 : materiel disponible propose en premier.
           'available_items'      => $this->isNewID($ID) || !$this->isStillEditable() ? [] : AssetsignItem::getAvailableItems($this),
           // Issue #163 : brouillon modifiable avant l'envoi (beneficiaire, modele, apercu).
           'is_draft'             => !$this->isNewID($ID) && (int) $this->fields['status'] === self::STATUS_DRAFT,
           'draft_template_condition' => ['type' => (int) ($this->fields['type'] ?? 0), 'is_active' => 1],
           // Le champ Observations est desactivable globalement (Config::enable_observations) :
           // une entite qui n'en a pas l'usage ne voit meme pas la section.
           'observations_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_observations'],
           // Etat des lieux visuel (etat_des_lieux) : meme reglage/permission que
           // les accessoires (isStillEditable() + droit UPDATE).
           'damage_annotation_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_damage_annotation'],
           'damage_views'   => DamageMarker::getViewLabels(),
           'damage_images'  => DamageMarker::getViewImageFilenames($this->fields['itemtype'] ?? null),
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
           // Organisme beneficiaire (Don) / prestataire (Destruction) : meme motif
           // que vente_details ci-dessus (issue #78, "fin de vie structuree").
           'type_don'         => self::TYPE_DON,
           'don_details'      => (!$this->isNewID($ID) && (int) $this->fields['type'] === self::TYPE_DON)
               ? DonDetails::getForAssetsign((int) $ID)
               : null,
           'can_edit_don_details' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           'type_destruction' => self::TYPE_DESTRUCTION,
           'destruction_details' => (!$this->isNewID($ID) && (int) $this->fields['type'] === self::TYPE_DESTRUCTION)
               ? DestructionDetails::getForAssetsign((int) $ID)
               : null,
           'can_edit_destruction_details' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Justificatif (Don) / certificat (Destruction) joints via
           // attachUploadedDocument() (issue #78) : cette fiche n'a pas d'onglet
           // "Documents" natif (showForm() est un affichage autonome, jamais
           // enveloppe par le cadre d'onglets standard de CommonDBTM) - sans ce
           // bloc, un document pourtant bien attache en base (Document_Item)
           // resterait invisible depuis la fiche elle-meme.
           'attached_documents' => $this->isNewID($ID) ? [] : self::getAttachedDocuments((int) $ID),
           // Preuve de signature (adresse IP, empreinte du document...) : la seule
           // trace de ces informations jusqu'ici etait a l'interieur du PDF signe
           // lui-meme (cf. handover.html.twig) — rien ne les affichait cote GLPI,
           // alors que glpi_plugin_assetsign_signatures les enregistre a chaque
           // signature (Signature::recordProofForAssetsign(), appele par markSigned()).
           //
           // Signatures multiples (issue #143) : getAllForAssetsign() (toutes les
           // preuves, pas seulement la plus recente) des que la fiche a signe au
           // moins une fois OU attend une contre-signature — sans ca, la preuve du
           // beneficiaire deviendrait invisible cote admin des que le responsable
           // contre-signe (Signature::getForAssetsign() ne renverrait plus alors
           // que la preuve la plus recente, celle du responsable).
           'signature_proofs' => (!$this->isNewID($ID) && in_array((int) $this->fields['status'], [self::STATUS_SIGNED, self::STATUS_AWAITING_COSIGNATURE], true))
               ? Signature::getAllForAssetsign((int) $ID)
               : [],
           'cosigner'         => $this->isNewID($ID) ? null : $this->getCosigner(),
           'cosigner_pending' => !$this->isNewID($ID) && (int) $this->fields['status'] === self::STATUS_AWAITING_COSIGNATURE,
           // Delegation de signature (issue #115) : bouton "Deleguer" affiche
           // seulement si le reglage entite est actif ET que le beneficiaire
           // est interne (cf. delegateSignatureTo()) — meme droit/garde que le
           // reste de cette fiche (isStillEditable() + droit UPDATE).
           'delegation_enabled' => !$this->isNewID($ID)
               && (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_signature_delegation']
               && (int) $this->fields['beneficiary_type'] === self::BENEFICIARY_INTERNAL,
           'delegate'         => $this->isNewID($ID) ? null : $this->getDelegate(),
           'can_delegate'     => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Checklist qualite (cf. ROADMAP.md V1, issue #74) : resultats deja
           // enregistres TOUJOURS affiches (meme un point desactive depuis), le
           // formulaire d'edition uniquement si applicable ET encore modifiable.
           'checklist_results'   => $this->isNewID($ID) ? [] : $this->getChecklistResults(),
           'checklist_items'     => $this->isNewID($ID) ? [] : ChecklistItem::getActiveItemsForMovementType((int) $this->fields['type']),
           'checklist_values'    => $this->isNewID($ID) ? [] : $this->getChecklistValuesByItemId(),
           'can_edit_checklist'  => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
           // Kit d'accessoires (cf. ROADMAP.md V3, issue #83) : section affichee
           // uniquement pour Attribution/Restitution (types_handover/type_return,
           // les deux seuls types pour lesquels un "controle au retour" a un
           // sens), meme droit/garde que le reste de cette fiche (can_edit_accessories,
           // reutilise plutot qu'invente : meme isStillEditable() + droit UPDATE).
           'type_handover'    => self::TYPE_HANDOVER,
           'type_return'      => self::TYPE_RETURN,
           'kit'              => $this->isNewID($ID) ? null : $this->getKit(),
           'kit_completeness' => $this->isNewID($ID) ? null : $this->getKitCompleteness(),
           // Issue #183 : ticket rattache et tickets ouverts du beneficiaire.
           'linked_ticket'    => $this->isNewID($ID) || (int) ($this->fields['tickets_id'] ?? 0) === 0 ? null : self::ticketLabel((int) $this->fields['tickets_id']),
           'open_tickets'     => $this->isNewID($ID) || !$this->isStillEditable() ? [] : $this->getBeneficiaryOpenTickets(),
           // Issue #181 : lignes « type d'element GLPI » du kit et objets reels choisis.
           'kit_item_lines'   => $this->isNewID($ID) ? [] : $this->getKitItemLines(),
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
            echo __('Envoyer une relance de signature aux attributions sélectionnées ?', 'assetsign');
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
      if (!Session::haveRight(self::$rightname, READ)) {
          return '';
      }
      if ($item->getType() === 'User') {
          $count = countElementsInTable(self::getTable(), ['users_id' => $item->getID(), 'is_deleted' => 0]);
          return self::createTabEntry(__('Attributions', 'assetsign'), $count);
      }
      if ($item->getType() === 'Ticket') {
          $count = countElementsInTable(self::getTable(), ['tickets_id' => $item->getID(), 'is_deleted' => 0]);
          return self::createTabEntry(__('Remise de matériel', 'assetsign'), $count);
      }
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return '';
      }
       $count = countElementsInTable(
           self::getTable(),
           ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_deleted' => 0]
       );
       return self::createTabEntry(__('Attributions', 'assetsign'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!Session::haveRight(self::$rightname, READ)) {
          return false;
      }
      if (!($item instanceof CommonDBTM)) {
          return false;
      }
      if ($item->getType() === 'User') {
          self::showForUser((int) $item->getID());
          return true;
      }
      if ($item instanceof \Ticket) {
          self::showForTicket($item);
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
      if ($config->fields['enable_destruction']) {
          $manualTypes[self::TYPE_DESTRUCTION] = Workflow\WorkflowTypeRegistry::get(self::TYPE_DESTRUCTION)->getLabel();
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
           'type_don'      => self::TYPE_DON,
           'type_destruction' => self::TYPE_DESTRUCTION,
           'can_create_manual' => $manualTypes !== [] && Session::haveRight(self::$rightname, UPDATE),
           'show_item_column' => false,
           'csrf_token'    => \Session::getNewCSRFToken(),
           'prefill_type'  => $prefillType,
           // Issue #170 : bouton d'envoi des brouillons d'attribution.
           'can_send_drafts' => Session::haveRight(self::$rightname, UPDATE),
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
           'WHERE' => ['users_id' => $users_id, 'is_deleted' => 0]
               + getEntitiesRestrictCriteria(self::getTable(), '', '', true),
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

       // Issue #157 : depart de la personne — restitution groupee de tout son materiel.
      if ($canAssign) {
          self::showDepartureCard($users_id);
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
     * Issue #157 : carte « Départ » de l'onglet Assetsign d'un utilisateur — lien vers son
     * dossier de depart ouvert, sinon bouton « Préparer le départ ».
     */
   private static function showDepartureCard(int $users_id): void {
       global $CFG_GLPI;

       $formUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/departure.form.php';
       $open = Departure::findOpenFor($users_id);

       echo '<div class="card mb-3"><div class="card-body d-flex align-items-center gap-3">';
       echo '<i class="ti ti-door-exit fs-2 text-muted"></i><div class="flex-grow-1">';
       echo '<strong>' . __('Départ de la personne', 'assetsign') . '</strong><div class="text-muted small">';
      if ($open !== null) {
          echo htmlescape(sprintf(
              __('Départ en cours : %d matériel(s) à restituer.', 'assetsign'),
              count($open->getChildren())
          ));
          echo '</div></div>';
          echo '<a class="btn btn-outline-primary" href="' . htmlescape($formUrl . '?id=' . $open->getID()) . '">'
              . '<i class="ti ti-eye me-1"></i>' . __('Voir le départ', 'assetsign') . '</a>';
      } else {
          echo __('Une seule fiche de restitution groupée pour tout le matériel affecté, signée en une fois.', 'assetsign');
          echo '</div></div>';
          echo '<form method="post" action="' . htmlescape($formUrl) . '" class="m-0">';
          echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape(Session::getNewCSRFToken()) . '">';
          echo '<input type="hidden" name="users_id" value="' . $users_id . '">';
          echo '<button type="submit" name="prepare" value="1" class="btn btn-primary">'
              . '<i class="ti ti-door-exit me-1"></i>' . __('Préparer le départ', 'assetsign') . '</button></form>';
      }
       echo '</div></div>';
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
           self::STATUS_AWAITING_COSIGNATURE => __('Signé par le bénéficiaire, en attente du responsable', 'assetsign'),
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

       // Issue #174 : element attribue au beneficiaire parce qu'il est remis avec un autre materiel
       // (deja sur la feuille de ce materiel) : pas de feuille separee.
      if (!empty($item->input['_assetsign_handed_item'])) {
          return;
      }

       // Element connecte a un poste (onglet Connexions) qui recoit le meme utilisateur que ce poste :
       // c'est la recopie automatique de GLPI (Computer::post_updateItem(), option « mise a jour des
       // elements connectes »). Il est couvert par la feuille du poste (ou il est propose comme
       // element connecte) : pas de feuille separee, ni d'e-mail en plus.
      if (self::followsItsConnectedAsset($item)) {
          return;
      }

       // Issue #163 : l'inventaire automatique peut vider puis remettre le champ Utilisateur (agent qui
       // ne remonte plus l'utilisateur, mouvement interne...) ; sur demande, ces changements ne
       // declenchent aucune feuille. GLPI marque ces mises a jour par '_auto' (MainAsset::handleInput()).
      if ((int) ($config->fields['ignore_inventory_changes'] ?? 0) === 1 && !empty($item->input['_auto'])) {
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

       // Reforme (cf. ROADMAP.md, issue #78) : effet de bord PUR sur Infocom::decommission_date
       // (champ natif GLPI), totalement independant du reste de cette methode -
       // ne cree jamais de fiche Assetsign, ne "return" jamais ici, et n'empeche
       // donc pas un meme changement d'Etat de declencher AUSSI un don/une vente
       // si l'administrateur a configure le meme Etat pour les deux (cas legitime,
       // ex: "Mis au rebut" a la fois vendeur de pieces detachees et reforme).
      if (in_array($newState, $config->getReformeStates(), true)) {
          self::writeDecommissionDateIfMissing($item);
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
           && (in_array($newState, $config->getDonationStates(), true)
               || in_array($newState, $config->getVenteStates(), true)
               || in_array($newState, $config->getDestructionStates(), true))
       ) {
          $isDon = in_array($newState, $config->getDonationStates(), true);
          $isVente = !$isDon && in_array($newState, $config->getVenteStates(), true);
           $targetType = $isDon ? self::TYPE_DON : ($isVente ? self::TYPE_VENTE : self::TYPE_DESTRUCTION);
           $targetLabel = $isDon ? __('don', 'assetsign') : ($isVente ? __('vente', 'assetsign') : __('destruction', 'assetsign'));
           $link = $item::getFormURLWithID($item->getID())
               . '&forcetab=' . urlencode(self::class . '$1')
               . '&tab_params[assetsign_prefill_type]=' . $targetType;
          \Session::addMessageAfterRedirect(
              sprintf(
                  __('Ce matériel n\'a pas d\'utilisateur assigné : <a href="%s">créez maintenant la fiche de %s</a> (bénéficiaire interne ou externe).', 'assetsign'),
                  htmlspecialchars($link, ENT_QUOTES),
                  $targetLabel
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
              __('Ce matériel n\'a pas d\'utilisateur assigné : ce changement d\'État ne peut donc pas générer de fiche d\'attribution ou de restitution. Assignez un utilisateur sur la fiche du matériel si une signature est attendue.', 'assetsign'),
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
          return;
      }

       // Meme principe que la Vente ci-dessus, applique a la Destruction (issue
       // #78) : ni DestructionDetails ni document (certificat) ne sont connus au
       // moment d'un simple changement d'Etat — completes ensuite via
       // Assetsign::updateDestructionDetails().
      if (in_array($newState, $config->getDestructionStates(), true)) {
          self::createAssetsign($item, self::TYPE_DESTRUCTION, $currentUser);
      }
   }

    /**
     * Ecrit `Infocom::decommission_date` (champ natif GLPI, libelle francais
     * "Reforme") a la date du jour, uniquement si elle est encore vide - une
     * saisie manuelle anterieure (ou un declenchement precedent) n'est JAMAIS
     * ecrasee (cf. ROADMAP.md, issue #78). Volontairement PAS un nouveau type
     * Assetsign::TYPE_* ni un nouvel evenement Passeport : la frise du Passeport
     * materiel lit deja ce champ (PassportEvent::getInfocomPseudoEvents(),
     * libelle "Reforme"), cette methode ne fait que le preremplir - decision
     * documentee dans le commentaire de l'utilisateur sur l'issue #78 (pas
     * besoin de dupliquer une notion de "fiche" pour une simple date native).
     * `Infocom::canApplyOn()` respecte le meme garde-fou que l'affichage
     * existant : aucune ecriture pour un itemtype que l'administrateur a
     * retire des types compatibles Infocom au niveau du coeur GLPI. Passe par
     * add()/update() (pas de SQL direct) : Infocom est un itemtype DIFFERENT
     * du materiel qui a declenche ce hook (Computer, Monitor...), aucun risque
     * de re-declencher item_update -> handleStateBasedTrigger() sur CE MEME
     * materiel (contrairement a syncItemStateAfterManualCreation(), qui ecrit
     * sur le materiel lui-meme et doit donc court-circuiter ses propres hooks).
     */
   private static function writeDecommissionDateIfMissing(CommonDBTM $item): void {
      if (!\Infocom::canApplyOn($item->getType())) {
          return;
      }

       $infocom = new \Infocom();
      if ($infocom->getFromDBforDevice($item->getType(), $item->getID())) {
         if (!empty($infocom->fields['decommission_date'])) {
             return; // Deja renseignee : jamais ecrasee.
         }
          $infocom->update([
              'id'                 => $infocom->getID(),
              'decommission_date'  => date('Y-m-d'),
          ]);
          return;
      }

       // Aucune ligne Infocom pour ce materiel (jamais consultee via l'onglet
       // Infocom) : en cree une, plutot que de renoncer silencieusement a
       // enregistrer la reforme.
       $infocom->add([
           'itemtype'           => $item->getType(),
           'items_id'           => $item->getID(),
           'entities_id'        => $item->fields['entities_id'] ?? 0,
           'decommission_date'  => date('Y-m-d'),
       ]);
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
      // Issue #157 : la desaffectation du materiel d'une personne qui part (geste
      // habituel du technicien au moment du depart) ne doit ni annuler la fiche du
      // dossier de depart (cancelPendingAssetsignsFor() ci-dessous) ni envoyer une
      // restitution individuelle en plus : le dossier couvre deja ce materiel.
      if ($type === self::TYPE_RETURN && self::isInOpenDeparture($item)) {
          return;
      }

       $config = Config::getForEntity((int) $item->fields['entities_id']);
       $template = Template::getDefaultFor($type, (int) $item->fields['entities_id']);

       // Issue #178 : derniere attribution envoyee ou signee, reperee AVANT l'annulation ci-dessous
       // (une restitution peut survenir avant la signature de la remise : le materiel a ete remis).
       $handoverId = $type === self::TYPE_RETURN ? self::findLastHandoverId($item) : null;

       self::cancelPendingAssetsignsFor($item);

       // Issue #170 : attribution faite par un technicien depuis la fiche du materiel -> brouillon,
       // confirme (et complete) dans une fenetre avant tout envoi.
       // Issue #163 : en mode manuel, toute feuille declenchee automatiquement reste en brouillon
       // jusqu'au clic sur « Envoyer pour signature ».
       $technicianWaiting = self::isTechnicianWaiting();
       $mode = $config->getSendingMode();
       // Issue #183 : remise demandee depuis un ticket -> toujours un brouillon, que le technicien
       // complete puis envoie depuis la fiche (il y est redirige).
       $tickets_id = (int) ($item->input['_assetsign_tickets_id'] ?? 0);
       $asDraft = $tickets_id > 0
           || $mode === Config::SENDING_MANUAL
           || ($mode === Config::SENDING_CONFIRM && $type === self::TYPE_HANDOVER && $technicianWaiting);
       $askConfirmation = $tickets_id === 0 && $asDraft && $type === self::TYPE_HANDOVER && $technicianWaiting;

       $assetsign = new self();
       $id = $assetsign->add([
           'entities_id'                 => $item->fields['entities_id'],
           'itemtype'                    => $item->getType(),
           'items_id'                    => $item->getID(),
           'users_id'                    => $users_id,
           'users_id_tech'               => Session::getLoginUserID() ?: 0,
           'plugin_assetsign_templates_id'  => $template ? $template->getID() : 0,
           'plugin_assetsign_kits_id'    => self::resolveKitForAutomaticCreation($item, $type),
           'type'                        => $type,
           'status'                      => $asDraft ? self::STATUS_DRAFT : self::STATUS_PENDING,
           'tickets_id'                  => $tickets_id,
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
       // Issue #178 : la restitution reprend le materiel remis avec ce poste.
       $assetsign->copyHandedItemsFromLastHandover($handoverId);
      if ($asDraft) {
         if ($type === self::TYPE_HANDOVER) {
             // Elements connectes au poste pre-coches (decochables avant l'envoi).
             $assetsign->attachConnectedItems(true, false);
         }
         if ($askConfirmation) {
             self::queueConfirmation($assetsign->getID());
         } else if ($technicianWaiting && $tickets_id === 0) {
             Session::addMessageAfterRedirect(
                 __('Feuille préparée en brouillon : rien n\'a été envoyé. Envoyez-la depuis l\'onglet Attributions du matériel quand vous le souhaitez.', 'assetsign')
             );
         }
          return;
      }
       $assetsign->attachConnectedItemsIfConfigured();
       $assetsign->launchWorkflow($config);
   }

    /**
     * Issue #170 : la fenetre de confirmation n'a de sens que si un technicien attend, devant la fiche
     * du materiel, le retour de son enregistrement (formulaire *.form.php ou bouton « Assigner » de
     * l'onglet). Inventaire automatique, actions massives, ligne de commande, taches planifiees : envoi
     * immediat comme avant, pour ne jamais bloquer une remise.
     */
   private static function isTechnicianWaiting(): bool {
      if (\isCommandLine() || !Session::getLoginUserID()) {
          return false;
      }
       $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
       return str_ends_with($path, '.form.php') || str_ends_with($path, '/assign_user_asset.php');
   }

   private static function queueConfirmation(int $assetsigns_id): void {
       $_SESSION['plugin_assetsign_confirm'][$assetsigns_id] = true;
   }

    /**
     * Brouillon en attente de confirmation pour ce materiel, retire de la file : la fenetre ne
     * s'affiche qu'une fois (le brouillon reste ensuite envoyable depuis l'onglet du materiel).
     */
   public static function popQueuedConfirmationFor(CommonDBTM $item): ?self {
      foreach (array_keys($_SESSION['plugin_assetsign_confirm'] ?? []) as $id) {
          $assetsign = new self();
         if (!$assetsign->getFromDB((int) $id) || (int) $assetsign->fields['status'] !== self::STATUS_DRAFT) {
             unset($_SESSION['plugin_assetsign_confirm'][$id]);
             continue;
         }
         if ($assetsign->fields['itemtype'] === $item->getType() && (int) $assetsign->fields['items_id'] === $item->getID()) {
             unset($_SESSION['plugin_assetsign_confirm'][$id]);
             return $assetsign;
         }
      }
       return null;
   }

    /** Rouvre la fenetre au prochain affichage du materiel (apres un ajout fait depuis la fenetre). */
   public function reopenConfirmation(): void {
      if ((int) $this->fields['status'] === self::STATUS_DRAFT) {
          self::queueConfirmation($this->getID());
      }
   }

    /**
     * Issue #170 : envoi d'un brouillon (fenetre de confirmation ou bouton de l'onglet) — ne garde que
     * les elements du parc encore coches ($keptItemIds = null : tout garder), puis lance le workflow
     * habituel (PDF, passeport, lien et e-mail de signature).
     *
     * @param list<int>|null $keptItemIds ids des lignes AssetsignItem a conserver
     */
   public function sendDraft(?array $keptItemIds = null): void {
      if ((int) $this->fields['status'] !== self::STATUS_DRAFT) {
          throw new \RuntimeException(__('Cette feuille a déjà été envoyée.', 'assetsign'));
      }
      if ($keptItemIds !== null) {
         foreach (AssetsignItem::getForAssetsign($this->getID()) as $row) {
            if (!in_array((int) $row['id'], $keptItemIds, true)) {
                AssetsignItem::detach($this->getID(), (int) $row['id']);
            }
         }
      }
       unset($_SESSION['plugin_assetsign_confirm'][$this->getID()]);
       $this->update(['id' => $this->getID(), 'status' => self::STATUS_PENDING]);
       $this->getFromDB($this->getID());
       AssetsignItem::refreshSnapshots($this->getID());
       // Messages techniques du coeur (« Création du répertoire », « Document copié ») ecartes :
       // seul le message de confirmation de l'envoi doit s'afficher (cf. regenerateUnsignedPdf()).
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $this->launchWorkflow();
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
   }

    /**
     * Issue #193 : pret temporaire. Une date de retour prevue fait d'une attribution un pret ;
     * vide = attribution definitive. Modifiable tant que la feuille n'est ni annulee ni expiree,
     * y compris apres signature (prolonger un pret) : le PDF deja signe reste tel quel, seul le
     * brouillon / la feuille en attente est regenere pour afficher l'echeance.
     */
   public function setLoanUntil(string $date): void {
      if ((int) $this->fields['type'] !== self::TYPE_HANDOVER) {
          throw new \RuntimeException(__('Seule une attribution peut être un prêt.', 'assetsign'));
      }
      if (in_array((int) $this->fields['status'], [self::STATUS_CANCELLED, self::STATUS_EXPIRED], true)) {
          throw new \RuntimeException(__('Cette feuille est annulée ou expirée.', 'assetsign'));
      }
       $date = trim($date);
      if ($date !== '') {
          $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
         if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
             throw new \RuntimeException(__('Date de retour invalide.', 'assetsign'));
         }
         if ($date < date('Y-m-d')) {
             throw new \RuntimeException(__('La date de retour prévue ne peut pas être dans le passé.', 'assetsign'));
         }
      }
      if ($date === (string) ($this->fields['loan_until'] ?? '')) {
          return;
      }
       // Nouvelle echeance : un nouveau rappel sera envoye avant elle.
       global $DB;
       $DB->update(self::getTable(), ['loan_until' => $date === '' ? null : $date, 'loan_reminder_sent_at' => null], ['id' => $this->getID()]);
       $this->getFromDB($this->getID());
      if ($this->isStillEditable() && (int) $this->fields['document_id_unsigned'] > 0) {
          $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
          $this->regenerateUnsignedPdf();
          $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      }
   }

    /**
     * Issue #163 : avant l'envoi, le technicien peut encore changer le beneficiaire (fiche interne)
     * et le modele de document (meme type de fiche, modele actif). Brouillon uniquement : une fois
     * envoyee, la feuille est entre les mains du beneficiaire.
     */
   public function updateDraft(int $users_id, int $templates_id): void {
      if ((int) $this->fields['status'] !== self::STATUS_DRAFT) {
          throw new \RuntimeException(__('Cette feuille a déjà été envoyée.', 'assetsign'));
      }
       $input = ['id' => $this->getID()];

      if ((int) ($this->fields['beneficiary_type'] ?? self::BENEFICIARY_INTERNAL) === self::BENEFICIARY_INTERNAL
          && $users_id > 0 && $users_id !== (int) $this->fields['users_id']) {
          $user = new \User();
         if (!$user->getFromDB($users_id) || !(int) $user->fields['is_active'] || (int) $user->fields['is_deleted']) {
             throw new \RuntimeException(__('Bénéficiaire introuvable ou inactif.', 'assetsign'));
         }
          $input['users_id'] = $users_id;
      }

      if ($templates_id !== (int) $this->fields['plugin_assetsign_templates_id']) {
          $template = new Template();
         if ($templates_id > 0 && (!$template->getFromDB($templates_id)
             || (int) $template->fields['type'] !== (int) $this->fields['type']
             || !(int) $template->fields['is_active'])) {
             throw new \RuntimeException(__('Ce modèle de document ne correspond pas à ce type de fiche.', 'assetsign'));
         }
          $input['plugin_assetsign_templates_id'] = $templates_id;
      }

      if (count($input) > 1) {
          $this->update($input);
          $this->getFromDB($this->getID());
         if ((int) $this->fields['document_id_unsigned'] > 0) {
             $this->regenerateUnsignedPdf();
         }
      }
   }

    /** Issue #163 : apercu du document tel qu'il partira, genere a la volee (rien n'est enregistre). */
   public function renderDraftPreviewPdf(): string {
       AssetsignItem::refreshSnapshots($this->getID());
       $builder = new Pdf\HandoverPdfBuilder();
       return $builder->renderPdf($builder->renderHtml($this));
   }

    /**
     * Attributions, maintenances, mouvements et departs naissent toujours d'un materiel ou d'un
     * utilisateur, jamais d'un formulaire vide : le bouton « Ajouter » que GLPI ajoute d'office au
     * menu (droit CREATE) menait a une page « element introuvable ». Retire de ces menus.
     *
     * @param array<string, mixed>|false $menu false quand l'utilisateur ne voit pas ce menu
     * @return array<string, mixed>
     */
   public static function withoutAddLink(array|false $menu): array {
      if (!is_array($menu)) {
          return [];
      }
       unset($menu['links']['add']);
       return $menu;
   }

    /**
     * Arrivee sur le formulaire sans numero de fiche (ancien lien, favori) : retour a la liste avec
     * l'endroit ou la creer, au lieu d'une page « element introuvable ».
     */
   public static function redirectToListWithHint(string $itemtype, string $hint): never {
       Session::addMessageAfterRedirect($hint, false, INFO);
       \Html::redirect($itemtype::getSearchURL());
   }

    /** Contenu de la fenetre de confirmation (issue #170), insere par plugin_assetsign_post_item_form(). */
   public function renderConfirmationModal(): string {
       $beneficiary = $this->getBeneficiary();
       ob_start();
       TemplateRenderer::getInstance()->display('@assetsign/confirm_modal.html.twig', [
           'item'                  => $this,
           'beneficiary_name'      => trim(\formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? '')),
           'target_item'           => $this->getTargetItem(),
           'target_type'           => self::getCanonicalItemtypeLabel((string) $this->fields['itemtype']),
           'inventoried_items'     => $this->getInventoriedItems(),
           'suggested_items'       => $this->getSuggestedConnectedItems(),
           'accessories'           => $this->getAccessories(),
           'inventoried_itemtypes' => AssetsignItem::getAllowedItemtypes(),
           'available_items'       => AssetsignItem::getAvailableItems($this),
           // Issue #181 : kit de la feuille (lignes « type GLPI ») et choix du kit.
           'kit'                   => $this->getKit(),
           'kit_item_lines'        => $this->getKitItemLines(),
           'csrf_token'            => Session::getNewCSRFToken(),
           'assign_in_glpi'        => (int) (Config::getForEntity((int) $this->fields['entities_id'])->fields['assign_handed_items'] ?? 1) === 1,
       ]);
       return (string) ob_get_clean();
   }

    /**
     * Issue #157 : fiche de restitution creee pour un dossier de depart — meme
     * creation que createAssetsign() (kit reporte, modele de l'entite, passeport,
     * PDF), mais en mode groupe : ni lien ni e-mail individuels (cf.
     * launchWorkflow()), signature depuis la page du dossier.
     */
   public static function createForDeparture(CommonDBTM $item, int $users_id, int $departures_id): ?self {
       $template = Template::getDefaultFor(self::TYPE_RETURN, (int) $item->fields['entities_id']);

       $assetsign = new self();
       $id = $assetsign->add([
           'entities_id'                    => $item->fields['entities_id'],
           'itemtype'                       => $item->getType(),
           'items_id'                       => $item->getID(),
           'users_id'                       => $users_id,
           'users_id_tech'                  => Session::getLoginUserID() ?: 0,
           'plugin_assetsign_templates_id'  => $template ? $template->getID() : 0,
           'plugin_assetsign_kits_id'       => self::resolveKitForAutomaticCreation($item, self::TYPE_RETURN),
           'plugin_assetsign_departures_id' => $departures_id,
           'type'                           => self::TYPE_RETURN,
           'status'                         => self::STATUS_PENDING,
       ]);
      if (!$id) {
          CreationFailure::record(
              $item->getType(),
              $item->getID(),
              (int) $item->fields['entities_id'],
              self::TYPE_RETURN,
              'Echec de l\'insertion en base (dossier de depart #' . $departures_id . ').'
          );
          return null;
      }

       $assetsign->getFromDB($id);
       $assetsign->copyHandedItemsFromLastHandover();
       $assetsign->attachConnectedItemsIfConfigured();
       $assetsign->launchWorkflow(null, true);
       return $assetsign;
   }

    /**
     * Vrai si une restitution de ce materiel en attente appartient a un dossier
     * de depart encore ouvert (issue #157).
     */
   public static function isInOpenDeparture(CommonDBTM $item): bool {
       global $DB;

       return $DB->request([
           'COUNT'      => 'cpt',
           'FROM'       => self::getTable() . ' AS a',
           'INNER JOIN' => [
               Departure::getTable() . ' AS d' => ['FKEY' => ['a' => 'plugin_assetsign_departures_id', 'd' => 'id']],
           ],
           'WHERE'      => [
               'a.itemtype'   => $item->getType(),
               'a.items_id'   => $item->getID(),
               'a.type'       => self::TYPE_RETURN,
               'a.is_deleted' => 0,
               'a.status'     => [self::STATUS_DRAFT, self::STATUS_PENDING, ...self::STATUSES_AWAITING_SIGNATURE],
               'd.status'     => DepartureLogic::STATUS_OPEN,
           ],
       ])->current()['cpt'] > 0;
   }

    /**
     * Report automatique du Kit assigne a la DERNIERE Attribution de ce materiel
     * vers une nouvelle Restitution (V3, issue #83) : evite de faire ressaisir
     * au technicien un kit deja choisi a la remise, tout en restant un simple
     * report d'une VRAIE donnee deja saisie — jamais une invention (meme
     * principe que writeDecommissionDateIfMissing() : copier un fait reel,
     * jamais en deviner un). Reste corrigeable ensuite via updateKit() (ex:
     * kit determine seulement au moment de la restitution, ou report errone
     * a corriger). Concerne UNIQUEMENT la Restitution : Don/Vente/Destruction
     * n'ont pas de notion de "controle au retour", et une Attribution n'a
     * evidemment rien a reporter depuis elle-meme.
     */
   private static function resolveKitForAutomaticCreation(CommonDBTM $item, int $type): int {
      if ($type !== self::TYPE_RETURN) {
          return 0;
      }

       global $DB;
       $lastHandover = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => [
               'itemtype'                    => $item->getType(),
               'items_id'                    => $item->getID(),
               'type'                        => self::TYPE_HANDOVER,
               'is_deleted'                  => 0,
               'plugin_assetsign_kits_id'    => ['>', 0],
           ],
           'ORDER' => 'date_creation DESC',
           'LIMIT' => 1,
       ])->current();

       return $lastHandover !== null ? (int) $lastHandover['plugin_assetsign_kits_id'] : 0;
   }

    /**
     * Point d'entree PUBLIC pour les types a declenchement manuel (Don, Vente,
     * Destruction) : contrairement a createAssetsign() (privee, appelee depuis
     * les hooks d'affectation/etat), rien ne signale automatiquement "ce
     * materiel est donne/vendu/detruit" — un technicien choisit explicitement
     * de creer la fiche depuis l'onglet Assetsign du materiel (cf.
     * assetsign_tab.html.twig, front/assetsign.form.php action "create_manual").
     *
     * @param array $extra Donnees specifiques au type — ignorees pour les types
     *                      qui n'en utilisent pas : 'price'/'sale_date' (TYPE_VENTE,
     *                      cf. VenteDetails) ; 'organization_name' (TYPE_DON, cf.
     *                      DonDetails) + 'justificatif' (entree $_FILES, document
     *                      joint, cf. Assetsign::attachUploadedDocument()) ; 'provider_name'
     *                      (TYPE_DESTRUCTION, cf. DestructionDetails) + 'certificat'
     *                      (entree $_FILES). 'beneficiary_type'
     *                      (BENEFICIARY_INTERNAL par defaut, ou
     *                      BENEFICIARY_EXTERNAL), et dans ce dernier cas
     *                      'external_name' (obligatoire)/'external_contact'
     *                      (facultatif, email ou telephone en texte libre) —
     *                      un don/une vente/une destruction peut concerner
     *                      quelqu'un hors de l'entreprise, sans compte GLPI.
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
      if ($type === self::TYPE_DON) {
          // Meme raison que la Vente ci-dessus : cree AVANT launchWorkflow() pour
          // que le PDF affiche deja l'organisme beneficiaire (cf. DonDetails).
          DonDetails::createForAssetsign($id, trim((string) ($extra['organization_name'] ?? '')));
      }
      if ($type === self::TYPE_DESTRUCTION) {
          DestructionDetails::createForAssetsign($id, trim((string) ($extra['provider_name'] ?? '')));
      }

       $assetsign->launchWorkflow($config);

       // Justificatif (Don) / certificat (Destruction) : simple document natif
       // GLPI attache a la fiche elle-meme (cf. Assetsign::attachUploadedDocument()),
       // jamais integre au PDF genere ci-dessus - contrairement au prix/organisme/
       // prestataire (textes courts, pertinents dans le corps du document), un
       // fichier joint (souvent un PDF/scan lui-meme) n'a pas besoin d'etre
       // fusionne dans le contrat : apres launchWorkflow() suffit, plus simple
       // et sans risque d'impacter la generation du PDF non signe.
      if ($type === self::TYPE_DON && !empty($extra['justificatif'])) {
          $assetsign->attachUploadedDocument($extra['justificatif']);
      }
      if ($type === self::TYPE_DESTRUCTION && !empty($extra['certificat'])) {
          $assetsign->attachUploadedDocument($extra['certificat']);
      }

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

       $targetStates = match ($type) {
           self::TYPE_DON         => $config->getDonationStates(),
           self::TYPE_VENTE       => $config->getVenteStates(),
           self::TYPE_DESTRUCTION => $config->getDestructionStates(),
           default                => [],
       };
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
     * Delegue la signature de cette fiche a un autre compte GLPI (issue #115).
     * 'users_id' (le beneficiaire d'origine) n'est JAMAIS modifie : seules les
     * 4 colonnes dediees (delegated_users_id/delegation_date/delegation_reason/
     * delegated_by_users_id) changent, pour garder une tracabilite complete
     * (signataire prevu vs signataire reel) — cf. getActualSigner().
     *
     * Utilisee aussi bien par un technicien/admin (front/assetsign.form.php,
     * cf. Config::enable_signature_delegation) que par le beneficiaire
     * lui-meme depuis la page de signature (front/sign.php, cf.
     * Config::enable_self_service_delegation) : $initiatedByUsersId distingue
     * les deux cas dans l'historique natif, ecrit explicitement ci-dessous
     * (\Log::history(), meme mecanisme que cancelPendingAssetsignsFor() —
     * contrairement a cancelRequest(), Assetsign::$dohistory n'est jamais
     * passe a true, update() seul ne journalise donc rien automatiquement ;
     * verifie en conditions reelles pendant le developpement de cette
     * fonctionnalite).
     *
     * Regenere systematiquement le jeton de signature : l'ancien lien, deja
     * envoye au beneficiaire d'origine, devient donc invalide dans le meme
     * mouvement. C'est le choix retenu ici pour repondre a "le beneficiaire
     * d'origine ne doit plus pouvoir signer lui-meme apres avoir delegue" —
     * plus simple qu'un etat de delegation actif/inactif verifie a chaque
     * acces (aucune modification necessaire a assertCurrentUserIsAuthorizedSigner(),
     * qui continue d'accepter users_id OU delegated_users_id : dans les
     * faits, seul le NOUVEAU lien - envoye au delegue - reste valide). Un
     * administrateur peut rendre la main au beneficiaire d'origine via
     * revokeDelegation() ci-dessous (seule voie de "revocation" retenue,
     * volontairement reservee a l'admin).
     *
     * @throws \RuntimeException si la fiche n'est plus modifiable, si le
     *                            beneficiaire n'est pas interne, ou si le
     *                            compte delegue est introuvable/identique au
     *                            beneficiaire d'origine.
     */
   public function delegateSignatureTo(int $delegateUsersId, string $reason, int $initiatedByUsersId): void {
      if (!$this->isStillEditable()) {
          throw new \RuntimeException(__('Cette demande ne peut plus être déléguée (déjà signée, expirée ou annulée).', 'assetsign'));
      }
      if ((int) $this->fields['beneficiary_type'] !== self::BENEFICIARY_INTERNAL) {
          // Cf. commentaire de classe / launchWorkflow() : un beneficiaire
          // externe n'a de toute facon aucun flux de signature a distance.
          throw new \RuntimeException(__('La délégation n\'est possible que pour un bénéficiaire interne (compte GLPI).', 'assetsign'));
      }
      if ($delegateUsersId === (int) $this->fields['users_id']) {
          throw new \RuntimeException(__('Le compte délégué doit être différent du bénéficiaire d\'origine.', 'assetsign'));
      }
      $config = Config::getForEntity((int) $this->fields['entities_id']);
      if (!$config->fields['enable_signature_delegation']) {
          // Revalide le commutateur cote serveur : le formulaire technicien ne
          // le verifiait jusqu'ici que par affichage conditionnel (Twig), a
          // l'inverse du chemin self-service qui l'a toujours fait - meme
          // discipline que Config::upsertForEntity() applique deja pour
          // enable_self_service_delegation.
          throw new \RuntimeException(__('La délégation de signature est désactivée pour cette entité.', 'assetsign'));
      }

       $delegate = new \User();
      if ($delegateUsersId <= 0 || !$delegate->getFromDB($delegateUsersId)) {
          throw new \RuntimeException(__('Compte utilisateur délégué introuvable.', 'assetsign'));
      }
      if (!$delegate->fields['is_active'] || $delegate->fields['is_deleted']) {
          throw new \RuntimeException(__('Le compte délégué est désactivé ou supprimé.', 'assetsign'));
      }
      $delegateEntities = \Profile_User::getUserEntities($delegateUsersId, true);
      if (!in_array((int) $this->fields['entities_id'], $delegateEntities, true)) {
          // Session::haveAccessToEntity() ne verifie que la session en cours
          // et ne peut pas etre utilise pour un compte tiers (le delegue) :
          // Profile_User::getUserEntities() est l'equivalent pour un
          // utilisateur arbitraire (recursif, pour couvrir les profils dont
          // l'acces vient d'une entite parente).
          throw new \RuntimeException(__('Le compte délégué n\'a pas accès à l\'entité de ce dossier.', 'assetsign'));
      }

       $this->update([
           'id'                    => $this->getID(),
           'delegated_users_id'    => $delegateUsersId,
           'delegation_date'       => date('Y-m-d H:i:s'),
           'delegation_reason'     => trim($reason),
           'delegated_by_users_id' => $initiatedByUsersId,
       ]);

       \Log::history(
           $this->getID(),
           self::class,
           ['0', '', sprintf(
               __('Signature déléguée à %s (motif : %s)', 'assetsign'),
               formatUserName(0, $delegate->fields['name'], $delegate->fields['realname'], $delegate->fields['firstname']),
               trim($reason) !== '' ? trim($reason) : '—'
           )],
           0,
           \Log::HISTORY_LOG_SIMPLE_MESSAGE
       );

       $config = Config::getForEntity((int) $this->fields['entities_id']);
       // Regenere/cree le jeton meme si la fiche n'en avait pas encore
       // (statut DRAFT/PENDING avant tout envoi) : Token::regenerateForAssetsign()
       // invalide simplement (aucun effet si aucun jeton n'existait) puis en
       // cree un nouveau, meme mecanisme que CanvasProvider::createRequest().
       $raw = Token::regenerateForAssetsign($this, (int) $config->fields['link_validity_days']);
       $this->_current_raw_token = $raw;

       NotificationEvent::raiseEvent('delegated', $this);
       // Delegation decidee par un tiers (technicien/admin) : le beneficiaire
       // d'origine, dont le lien vient d'etre invalide, en est informe — qui,
       // au profit de qui, pourquoi. Sans objet quand il l'a faite lui-meme.
      if ($initiatedByUsersId !== (int) $this->fields['users_id']) {
          NotificationEvent::raiseEvent('delegated_beneficiary', $this);
      }
   }

    /**
     * Rend la main au beneficiaire d'origine (admin uniquement, cf.
     * front/assetsign.form.php) : seule voie de "revocation" d'une delegation,
     * cf. le commentaire de delegateSignatureTo(). Regenere le jeton (meme
     * raisonnement : l'ancien lien, envoye au delegue, devient invalide) et
     * renvoie l'evenement 'new' (contenu deja generique — "un document vous
     * attend" reste vrai ici, pas besoin d'un gabarit de notification dedie).
     *
     * @throws \RuntimeException si la fiche n'est pas deleguee ou plus modifiable.
     */
   public function revokeDelegation(): void {
      if ((int) ($this->fields['delegated_users_id'] ?? 0) <= 0) {
          throw new \RuntimeException(__('Cette demande n\'est pas déléguée.', 'assetsign'));
      }
      if (!$this->isStillEditable()) {
          throw new \RuntimeException(__('Cette délégation ne peut plus être révoquée (déjà signée, expirée ou annulée).', 'assetsign'));
      }

       $this->update([
           'id'                    => $this->getID(),
           'delegated_users_id'    => 0,
           'delegation_date'       => null,
           'delegation_reason'     => '',
           'delegated_by_users_id' => 0,
       ]);

       \Log::history(
           $this->getID(),
           self::class,
           ['0', '', __('Délégation de signature révoquée', 'assetsign')],
           0,
           \Log::HISTORY_LOG_SIMPLE_MESSAGE
       );

       $config = Config::getForEntity((int) $this->fields['entities_id']);
       $raw = Token::regenerateForAssetsign($this, (int) $config->fields['link_validity_days']);
       $this->_current_raw_token = $raw;

       NotificationEvent::raiseEvent('new', $this);
   }

    /**
     * Genere le PDF, cree le jeton de signature, envoie la notification initiale.
     */
   public function launchWorkflow(?Config $config = null, bool $grouped = false): void {
       $config ??= Config::getForEntity((int) $this->fields['entities_id']);

       // Point de passage commun a toutes les voies de creation (automatique par
       // affectation/Etat, manuelle Don/Vente) : seul endroit ou enregistrer
       // l'evenement du Passeport materiel sans dupliquer cet appel dans chacune
       // de ces voies. Volontairement AVANT la construction du PDF : un echec de
       // rendu PDF ne doit pas empecher l'evenement d'exister.
       PassportEvent::recordForAssetsign($this);
       // Issue #174 : le materiel remis avec est attribue dans GLPI au beneficiaire des l'envoi.
       $this->assignHandedItemsInGlpi($config);
       // Issue #182 : et il apparait dans son propre passeport (« remis avec ... »).
       PassportEvent::recordHandedItems($this);

       // Messages techniques du coeur a la creation du PDF (« Creation du repertoire », « Document
       // copie avec succes ») : ecartes, comme dans regenerateUnsignedPdf().
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $builder = new Pdf\HandoverPdfBuilder();
       $document = $builder->build($this);

       $this->update([
           'id'                   => $this->getID(),
           'document_id_unsigned' => $document->getID(),
       ]);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

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

       // Signatures multiples (issue #143), Attribution uniquement (cf.
       // Config::enable_co_signature) : responsable hierarchique du
       // beneficiaire (User::users_id_supervisor, deja renseigne par
       // l'annuaire) SNAPSHOTE ici, au lancement — jamais relu dynamiquement
       // ensuite, pour que le signataire attendu ne change pas en cours de
       // route si l'organigramme est modifie pendant que la fiche est en
       // circulation. Si aucun responsable n'est renseigne, la fiche reste
       // mono-signataire (0 = pas de contre-signature requise, meme
       // convention "0 = absent" que delegated_users_id ci-dessus) : jamais
       // de blocage pour donnee organisationnelle manquante.
      if ($config->fields['enable_co_signature'] && (int) $this->fields['type'] === self::TYPE_HANDOVER) {
          $beneficiary = new \User();
         if ($beneficiary->getFromDB((int) $this->fields['users_id'])) {
             $supervisorId = (int) ($beneficiary->fields['users_id_supervisor'] ?? 0);
            if ($supervisorId > 0 && $supervisorId !== (int) $this->fields['users_id']) {
                $this->update(['id' => $this->getID(), 'cosigner_users_id' => $supervisorId]);
            }
         }
      }

       // Delegue au fournisseur configure (uniquement le canvas natif pour l'instant,
       // cf. Provider\ProviderFactory).
       // Le jeton brut n'est jamais stocke : il transite en propriete volatile le temps
       // de construire l'e-mail de notification dans la meme requete (cf. Token::validate()).
      // Issue #157 : fiche d'un dossier de depart — signee depuis la page du dossier
      // (Departure::sign()), donc ni lien de signature ni e-mail individuels : une
      // seule invitation pour tout le materiel, envoyee par le dossier lui-meme.
      if ($grouped) {
          $this->update([
              'id'        => $this->getID(),
              'status'    => self::STATUS_SENT,
              'date_sent' => date('Y-m-d H:i:s'),
          ]);
          return;
      }

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

    /**
     * Compte GLPI actuellement delegue pour signer cette fiche (issue #115),
     * ou null si aucune delegation n'est active. Meme forme de tableau que
     * getBeneficiary() (firstname/realname/name/email).
     */
   public function getDelegate(): ?array {
       $delegateId = (int) ($this->fields['delegated_users_id'] ?? 0);
      if ($delegateId <= 0) {
          return null;
      }

       $user = new \User();
      if (!$user->getFromDB($delegateId)) {
          return null;
      }
       $fields = $user->fields;
       $fields['email'] = \UserEmail::getDefaultForUser($delegateId) ?: '';
       return $fields;
   }

    /**
     * Responsable hierarchique snapshote comme contre-signataire (issue #143),
     * ou null si aucune contre-signature n'est requise pour cette fiche.
     * Meme forme de tableau que getBeneficiary()/getDelegate()
     * (firstname/realname/name/email).
     */
   public function getCosigner(): ?array {
       $cosignerId = (int) ($this->fields['cosigner_users_id'] ?? 0);
      if ($cosignerId <= 0) {
          return null;
      }

       $user = new \User();
      if (!$user->getFromDB($cosignerId)) {
          return null;
      }
       $fields = $user->fields;
       $fields['email'] = \UserEmail::getDefaultForUser($cosignerId) ?: '';
       return $fields;
   }

    /**
     * Comptes GLPI eligibles comme delegue pour une entite donnee (issue #115) —
     * memes criteres (`right => 'all'`, restreint a l'entite) que la liste
     * deroulante equivalente cote technicien/admin (assetsign_form.html.twig),
     * mais rendue en `<option>` simples plutot qu'en widget AJAX select2 : la
     * page de signature (sign_page.html.twig) est une page volontairement
     * autonome, sans jQuery (JS moderne fetch/module uniquement) — le widget
     * `User::dropdown()` y genere du JS qui suppose jQuery disponible et ne
     * s'initialise donc jamais, laissant un `<select>` vide avec pour seule
     * option "-----" (bug reel trouve en testant issue #115 en conditions
     * reelles avec Playwright, independant du correctif right='id' vs 'all').
     * Une liste simple, pre-remplie cote serveur, ne depend d'aucun JS pour
     * fonctionner. La validation reelle (compte actif, acces a l'entite, motif
     * obligatoire...) reste faite par delegateSignatureTo() a la soumission —
     * cette liste n'est qu'un confort d'affichage, jamais la seule barriere.
     *
     * @return array<int, array{id: int, label: string}>
     */
   public static function getDelegateCandidates(int $entitiesId): array {
       $candidates = [];
      foreach (\User::getSqlSearchResult(false, 'all', $entitiesId) as $row) {
          $candidates[] = [
              'id'    => (int) $row['id'],
              'label' => trim(formatUserName(0, $row['name'], $row['realname'], $row['firstname'])),
          ];
      }

       usort($candidates, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

       return $candidates;
   }

    /**
     * Identite du signataire REEL (delegue ou beneficiaire d'origine), utilisee
     * pour la ligne "Signataire" du PDF final et pour la preuve de signature
     * (glpi_plugin_assetsign_signatures) — cf. SignController::submit(). Sans
     * cette distinction, un document signe par un delegue afficherait quand
     * meme le nom du beneficiaire d'origine (getBeneficiary()), ce qui serait
     * faux au sens de la preuve de signature.
     *
     * Compare l'utilisateur EFFECTIVEMENT connecte (pas simplement
     * delegated_users_id) : si la fiche est deleguee mais que, par un chemin
     * quelconque, c'est encore le beneficiaire d'origine qui signe, la preuve
     * doit refleter QUI a reellement signe, pas qui est designe en base.
     */
   public function getActualSigner(): array {
       $delegateId = (int) ($this->fields['delegated_users_id'] ?? 0);
      if ($delegateId > 0 && $delegateId === (int) \Session::getLoginUserID()) {
           $delegate = $this->getDelegate();
         if ($delegate !== null) {
              return $delegate;
         }
      }
       return $this->getBeneficiary();
   }

    /**
     * Signataire ATTENDU a l'etape beneficiaire : le delegue si la signature a ete deleguee,
     * sinon le beneficiaire. Contrairement a getActualSigner(), ne depend pas de la session :
     * pour une signature sur place (issue #152), la session est celle du technicien temoin,
     * jamais celle du signataire.
     */
   public function getExpectedSigner(): array {
      if ((int) ($this->fields['delegated_users_id'] ?? 0) > 0) {
          $delegate = $this->getDelegate();
         if ($delegate !== null) {
             return $delegate;
         }
      }
       return $this->getBeneficiary();
   }

    /**
     * Signature sur place (issue #152) possible pour cette fiche : reglage actif pour son entite,
     * fiche en attente de la signature du beneficiaire (pas de la contre-signature, hors
     * perimetre) et beneficiaire interne (un beneficiaire externe ne signe jamais, cf.
     * BENEFICIARY_EXTERNAL). Le droit du technicien est verifie a part (can(UPDATE)).
     */
   public function canSignInPerson(): bool {
      if (!empty($this->fields['is_deleted'])
          || !in_array((int) $this->fields['status'], [self::STATUS_SENT, self::STATUS_VIEWED], true)
          || (int) ($this->fields['beneficiary_type'] ?? self::BENEFICIARY_INTERNAL) !== self::BENEFICIARY_INTERNAL
      ) {
          return false;
      }

       return (bool) Config::getForEntity((int) $this->fields['entities_id'])->fields['enable_in_person_signature'];
   }

    /**
     * Emet un jeton de signature sur place lie au technicien $witnessUsersId (seul ce compte
     * pourra l'ouvrir, cf. SignController) et le renvoie (brut), a ouvrir dans front/sign.php
     * sur l'ecran du technicien. Valable 24 h - le temps de la rencontre, pas le delai de la
     * fiche - et sans invalider le lien deja envoye par e-mail.
     *
     * @throws \RuntimeException si la fiche ne permet pas la signature sur place ou si le
     *                           technicien n'a pas le droit de la modifier
     */
   public function startInPersonSignature(int $witnessUsersId): string {
      if ($witnessUsersId <= 0 || !$this->can($this->getID(), UPDATE)) {
          throw new \RuntimeException(__('Vous n\'avez pas le droit de faire signer cette fiche.', 'assetsign'));
      }
      if (!$this->canSignInPerson()) {
          throw new \RuntimeException(__('La signature sur place n\'est pas possible pour cette fiche.', 'assetsign'));
      }

       return Token::createForAssetsign($this, 1, witnessUsersId: $witnessUsersId);
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

    /**
     * Issue #166 : objets du parc GLPI remis avec le materiel principal (instantanes figes, cf.
     * AssetsignItem), avec en plus l'utilisateur GLPI actuel de chaque objet pour signaler un
     * accessoire deja affecte a quelqu'un d'autre que le beneficiaire.
     *
     * @return list<array<string, mixed>>
     */
   public function getInventoriedItems(): array {
       $rows = AssetsignItem::getForAssetsign($this->getID());
      foreach ($rows as &$row) {
          $item = new $row['itemtype']();
          $holder = $item->getFromDB((int) $row['items_id']) ? (int) ($item->fields['users_id'] ?? 0) : 0;
          $row['assigned_to_other'] = $holder > 0 && $holder !== (int) $this->fields['users_id']
              ? \getUserName($holder)
              : '';
      }
       return $rows;
   }

    /**
     * Ajoute un objet du parc a la fiche (droit de lecture GLPI natif sur l'objet exige) et regenere
     * le PDF non signe.
     *
     * @return string|null null si ajoute, sinon un message d'erreur traduit
     */
   public function addInventoriedItem(string $itemtype, int $items_id): ?string {
      if (!$this->isStillEditable()) {
          return __('Cette fiche est déjà signée : sa liste de matériel ne peut plus changer.', 'assetsign');
      }
      if (in_array($itemtype, AssetsignItem::getAllowedItemtypes(), true)) {
          $item = new $itemtype();
         if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
             return __('Élément introuvable ou non visible avec vos droits.', 'assetsign');
         }
      }

       $error = AssetsignItem::attach($this, $itemtype, $items_id);
      if ($error !== null) {
          return self::getInventoriedItemErrorMessage($error);
      }
       $this->assignHandedItemsInGlpi();
       $this->regenerateUnsignedPdf();
       return null;
   }

   public static function getInventoriedItemErrorMessage(string $error): string {
       return match ($error) {
           AssetsignItem::ERROR_MAIN_ITEM      => __('C\'est déjà le matériel principal de cette fiche.', 'assetsign'),
           AssetsignItem::ERROR_ALREADY_LISTED => __('Cet élément figure déjà sur cette fiche.', 'assetsign'),
           AssetsignItem::ERROR_ON_OTHER_SHEET => __('Cet élément figure déjà sur une autre fiche en attente de signature.', 'assetsign'),
           default                             => __('Élément introuvable ou non visible avec vos droits.', 'assetsign'),
       };
   }

    /**
     * Issue #174 : attribue dans GLPI, au beneficiaire, chaque element du parc remis avec le materiel
     * (feuilles d'attribution envoyees uniquement : un brouillon peut encore changer).
     *
     * @return int nombre d'elements modifies
     */
   public function assignHandedItemsInGlpi(?Config $config = null): int {
       $config ??= Config::getForEntity((int) $this->fields['entities_id']);
      if ((int) $this->fields['type'] !== self::TYPE_HANDOVER
          || (int) $this->fields['status'] === self::STATUS_DRAFT
          || (int) ($this->fields['beneficiary_type'] ?? self::BENEFICIARY_INTERNAL) !== self::BENEFICIARY_INTERNAL
          || (int) ($config->fields['assign_handed_items'] ?? 1) !== 1) {
          return 0;
      }
       $changed = 0;
      foreach (AssetsignItem::getForAssetsign($this->getID()) as $row) {
         if (AssetsignItem::assignToBeneficiary($this, (string) $row['itemtype'], (int) $row['items_id'], $config)) {
             $changed++;
         }
      }
       return $changed;
   }

    /** Element connecte dont le poste porte deja le meme utilisateur (cf. handleItemAssignment()). */
   private static function followsItsConnectedAsset(CommonDBTM $item): bool {
       global $DB;

      if (!$item->isField('users_id') || !$DB->tableExists('glpi_assets_assets_peripheralassets')) {
          return false;
      }
       $users_id = (int) ($item->fields['users_id'] ?? 0);
      foreach ($DB->request([
          'SELECT' => ['itemtype_asset', 'items_id_asset'],
          'FROM'   => 'glpi_assets_assets_peripheralassets',
          'WHERE'  => [
              'itemtype_peripheral' => $item->getType(),
              'items_id_peripheral' => $item->getID(),
              'is_deleted'          => 0,
          ],
      ]) as $link) {
          $assetType = (string) $link['itemtype_asset'];
         if (!class_exists($assetType)) {
             continue;
         }
          $asset = new $assetType();
         if ($asset->getFromDB((int) $link['items_id_asset']) && (int) ($asset->fields['users_id'] ?? -1) === $users_id) {
             return true;
         }
      }
       return false;
   }

    /**
     * Issue #178 : une restitution reprend les elements du parc de la derniere attribution de ce
     * materiel qui a ete envoyee ou signee (pas un brouillon ni une feuille annulee).
     *
     * @return int nombre d'elements repris
     */
   public function copyHandedItemsFromLastHandover(?int $handoverId = null): int {
      if ((int) $this->fields['type'] !== self::TYPE_RETURN) {
          return 0;
      }
      if ($handoverId === null) {
          $mainType = (string) $this->fields['itemtype'];
         if (class_exists($mainType)) {
             $main = new $mainType();
            if ($main->getFromDB((int) $this->fields['items_id'])) {
                $handoverId = self::findLastHandoverId($main);
            }
         }
      }
      if ($handoverId === null) {
          return 0;
      }
       $copied = 0;
      foreach (AssetsignItem::getForAssetsign($handoverId) as $row) {
         if (AssetsignItem::attach($this, (string) $row['itemtype'], (int) $row['items_id']) === null) {
             $copied++;
         }
      }
       return $copied;
   }

    /** Derniere attribution de ce materiel envoyee ou signee (ni brouillon ni annulee). */
   private static function findLastHandoverId(CommonDBTM $item): ?int {
       global $DB;

       $last = $DB->request([
           'SELECT' => ['id'],
           'FROM'   => self::getTable(),
           'WHERE'  => [
               'itemtype'   => $item->getType(),
               'items_id'   => $item->getID(),
               'type'       => self::TYPE_HANDOVER,
               'status'     => [...self::STATUSES_AWAITING_SIGNATURE, self::STATUS_SIGNED, self::STATUS_COMPLETED_NO_SIGNATURE],
               'is_deleted' => 0,
           ],
           'ORDER'  => 'id DESC',
           'LIMIT'  => 1,
       ])->current();
       return $last ? (int) $last['id'] : null;
   }

    /**
     * Issue #178 : enregistre le controle du retour (etat et commentaire par element) puis met GLPI a
     * jour pour les elements dont l'etat change : rendu -> plus d'utilisateur, statut
     * Config::return_returned_state ; abime -> plus d'utilisateur, statut Config::return_damaged_state ;
     * manquant -> l'element reste a la personne, ticket si Config::return_missing_ticket.
     *
     * @param array<int|string, mixed> $states   id de ligne => etat (AssetsignItem::RETURN_*)
     * @param array<int|string, mixed> $comments id de ligne => commentaire
     * @return array{returned: int, missing: int, damaged: int, tickets: int}
     */
   public function saveReturnCheck(array $states, array $comments = []): array {
       global $DB;

      if ((int) $this->fields['type'] !== self::TYPE_RETURN || !$this->isStillEditable()) {
          throw new \RuntimeException(__('Le contrôle du retour n\'est plus modifiable sur cette feuille.', 'assetsign'));
      }
       $config = Config::getForEntity((int) $this->fields['entities_id']);
       $summary = ['returned' => 0, 'missing' => 0, 'damaged' => 0, 'tickets' => 0];
      foreach (AssetsignItem::getForAssetsign($this->getID()) as $row) {
          $id = (int) $row['id'];
          $state = (int) ($states[$id] ?? $row['return_state']);
         if (!array_key_exists($state, AssetsignItem::getReturnStates())) {
             continue;
         }
          $comment = mb_substr(trim((string) ($comments[$id] ?? $row['return_comment'])), 0, 255);
          $DB->update(AssetsignItem::getTable(), ['return_state' => $state, 'return_comment' => $comment], ['id' => $id]);
         if ($state === (int) $row['return_state']) {
             continue; // deja applique lors d'un enregistrement precedent
         }
         if ($state === AssetsignItem::RETURN_RETURNED) {
             $summary['returned'] += (int) $this->releaseReturnedItem($row, (int) $config->fields['return_returned_state']);
         } else if ($state === AssetsignItem::RETURN_DAMAGED) {
             $summary['damaged'] += (int) $this->releaseReturnedItem($row, (int) $config->fields['return_damaged_state']);
         } else if ($state === AssetsignItem::RETURN_MISSING) {
             $summary['missing']++;
         }
          // Issue #182 : evenement « Restitution » dans le passeport de l'element.
          PassportEvent::recordReturnedItem($this, ['return_state' => $state, 'return_comment' => $comment] + $row);
         if ($state === AssetsignItem::RETURN_MISSING && (int) $config->fields['return_missing_ticket'] === 1) {
             $summary['tickets'] += (int) ($this->openMissingItemTicket($row, $comment) > 0);
         }
      }
       $this->regenerateUnsignedPdf();
       return $summary;
   }

    /** Element rendu ou abime : plus d'utilisateur dans GLPI, et statut donne s'il est configure. */
   private function releaseReturnedItem(array $row, int $state): bool {
       $itemtype = (string) $row['itemtype'];
      if (!in_array($itemtype, AssetsignItem::getAllowedItemtypes(), true)) {
          return false;
      }
       $item = new $itemtype();
      if (!$item->getFromDB((int) $row['items_id'])) {
          return false;
      }
       $input = [];
      if ($item->isField('users_id') && (int) $item->fields['users_id'] !== 0) {
          $input['users_id'] = 0;
      }
      if ($state > 0 && $item->isField('states_id') && (int) $item->fields['states_id'] !== $state) {
          $input['states_id'] = $state;
      }
      if ($input === []) {
          return true;
      }
       // Meme marqueur que l'attribution (issue #174) : pas de feuille de restitution separee.
       return (bool) $item->update(['id' => (int) $row['items_id'], '_assetsign_handed_item' => true] + $input);
   }

    /** Element manquant : ticket lie a l'element, pour le suivi par l'equipe. */
   private function openMissingItemTicket(array $row, string $comment): int {
       $beneficiary = $this->getBeneficiary();
       $name = trim(\formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? ''));
       $main = $this->getTargetItem();
       $content = sprintf(
           __('%1$s « %2$s » n\'a pas été rendu avec %3$s par %4$s (feuille de restitution n° %5$d).', 'assetsign'),
           $row['type_label'],
           $row['name'],
           $main['name'] ?? '',
           $name,
           $this->getID()
       );
      if ($comment !== '') {
          $content .= "\n" . $comment;
      }
       $ticket = new \Ticket();
       $tickets_id = (int) $ticket->add([
           'name'              => sprintf(__('Matériel non rendu : %s', 'assetsign'), $row['name']),
           'content'           => $content,
           'entities_id'       => (int) $this->fields['entities_id'],
           'type'              => \Ticket::DEMAND_TYPE,
           '_users_id_requester' => (int) (Session::getLoginUserID() ?: 0),
           '_users_id_observer'  => (int) $this->fields['users_id'],
       ]);
      if ($tickets_id > 0) {
          (new \Item_Ticket())->add(['tickets_id' => $tickets_id, 'itemtype' => $row['itemtype'], 'items_id' => (int) $row['items_id']]);
      }
       return $tickets_id;
   }

    /**
     * Issue #181 : pour chaque type attendu par le kit de la feuille, quantite attendue, elements deja
     * sur la feuille et materiel disponible de ce type pour pourvoir le reste.
     *
     * @return list<array{itemtype: string, label: string, expected: int, provided: int, names: list<string>, available: list<array<string, mixed>>}>
     */
   public function getKitItemLines(): array {
       $kit = $this->getKit();
      if ($kit === null) {
          return [];
      }
       $expected = $kit->getExpectedItemLines();
      if ($expected === []) {
          return [];
      }
       $onSheet = [];
      foreach (AssetsignItem::getForAssetsign($this->getID()) as $row) {
          $onSheet[(string) $row['itemtype']][] = (string) $row['name'];
      }
       $available = [];
      if ($this->isStillEditable()) {
         foreach (AssetsignItem::getAvailableItems($this) as $rows) {
            foreach ($rows as $row) {
                $available[$row['itemtype']][] = $row;
            }
         }
      }
       $lines = [];
      foreach ($expected as $itemtype => $quantity) {
          $lines[] = [
              'itemtype'  => $itemtype,
              'label'     => self::getCanonicalItemtypeLabel($itemtype),
              'expected'  => $quantity,
              'provided'  => count($onSheet[$itemtype] ?? []),
              'names'     => $onSheet[$itemtype] ?? [],
              'available' => $available[$itemtype] ?? [],
          ];
      }
       return $lines;
   }

    /**
     * Issue #183 : onglet « Remise de materiel » d'un ticket - feuilles rattachees et remise d'un
     * materiel disponible a un demandeur du ticket.
     */
   public static function showForTicket(\Ticket $ticket): void {
       global $DB, $CFG_GLPI;

       $sheets = [];
      foreach ($DB->request([
          'FROM'  => self::getTable(),
          'WHERE' => ['tickets_id' => $ticket->getID(), 'is_deleted' => 0],
          'ORDER' => 'id DESC',
      ]) as $row) {
          $sheet = new self();
          $sheet->fields = $row;
          $target = $sheet->getTargetItem();
          $beneficiary = $sheet->getBeneficiary();
          $sheets[] = [
              'id'          => (int) $row['id'],
              'status'      => self::getStatuses()[(int) $row['status']] ?? '',
              'is_draft'    => (int) $row['status'] === self::STATUS_DRAFT,
              'item'        => self::getCanonicalItemtypeLabel((string) $row['itemtype']) . ' « ' . ($target['name'] ?? '') . ' »',
              'beneficiary' => \formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? ''),
          ];
      }

       $requesters = [];
      foreach ($ticket->getUsers(\CommonITILActor::REQUESTER) as $actor) {
         if ((int) $actor['users_id'] > 0) {
             $requesters[(int) $actor['users_id']] = \getUserName((int) $actor['users_id']);
         }
      }

       $config = Config::getForEntity((int) $ticket->fields['entities_id']);
       $managed = $config->getManagedItemtypes();
       $probe = new self();
       $probe->fields = ['id' => 0, 'entities_id' => (int) $ticket->fields['entities_id'], 'itemtype' => '', 'items_id' => 0];
       $available = [];
      foreach (AssetsignItem::getAvailableItems($probe) as $label => $rows) {
          $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['itemtype'], $managed, true)));
         if ($rows !== []) {
             $available[$label] = $rows;
         }
      }

       TemplateRenderer::getInstance()->display('@assetsign/ticket_tab.html.twig', [
           'ticket'      => $ticket,
           'sheets'      => $sheets,
           'requesters'  => $requesters,
           'available'   => $available,
           'can_create'  => Session::haveRight(self::$rightname, UPDATE) && $ticket->canUpdateItem() && $requesters !== [],
           'form_url'    => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/ticket_handover.php',
           'sheet_url'   => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/assetsign.form.php',
           'csrf_token'  => Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Issue #183 : remet un materiel au demandeur d'un ticket - attribution GLPI (champ Utilisateur),
     * qui cree la feuille en brouillon rattachee au ticket, et lien materiel <-> ticket.
     *
     * @return self la feuille creee
     */
   public static function createFromTicket(\Ticket $ticket, string $itemtype, int $items_id, int $users_id): self {
       $config = Config::getForEntity((int) $ticket->fields['entities_id']);
      if (!in_array($itemtype, $config->getManagedItemtypes(), true) || !class_exists($itemtype)) {
          throw new \RuntimeException(__('Ce type de matériel ne déclenche pas de feuille d\'attribution (Configuration > Général).', 'assetsign'));
      }
       $requesters = array_map(static fn (array $a): int => (int) $a['users_id'], $ticket->getUsers(\CommonITILActor::REQUESTER));
      if (!in_array($users_id, $requesters, true)) {
          throw new \RuntimeException(__('Le bénéficiaire doit être un demandeur du ticket.', 'assetsign'));
      }
       $item = new $itemtype();
      if (!$item->getFromDB($items_id) || !$item->canUpdateItem()) {
          throw new \RuntimeException(__('Élément introuvable ou non visible avec vos droits.', 'assetsign'));
      }
      if ((int) ($item->fields['users_id'] ?? 0) === $users_id) {
          throw new \RuntimeException(__('Ce matériel est déjà attribué à cette personne.', 'assetsign'));
      }

       $item->update(['id' => $items_id, 'users_id' => $users_id, '_assetsign_tickets_id' => $ticket->getID()]);

       $sheet = new self();
      if (!$sheet->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $items_id, 'tickets_id' => $ticket->getID(), 'status' => self::STATUS_DRAFT])) {
          throw new \RuntimeException(__('La feuille n\'a pas pu être créée : vérifiez que l\'attribution déclenche une feuille pour cette entité (Configuration > Général).', 'assetsign'));
      }
       // Materiel visible sur le ticket (onglet Elements), comme une liaison faite a la main.
       $link = new \Item_Ticket();
      if (!$link->getFromDBByCrit(['tickets_id' => $ticket->getID(), 'itemtype' => $itemtype, 'items_id' => $items_id])) {
          $link->add(['tickets_id' => $ticket->getID(), 'itemtype' => $itemtype, 'items_id' => $items_id]);
      }
       return $sheet;
   }

    /** @return array{id: int, label: string}|null */
   private static function ticketLabel(int $tickets_id): ?array {
       $ticket = new \Ticket();
      if (!$ticket->getFromDB($tickets_id)) {
          return null;
      }
       return ['id' => $tickets_id, 'label' => sprintf('#%d — %s', $tickets_id, (string) $ticket->fields['name'])];
   }

    /** Issue #183 : rattache la feuille a un ticket ouvert dont le beneficiaire est demandeur. */
   public function linkTicket(int $tickets_id): void {
      if ($tickets_id === 0) {
          $this->update(['id' => $this->getID(), 'tickets_id' => 0]);
          return;
      }
       $ticket = new \Ticket();
      if (!$ticket->getFromDB($tickets_id) || !in_array((int) $this->fields['users_id'], array_map(static fn (array $a): int => (int) $a['users_id'], $ticket->getUsers(\CommonITILActor::REQUESTER)), true)) {
          throw new \RuntimeException(__('Le bénéficiaire doit être un demandeur du ticket.', 'assetsign'));
      }
       $this->update(['id' => $this->getID(), 'tickets_id' => $tickets_id]);
   }

    /** @return array<int, string> tickets ouverts dont le beneficiaire est demandeur (id => libelle) */
   public function getBeneficiaryOpenTickets(): array {
       global $DB;

       $tickets = [];
      if ((int) $this->fields['users_id'] <= 0) {
          return $tickets;
      }
      foreach ($DB->request([
          'SELECT'     => ['glpi_tickets.id', 'glpi_tickets.name'],
          'FROM'       => 'glpi_tickets',
          'INNER JOIN' => ['glpi_tickets_users' => ['FKEY' => ['glpi_tickets_users' => 'tickets_id', 'glpi_tickets' => 'id']]],
          'WHERE'      => [
              'glpi_tickets_users.users_id' => (int) $this->fields['users_id'],
              'glpi_tickets_users.type'     => \CommonITILActor::REQUESTER,
              'glpi_tickets.is_deleted'     => 0,
              'glpi_tickets.status'         => \Ticket::getNotSolvedStatusArray(),
          ],
          'ORDER'      => 'glpi_tickets.id DESC',
          'LIMIT'      => 30,
      ]) as $row) {
          $tickets[(int) $row['id']] = sprintf('#%d — %s', (int) $row['id'], (string) $row['name']);
      }
       return $tickets;
   }

    /**
     * Issue #183 : a la signature, suivi dans le ticket rattache (document signe joint au ticket) et,
     * si l'entite le demande, ticket resolu.
     */
   private function notifyLinkedTicket(int $documents_id): void {
       $tickets_id = (int) ($this->fields['tickets_id'] ?? 0);
      if ($tickets_id <= 0) {
          return;
      }
       $ticket = new \Ticket();
      if (!$ticket->getFromDB($tickets_id)) {
          return;
      }
      if ($documents_id > 0) {
          (new Document_Item())->add(['documents_id' => $documents_id, 'itemtype' => 'Ticket', 'items_id' => $tickets_id]);
      }
       $target = $this->getTargetItem();
       $text = sprintf(
           __('La feuille n° %1$d (%2$s) pour %3$s « %4$s » a été signée. Le document signé est joint au ticket.', 'assetsign'),
           $this->getID(),
           self::getTypes()[(int) $this->fields['type']] ?? '',
           self::getCanonicalItemtypeLabel((string) $this->fields['itemtype']),
           (string) ($target['name'] ?? '')
       );
       // La signature se fait dans la session du BENEFICIAIRE : les messages de GLPI lies au ticket
       // (champs obligatoires du gabarit...) ne doivent pas lui etre affiches.
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       (new \ITILFollowup())->add(['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'content' => $text, 'is_private' => 0]);
       $config = Config::getForEntity((int) $this->fields['entities_id']);
      if ((int) ($config->fields['resolve_ticket_on_signature'] ?? 1) === 1 && in_array((int) $ticket->fields['status'], \Ticket::getNotSolvedStatusArray(), true)) {
         if (!(new \ITILSolution())->add(['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'content' => $text])) {
             // Ex. champ obligatoire du gabarit de ticket non renseigne (categorie...) : le technicien
             // le voit dans le ticket au lieu d'une resolution silencieusement absente.
             (new \ITILFollowup())->add(['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'is_private' => 1, 'content' => __(
                 'Le ticket n\'a pas pu être résolu automatiquement après la signature (un champ obligatoire du ticket n\'est sans doute pas renseigné, comme la catégorie) : résolvez-le manuellement.',
                 'assetsign'
             )]);
         }
      }
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
   }

    /** Retire un objet du parc de la fiche (id de la ligne) et regenere le PDF non signe. */
   public function removeInventoriedItem(int $assetsignitems_id): void {
      if (!$this->isStillEditable()) {
          return;
      }
       AssetsignItem::detach($this->getID(), $assetsignitems_id);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Peripheriques connectes nativement au materiel (onglet « Connexions » de GLPI) qui ne figurent
     * pas encore sur cette fiche ni sur une autre fiche en attente.
     *
     * @return list<array{itemtype: string, items_id: int, name: string, type_label: string, serial: string}>
     */
   public function getSuggestedConnectedItems(): array {
       $itemtype = (string) $this->fields['itemtype'];
      if (!class_exists($itemtype)) {
          return [];
      }
       $main = new $itemtype();
      if (!$main->getFromDB((int) $this->fields['items_id'])) {
          return [];
      }

       $listed = [];
      foreach (AssetsignItem::getForAssetsign($this->getID()) as $row) {
          $listed[$row['itemtype'] . '#' . $row['items_id']] = true;
      }

       $suggestions = [];
      foreach (AssetsignItem::getConnectedItems($main) as $connected) {
         if (isset($listed[$connected['itemtype'] . '#' . $connected['items_id']])
             || AssetsignItem::getPendingSheetIdFor($connected['itemtype'], $connected['items_id'], $this->getID()) !== null) {
             continue;
         }
          $snapshot = AssetsignItem::snapshot($connected['itemtype'], $connected['items_id']);
         if ($snapshot !== null) {
             $suggestions[] = $connected + $snapshot;
         }
      }
       return $suggestions;
   }

    /**
     * Ajoute tous les peripheriques connectes proposes (bouton de la fiche) et regenere le PDF.
     *
     * @return int nombre d'elements ajoutes
     */
   public function attachConnectedItems(bool $automatic = false, bool $regenerate = true): int {
      if (!$this->isStillEditable()) {
          return 0;
      }
       $added = 0;
      foreach ($this->getSuggestedConnectedItems() as $suggestion) {
         if (AssetsignItem::attach($this, $suggestion['itemtype'], $suggestion['items_id'], $automatic) === null) {
             $added++;
         }
      }
      if ($added > 0 && $regenerate) {
          $this->assignHandedItemsInGlpi();
          $this->regenerateUnsignedPdf();
      }
       return $added;
   }

    /**
     * Fiches creees automatiquement : ajout d'office des peripheriques connectes si l'entite l'a
     * active (Config::auto_attach_connected_items), avant la generation du premier PDF.
     */
   private function attachConnectedItemsIfConfigured(): void {
      if ((int) (Config::getForEntity((int) $this->fields['entities_id'])->fields['auto_attach_connected_items'] ?? 0) === 1) {
          $this->attachConnectedItems(true, false);
      }
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
     * Renseigne ou corrige l'organisme beneficiaire d'un Don, meme motif que
     * updateVenteDetails() (utile pour un Don declenche automatiquement par
     * changement d'Etat, ou aucun organisme n'est connu a la creation).
     */
   public function updateDonDetails(string $organizationName): void {
      if ((int) $this->fields['type'] !== self::TYPE_DON || !$this->isStillEditable()) {
          return;
      }
       DonDetails::upsertForAssetsign($this->getID(), $organizationName);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Renseigne ou corrige le prestataire d'une Destruction, meme motif que
     * updateVenteDetails()/updateDonDetails() ci-dessus (issue #78).
     */
   public function updateDestructionDetails(string $providerName): void {
      if ((int) $this->fields['type'] !== self::TYPE_DESTRUCTION || !$this->isStillEditable()) {
          return;
      }
       DestructionDetails::upsertForAssetsign($this->getID(), $providerName);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Joint un document natif GLPI (Document/Document_Item) a cette fiche
     * elle-meme — justificatif de don ou certificat de destruction (issue #78),
     * sans nouvelle table de stockage. showForm() n'est PAS enveloppee par le
     * cadre d'onglets standard de CommonDBTM (fiche en lecture seule autonome,
     * cf. son propre commentaire) : aucun onglet "Documents" natif n'existe
     * donc sur cette fiche — le document est plutot re-liste explicitement via
     * getAttachedDocuments() ci-dessous, affiche dans assetsign_form.html.twig
     * (meme motif que Movement::getAttachedDocuments()/movement_form.html.twig).
     * copy() plutot que move_uploaded_file() (GLPI reconstruit un objet Request
     * depuis $_FILES a certains points de la requete, cf. commentaire
     * equivalent de Config::uploadLogo()), silencieux si aucun fichier n'a ete
     * selectionne (facultatif), message d'erreur si un fichier a ete choisi
     * mais est invalide.
     */
   public function attachUploadedDocument(array $file): void {
      if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
          return; // aucun fichier selectionne : rien a faire, pas une erreur
      }
      if (($file['error'] ?? null) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du document.', 'assetsign'), false, ERROR);
          return;
      }

       $tmpName = uniqid('assetsign_doc_', true) . '_' . basename((string) $file['name']);
      if (!copy($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du document.', 'assetsign'), false, ERROR);
          return;
      }

       $document = new Document();
       $documents_id = $document->add([
           'name'          => (string) ($file['name'] ?? $tmpName),
           'entities_id'   => $this->fields['entities_id'],
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);
      if (!$documents_id) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du document.', 'assetsign'), false, ERROR);
          return;
      }

       (new Document_Item())->add([
           'documents_id' => $documents_id,
           'itemtype'     => self::class,
           'items_id'     => $this->getID(),
       ]);
   }

    /**
     * Documents joints a CETTE fiche via attachUploadedDocument() (justificatif
     * de don, certificat de destruction — issue #78) : reutilise Document_Item,
     * la relation polymorphe deja native GLPI, jamais un nouveau stockage.
     * Meme motif exact que Movement::getAttachedDocuments().
     * @return list<array{id: int, name: string}>
     */
   public static function getAttachedDocuments(int $assetsigns_id): array {
       global $DB;

       $documents = [];
      foreach ($DB->request([
          'SELECT'     => ['glpi_documents.id', 'glpi_documents.name'],
          'FROM'       => 'glpi_documents_items',
          'INNER JOIN' => ['glpi_documents' => ['FKEY' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id']]],
          'WHERE'      => ['glpi_documents_items.itemtype' => self::class, 'glpi_documents_items.items_id' => $assetsigns_id],
          'ORDER'      => 'glpi_documents.date_creation DESC',
      ]) as $row) {
          $documents[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
      }
       return $documents;
   }

    /**
     * Points de controle qualite renseignes sur CETTE fiche (cf. ROADMAP.md V1,
     * issue #74, ChecklistItem) - meme forme que Maintenance::getChecklistResults(),
     * pour rester interchangeable cote gabarit Twig. Un point desactive ou
     * retire du catalogue APRES l'enregistrement de cette valeur reste
     * visible ici (jointure sur la ligne existante, pas sur le catalogue
     * actif) : un constat historique ne doit jamais perdre silencieusement
     * une reponse deja donnee.
     * @return array<int, array{id: int, name: string, type: int, value: ?string}>
     */
   public function getChecklistResults(): array {
       global $DB;

       $results = [];
      foreach ($DB->request([
           'SELECT' => [
               'glpi_plugin_assetsign_checklistitems.id',
               'glpi_plugin_assetsign_checklistitems.name',
               'glpi_plugin_assetsign_checklistitems.type',
               'glpi_plugin_assetsign_checklistvalues.value',
           ],
           'FROM'   => 'glpi_plugin_assetsign_checklistvalues',
           'INNER JOIN' => [
               'glpi_plugin_assetsign_checklistitems' => [
                   'FKEY' => [
                       'glpi_plugin_assetsign_checklistvalues' => 'plugin_assetsign_checklistitems_id',
                       'glpi_plugin_assetsign_checklistitems'  => 'id',
                   ],
               ],
           ],
           'WHERE' => ['glpi_plugin_assetsign_checklistvalues.plugin_assetsign_assetsigns_id' => $this->getID()],
           'ORDER' => 'glpi_plugin_assetsign_checklistitems.name',
       ]) as $row) {
          $results[] = [
              'id'    => (int) $row['id'],
              'name'  => $row['name'],
              'type'  => (int) $row['type'],
              'value' => $row['value'],
          ];
      }
       return $results;
   }

    /** @return array<int, string|null> Valeurs deja enregistrees, indexees par ID de ChecklistItem (pre-remplissage du formulaire d'edition). */
   public function getChecklistValuesByItemId(): array {
       $byId = [];
      foreach ($this->getChecklistResults() as $result) {
          $byId[$result['id']] = $result['value'];
      }
       return $byId;
   }

    /**
     * Enregistre (ou remplace) les valeurs de checklist qualite soumises pour
     * cette fiche - $itemValues est le tableau brut $_POST['checklist'] (id du
     * ChecklistItem => valeur soumise), meme convention que
     * Maintenance::createWithChecklist(). Seuls les points APPLICABLES au type
     * de CETTE fiche (ChecklistItem::getActiveItemsForMovementType($this->fields['type']))
     * sont pris en compte, jamais une valeur soumise pour un point etranger
     * (formulaire modifie/rejoue). Contrairement a Maintenance (fiche immuable
     * des sa creation), une Assetsign reste EDITABLE tant que
     * isStillEditable() - meme garde que addAccessory()/updateObservations() :
     * un technicien peut completer la checklist en plusieurs fois, jusqu'a la
     * signature. Chaque appel remplace entierement la reponse existante pour
     * un point donne (upsert par contrainte UNIQUE, jamais un doublon).
     * @param array<int|string, mixed> $itemValues
     */
   public function setChecklistValues(array $itemValues): void {
      if (!$this->isStillEditable()) {
          return;
      }

       global $DB;

      foreach (ChecklistItem::getActiveItemsForMovementType((int) $this->fields['type']) as $checklistItemId => $checklistItem) {
         if (!array_key_exists($checklistItemId, $itemValues) && !array_key_exists((string) $checklistItemId, $itemValues)) {
             continue;
         }
          $submitted = $itemValues[$checklistItemId] ?? $itemValues[(string) $checklistItemId];

          $value = match ($checklistItem['type']) {
              ChecklistItem::TYPE_TEXT, ChecklistItem::TYPE_SELECT => trim((string) $submitted),
              default => null,
          };

            // Case a cocher : presence de la cle suffit. Texte/select : seule une
            // valeur non vide est enregistree, sinon le point reste simplement
            // absent (meme convention que Maintenance::createWithChecklist()).
         if ($checklistItem['type'] !== ChecklistItem::TYPE_CHECKBOX && $value === '') {
             continue;
         }

            $DB->updateOrInsert('glpi_plugin_assetsign_checklistvalues', [
              'value' => $value,
            ], [
              'plugin_assetsign_assetsigns_id'     => $this->getID(),
              'plugin_assetsign_checklistitems_id' => $checklistItemId,
            ]);
      }
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

       // Issue #166 : n° de serie/modele relus dans GLPI tant que la fiche n'est pas signee.
       AssetsignItem::refreshSnapshots($this->getID());

       // Le remplacement du PDF ajoute des messages techniques du coeur GLPI (« Document copié avec
       // succès », « Fichier PDF/xx/... supprimé ») qui noieraient le message utile de l'action :
       // meme motif que Departure/Attestation, on restaure les messages d'avant.
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];

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

       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
   }

   public function getTemplate(): ?Template {
      if (!$this->fields['plugin_assetsign_templates_id']) {
          return null;
      }
       $template = new Template();
       return $template->getFromDB($this->fields['plugin_assetsign_templates_id']) ? $template : null;
   }

    /** Kit assigne a cette fiche (V3, issue #83), meme motif que getTemplate() ci-dessus. */
   public function getKit(): ?Kit {
      if (!$this->fields['plugin_assetsign_kits_id']) {
          return null;
      }
       $kit = new Kit();
       return $kit->getFromDB($this->fields['plugin_assetsign_kits_id']) ? $kit : null;
   }

    /**
     * Assigne (ou retire, kits_id=0) le Kit utilise pour cette remise (cf.
     * ROADMAP.md V3, issue #83, "Kits/accessoires avec controle automatique au
     * retour"), meme garde isStillEditable() que updateVenteDetails()/
     * updateDonDetails() et meme regeneration du PDF non signe qu'addAccessory().
     * Pose volontairement sur N'IMPORTE QUEL type de fiche (pas seulement
     * Attribution) : un technicien doit pouvoir assigner/corriger le kit
     * directement sur la Restitution elle-meme (ex: kit determine apres coup,
     * ou correction d'un report automatique errone — cf.
     * resolveKitForAutomaticCreation() ci-dessous), pas seulement a
     * l'Attribution d'origine.
     */
   public function updateKit(int $kits_id): void {
      if (!$this->isStillEditable()) {
          return;
      }
       $this->update(['id' => $this->getID(), 'plugin_assetsign_kits_id' => max(0, $kits_id)]);
       $this->regenerateUnsignedPdf();
   }

    /**
     * Resultat du controle automatique de kit pour CETTE fiche (V3, issue #83) —
     * `null` (jamais un tableau "vide") si aucun kit n'est assigne, ou si le kit
     * assigne n'a plus aucun accessoire attendu configure : meme principe de
     * non-invention que getResidualValue()/attachChecklistSummaries(), l'absence
     * de kit n'est pas un defaut a signaler. Les accessoires "reellement
     * restitues" sont ceux enregistres sur CETTE fiche precise (getAccessories(),
     * deja alimentee par addAccessory() — les accessoires physiquement rendus au
     * technicien au moment de LA restitution), jamais ceux de l'attribution
     * d'origine. PUREMENT calcule a l'affichage (cf. ADR-passeport-v1.md,
     * risque 2.2) : aucune donnee copiee, la comparaison (Kit::computeCompleteness())
     * est refaite depuis les tables reelles a chaque appel — sans consequence de
     * performance ici (un seul enregistrement, contrairement a
     * PassportEvent::attachKitSummaries() qui doit batcher pour toute une frise).
     * @return array{kit_name: string, expected_total: int, returned_count: int, missing_names: string[], complete: bool, color: string}|null
     */
   public function getKitCompleteness(): ?array {
       $kit = $this->getKit();
      if ($kit === null) {
          return null;
      }

       $expectedIds = $kit->getExpectedAccessoryIds();
      if ($expectedIds === []) {
          return null;
      }

       $actualIds = array_map(static fn (array $a): int => (int) $a['accessories_id'], $this->getAccessories());
       $result = Kit::computeCompleteness($expectedIds, $actualIds);

       $names = $kit->getExpectedAccessoryNames();
       $missingNames = array_map(
           static fn (int $id): string => $names[$id] ?? ('#' . $id),
           $result['missing_ids']
       );

       return [
           'kit_name'       => (string) $kit->fields['name'],
           'expected_total' => $result['expected_total'],
           'returned_count' => $result['returned_count'],
           'missing_names'  => $missingNames,
           'complete'       => $result['complete'],
           'color'          => Kit::colorForCompleteness($result),
       ];
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
      } else if ((int) $this->fields['status'] === self::STATUS_AWAITING_COSIGNATURE && empty($this->fields['date_viewed'])) {
          // Contre-signature (issue #143) : date_viewed reutilise pour
          // l'etape en cours (cf. commentaire sur STATUSES_AWAITING_SIGNATURE)
          // — pas de transition de statut ici (contrairement au cas
          // beneficiaire ci-dessus), juste l'horodatage de premiere
          // consultation par le responsable.
          $this->update(['id' => $this->getID(), 'date_viewed' => date('Y-m-d H:i:s')]);
      }
   }

   public function markSigned(string $signedPdfPath, array $proof, bool $notify = true): void {
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
       $this->notifyLinkedTicket((int) $document->getID());

       Token::invalidateForAssetsign($this->getID());
      if ($notify) {
          NotificationEvent::raiseEvent('signed', $this);
      }
   }

    /**
     * Signatures multiples (issue #143) : le beneficiaire vient de signer, et
     * cette fiche requiert une contre-signature (cosigner_users_id > 0, cf.
     * launchWorkflow()) — la fiche n'est PAS encore consideree signee
     * (STATUS_AWAITING_COSIGNATURE, statut intermediaire). Le PDF signe par
     * le seul beneficiaire est tout de meme attache comme document_id_signed
     * (tracabilite de cette etape) ; markCosigned() ci-dessous produira le
     * PDF final (document_id_cosigned) une fois la contre-signature obtenue.
     *
     * $rawSignatureImagePng (data URI PNG, meme format que celui traite par
     * SignatureStamper) est conserve en base (cosigner_pending_signature) le
     * temps de l'attente : SignatureStamper re-rend tout le gabarit Twig a
     * chaque etape (pas de calque/overlay, cf. son docblock) — sans le
     * reinjecter au moment de la contre-signature, la signature du
     * beneficiaire disparaitrait purement et simplement du PDF final.
     *
     * Ne declenche PAS elle-meme la notification 'cosignature_requested' :
     * contrairement a markSigned()/markCosigned(), le jeton du responsable
     * n'existe pas encore a ce stade (cree par l'appelant juste apres, cf.
     * SignController::submitBeneficiarySignature()) — la notifier ici
     * resoudrait ##assetsign.sign_url## avant que _current_raw_token ne soit
     * renseigne, donc vide.
     */
   public function markAwaitingCosignature(string $signedPdfPath, array $proof, string $rawSignatureImagePng): void {
       $document = $this->attachDocument($signedPdfPath, $this->getDocumentTitle() . ' [signée bénéficiaire]');

       Signature::recordProofForAssetsign($this, $proof);

       $this->update([
           'id'                         => $this->getID(),
           'status'                     => self::STATUS_AWAITING_COSIGNATURE,
           'document_id_signed'         => $document->getID(),
           'cosigner_pending_signature' => $rawSignatureImagePng,
           // Reutilises pour suivre l'etape EN COURS (desormais celle du
           // responsable) plutot que de dupliquer un jeu de colonnes
           // cosigner_* parallele — cf. commentaire sur
           // STATUSES_AWAITING_SIGNATURE.
           'date_sent'                  => date('Y-m-d H:i:s'),
           'date_viewed'                => null,
           'reminder_count'             => 0,
       ]);

       // Jeton BENEFICIAIRE invalide (deja utilise pour cette signature) — un
       // nouveau jeton, distinct, est cree pour le responsable par
       // l'appelant (cf. SignController::submitBeneficiarySignature()), qui
       // declenche ensuite lui-meme la notification 'cosignature_requested'
       // (cf. commentaire ci-dessus).
       Token::invalidateForAssetsign($this->getID());
   }

    /**
     * Contre-signature terminee (issue #143) : la fiche devient reellement
     * SIGNEE — document_id_cosigned (PAS document_id_signed, qui reste le
     * PDF intermediaire signe par le seul beneficiaire, conserve pour la
     * tracabilite, cf. markAwaitingCosignature() ci-dessus). Distinct de
     * markSigned() : cible une colonne differente et efface
     * cosigner_pending_signature (plus necessaire une fois le PDF final
     * produit — inutile de garder cette image de signature en base).
     */
   public function markCosigned(string $signedPdfPath, array $proof): void {
       $document = $this->attachDocument($signedPdfPath, $this->getDocumentTitle() . ' [signée]');

       Signature::recordProofForAssetsign($this, $proof);

       $this->update([
           'id'                         => $this->getID(),
           'status'                     => self::STATUS_SIGNED,
           'document_id_cosigned'       => $document->getID(),
           'date_signed'                => date('Y-m-d H:i:s'),
           'cosigner_pending_signature' => '',
       ]);
       $this->notifyLinkedTicket((int) $document->getID());

       Token::invalidateForAssetsign($this->getID());
       NotificationEvent::raiseEvent('signed', $this);
   }

   private function attachDocument(string $filePath, string $name): Document {
       // Document::moveDocument() lit le fichier source dans GLPI_TMP_DIR :
       // $filePath doit y avoir ete ecrit prealablement (cf. SignatureStamper::apply()).
       // Messages techniques du coeur (« Document copie avec succes ») : la signature se fait dans la
       // session du signataire, qui n'a pas a les voir.
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
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

       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
       return $document;
   }

    // ================================================================================
    // Crons
    // ================================================================================

   public static function cronInfo(string $name): array {
       return match ($name) {
           'assetsignReminders'     => ['description' => __('Envoie les relances de signature dues', 'assetsign')],
           'assetsignExpire'        => ['description' => __('Marque comme expirées les attributions hors délai', 'assetsign')],
           'assetsignExpiryWarning' => ['description' => __('Alerte le technicien des attributions sur le point d\'expirer', 'assetsign')],
           'assetsignDraftReminder' => ['description' => __('Rappelle au technicien les feuilles restées en brouillon', 'assetsign')],
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
          throw new \RuntimeException(__('Cette attribution ne peut plus être relancée (déjà signée ou expirée).', 'assetsign'));
      }

       $config = Config::getForEntity((int) $this->fields['entities_id']);

      if ((int) $this->fields['status'] === self::STATUS_AWAITING_COSIGNATURE) {
          // Contre-signature (issue #143) : jamais geree par un prestataire
          // externe — Provider vise les futures integrations SaaS pour la
          // signature du BENEFICIAIRE (cf. docblock de CanvasProvider), hors
          // perimetre pour cette premiere version de la contre-signature.
          // Jeton "responsable" regenere directement, meme mecanisme que
          // Token::regenerateForAssetsign() utilise par CanvasProvider mais
          // scope au role cosigner.
          $raw = Token::regenerateForAssetsign($this, (int) $config->fields['link_validity_days'], forCosigner: true);
          $this->_current_raw_token = $raw;
      } else {
          $provider = Provider\ProviderFactory::for($config);
          // Nouvelle demande a chaque relance : le jeton precedent est invalide dans le meme mouvement.
          $provider->createRequest($this, '');
      }

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
           // Issue #157 : fiches d'un dossier de depart relancees par le dossier (Departure::runReminders()).
           'WHERE' => ['status' => self::STATUSES_AWAITING_SIGNATURE, 'is_deleted' => 0, 'plugin_assetsign_departures_id' => 0],
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
               // Issue #157 : un dossier de depart reste ouvert jusqu'a sa signature ou son annulation.
               'plugin_assetsign_departures_id' => 0,
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
               'plugin_assetsign_departures_id' => 0,
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

   public static function cronAssetsignDraftReminder(CronTask $task): int {
       $count = self::runDraftReminders();
       $task->addVolume($count);
       return $count > 0 ? 1 : 0;
   }

    /** Delai entre deux rappels pour un meme brouillon (issue #179). */
   public const DRAFT_REMINDER_REPEAT_DAYS = 7;

    /**
     * Issue #179 : rappelle par e-mail au technicien (users_id_tech) une feuille restee en brouillon
     * depuis Config::draft_reminder_days jours (0 = desactive), au plus une fois par semaine.
     */
   public static function runDraftReminders(): int {
       global $DB;

       $count = 0;
       $configByEntity = [];
      foreach ($DB->request([
          'FROM'  => self::getTable(),
          'WHERE' => [
              'status'        => self::STATUS_DRAFT,
              'is_deleted'    => 0,
              'users_id_tech' => ['>', 0],
          ],
      ]) as $row) {
          $entityId = (int) $row['entities_id'];
          $config = $configByEntity[$entityId] ??= Config::getForEntity($entityId);
          $days = (int) ($config->fields['draft_reminder_days'] ?? 3);
         if ($days <= 0 || self::daysSince((string) $row['date_creation']) < $days) {
             continue;
         }
         if (!empty($row['draft_reminder_sent_at']) && self::daysSince((string) $row['draft_reminder_sent_at']) < self::DRAFT_REMINDER_REPEAT_DAYS) {
             continue;
         }
          $assetsign = new self();
          $assetsign->fields = $row;
          $DB->update(self::getTable(), ['draft_reminder_sent_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
          NotificationEvent::raiseEvent('draft_pending', $assetsign);
          $count++;
      }
       return $count;
   }

    /** Nombre total de brouillons a envoyer (le titre de l'encadre, au-dela des lignes affichees). */
   public static function countDraftsToSend(int $users_id): int {
       global $DB;

      if ($users_id <= 0 || !Session::haveRight(self::$rightname, UPDATE)) {
          return 0;
      }
       return (int) $DB->request([
           'COUNT' => 'c',
           'FROM'  => self::getTable(),
           'WHERE' => self::draftsToSendCriteria($users_id),
       ])->current()['c'];
   }

    /** @return array<string, mixed> */
   private static function draftsToSendCriteria(int $users_id): array {
       return [
           'status'        => self::STATUS_DRAFT,
           'is_deleted'    => 0,
           'users_id_tech' => [$users_id, 0],
       ] + getEntitiesRestrictCriteria(self::getTable(), '', '', true);
   }

    /**
     * Issue #179 : brouillons a envoyer visibles par l'utilisateur sur la page d'accueil - les siens,
     * et ceux crees sans technicien (inventaire automatique, tache planifiee) dans ses entites.
     *
     * @return list<array<string, mixed>>
     */
   public static function getDraftsToSend(int $users_id, int $limit = 10): array {
       global $DB;

      if ($users_id <= 0 || !Session::haveRight(self::$rightname, UPDATE)) {
          return [];
      }
       $drafts = [];
      foreach ($DB->request([
          'FROM'  => self::getTable(),
          'WHERE' => self::draftsToSendCriteria($users_id),
          'ORDER' => 'date_creation DESC',
          'LIMIT' => $limit,
      ]) as $row) {
          $sheet = new self();
          $sheet->fields = $row;
          $item = $sheet->getTargetItem();
          $beneficiary = $sheet->getBeneficiary();
          $drafts[] = [
              'id'          => (int) $row['id'],
              'type'        => self::getTypes()[(int) $row['type']] ?? '',
              'item'        => (string) ($item['name'] ?? ''),
              'beneficiary' => formatUserName(0, $beneficiary['name'] ?? '', $beneficiary['realname'] ?? '', $beneficiary['firstname'] ?? ''),
              'date'        => (string) $row['date_creation'],
              'mine'        => (int) $row['users_id_tech'] === $users_id,
          ];
      }
       return $drafts;
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
                `delegated_users_id` int unsigned NOT NULL DEFAULT 0,
                `delegation_date` timestamp NULL DEFAULT NULL,
                `delegation_reason` text,
                `delegated_by_users_id` int unsigned NOT NULL DEFAULT 0,
                `beneficiary_type` tinyint unsigned NOT NULL DEFAULT 0,
                `external_beneficiary_name` varchar(255) DEFAULT NULL,
                `external_beneficiary_contact` varchar(255) DEFAULT NULL,
                `plugin_assetsign_templates_id` int unsigned NOT NULL DEFAULT 0,
                `plugin_assetsign_kits_id` int unsigned NOT NULL DEFAULT 0,
                `plugin_assetsign_departures_id` int unsigned NOT NULL DEFAULT 0,
                `type` tinyint NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 0,
                `document_id_unsigned` int unsigned NOT NULL DEFAULT 0,
                `document_id_signed` int unsigned NOT NULL DEFAULT 0,
                `cosigner_users_id` int unsigned NOT NULL DEFAULT 0,
                `cosigner_pending_signature` longtext,
                `document_id_cosigned` int unsigned NOT NULL DEFAULT 0,
                `reminder_count` int unsigned NOT NULL DEFAULT 0,
                `date_sent` timestamp NULL DEFAULT NULL,
                `date_viewed` timestamp NULL DEFAULT NULL,
                `date_signed` timestamp NULL DEFAULT NULL,
                `date_expired` timestamp NULL DEFAULT NULL,
                `expiry_warning_sent` tinyint NOT NULL DEFAULT 0,
                `draft_reminder_sent_at` timestamp NULL DEFAULT NULL,
                `tickets_id` int unsigned NOT NULL DEFAULT 0,
                `loan_until` date DEFAULT NULL,
                `loan_reminder_sent_at` timestamp NULL DEFAULT NULL,
                `loan_tickets_id` int unsigned NOT NULL DEFAULT 0,
                `observations` text,
                `beneficiary_comment` text,
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `users_id` (`users_id`),
                KEY `users_id_tech` (`users_id_tech`),
                KEY `delegated_users_id` (`delegated_users_id`),
                KEY `cosigner_users_id` (`cosigner_users_id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `status` (`status`),
                KEY `type` (`type`),
                KEY `loan_until` (`loan_until`),
                KEY `plugin_assetsign_templates_id` (`plugin_assetsign_templates_id`),
                KEY `plugin_assetsign_kits_id` (`plugin_assetsign_kits_id`),
                KEY `plugin_assetsign_departures_id` (`plugin_assetsign_departures_id`),
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
         // Issue #183 : ticket a l'origine de la remise (demande de materiel).
         if (!$DB->fieldExists($table, 'tickets_id')) {
             $migration->addField($table, 'tickets_id', 'integer', ['value' => 0]);
             $migration->addKey($table, 'tickets_id');
             $migration->migrationOneTable($table);
         }
         // Issue #193 : pret temporaire (date de retour prevue, rappel, ticket de retard).
         if (!$DB->fieldExists($table, 'loan_until')) {
             $migration->addField($table, 'loan_until', 'date', ['null' => true]);
             $migration->addField($table, 'loan_reminder_sent_at', 'timestamp', ['null' => true]);
             $migration->addField($table, 'loan_tickets_id', 'fkey');
             $migration->addKey($table, 'loan_until');
             $migration->migrationOneTable($table);
         }
         // Issue #179 : date du dernier rappel envoye pour un brouillon.
         if (!$DB->fieldExists($table, 'draft_reminder_sent_at')) {
             $migration->addField($table, 'draft_reminder_sent_at', 'timestamp', ['null' => true]);
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
         if (!$DB->fieldExists($table, 'plugin_assetsign_kits_id')) {
             // V3, issue #83 ("Kits/accessoires avec controle automatique au
             // retour") : 0 = aucun kit assigne, meme repli que
             // plugin_assetsign_templates_id (jamais NULL). Pas de contrainte de
             // cle etrangere vers glpi_plugin_assetsign_kits, meme choix deja
             // fait pour plugin_assetsign_templates_id (cf. son propre
             // commentaire plus haut) : un Kit purge ne doit jamais entrainer la
             // suppression en cascade de tout l'historique des remises qui l'ont
             // utilise — seule une KEY (index de lecture) est necessaire.
             $migration->addField($table, 'plugin_assetsign_kits_id', 'integer', ['value' => 0, 'after' => 'plugin_assetsign_templates_id']);
             $migration->migrationOneTable($table);
             $migration->addKey($table, 'plugin_assetsign_kits_id');
         }
         if (!$DB->fieldExists($table, 'plugin_assetsign_departures_id')) {
             // Issue #157 : fiche de restitution rattachee a un dossier de depart
             // (Departure), 0 = fiche individuelle. Pas de cle etrangere, meme
             // choix que plugin_assetsign_kits_id ci-dessus.
             $migration->addField($table, 'plugin_assetsign_departures_id', 'fkey', ['after' => 'plugin_assetsign_kits_id']);
             $migration->migrationOneTable($table);
             $migration->addKey($table, 'plugin_assetsign_departures_id');
         }
         if (!$DB->fieldExists($table, 'delegated_users_id')) {
             // Delegation de signature (issue #115) : 'users_id' (le
             // beneficiaire d'origine) n'est JAMAIS modifie par une delegation -
             // ces 4 colonnes, toutes a 0/NULL par defaut (aucun effet pour une
             // fiche existante), portent l'etat de delegation separement, pour
             // garder une tracabilite complete (signataire prevu vs signataire
             // reel, cf. delegateSignatureTo()). 'delegated_by_users_id'
             // distingue une delegation faite par un technicien/admin de celle
             // faite par le beneficiaire lui-meme (self-service).
             $migration->addField($table, 'delegated_users_id', 'integer', ['value' => 0, 'after' => 'users_id_tech']);
             $migration->addField($table, 'delegation_date', 'timestamp', ['after' => 'delegated_users_id']);
             $migration->addField($table, 'delegation_reason', 'text', ['after' => 'delegation_date']);
             $migration->addField($table, 'delegated_by_users_id', 'integer', ['value' => 0, 'after' => 'delegation_reason']);
             $migration->migrationOneTable($table);
             $migration->addKey($table, 'delegated_users_id');
         }
         if (!$DB->fieldExists($table, 'cosigner_users_id')) {
             // Signatures multiples (issue #143) : contre-signature du
             // responsable hierarchique du beneficiaire, en plus de la
             // signature de ce dernier - meme convention "0 = absent" que
             // delegated_users_id ci-dessus (jamais NULL). cosigner_users_id
             // est snapshote au lancement (cf. launchWorkflow()), jamais
             // relu dynamiquement. cosigner_pending_signature conserve
             // temporairement l'image de signature du beneficiaire (data URI
             // PNG) le temps de la contre-signature - necessaire car
             // SignatureStamper re-rend tout le PDF depuis zero a chaque
             // etape (pas de calque/overlay). document_id_cosigned est le
             // PDF final a deux signatures, distinct de document_id_signed
             // qui reste le PDF intermediaire signe par le seul beneficiaire
             // (conserve pour la tracabilite).
             $migration->addField($table, 'cosigner_users_id', 'integer', ['value' => 0, 'after' => 'document_id_signed']);
             $migration->addField($table, 'cosigner_pending_signature', 'longtext', ['after' => 'cosigner_users_id']);
             $migration->addField($table, 'document_id_cosigned', 'integer', ['value' => 0, 'after' => 'cosigner_pending_signature']);
             $migration->migrationOneTable($table);
             $migration->addKey($table, 'cosigner_users_id');
         }
      }

       // Resultats de checklist qualite (cf. ROADMAP.md V1, issue #74, ChecklistItem) :
       // meme motif dual-table que glpi_plugin_assetsign_maintenancechecklistvalues
       // (Maintenance.php), volontairement une table SEPAREE (jamais partagee entre
       // Assetsign et Maintenance, ces deux sous-systemes restant deliberement
       // independants) - rattachee ici plutot que dans ChecklistItem::install() : la
       // contrainte de cle etrangere vers CETTE table (glpi_plugin_assetsign_assetsigns)
       // exige qu'elle existe deja, comme DamageMarker::install() apres Maintenance::install()
       // pour la meme raison (cf. hook.php).
       $checklistValuesTable = 'glpi_plugin_assetsign_checklistvalues';
      if (!$DB->tableExists($checklistValuesTable)) {
          $migration->displayMessage('Création de la table ' . $checklistValuesTable);
          $DB->doQuery("CREATE TABLE `$checklistValuesTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `plugin_assetsign_checklistitems_id` int unsigned NOT NULL,
                `value` text,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_assetsign_assetsigns_id`,`plugin_assetsign_checklistitems_id`),
                KEY `plugin_assetsign_checklistitems_id` (`plugin_assetsign_checklistitems_id`),
                CONSTRAINT `fk_acv_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_acv_checklistitem` FOREIGN KEY (`plugin_assetsign_checklistitems_id`) REFERENCES `glpi_plugin_assetsign_checklistitems` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
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
