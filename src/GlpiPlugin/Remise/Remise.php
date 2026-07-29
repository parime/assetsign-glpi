<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Migration;
use Document;
use Document_Item;
use NotificationEvent;
use Session;
use Glpi\Application\View\TemplateRenderer;

/**
 * Objet central du plugin : une ligne = une instance de workflow de remise
 * (remise initiale, restitution ou echange) pour un couple (materiel, utilisateur).
 */
class Remise extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_REMISE;

    /**
     * Jeton de signature brut, valide uniquement le temps de la requete en cours
     * (jamais persiste, jamais relu depuis la base). Alimente par
     * Provider\CanvasProvider::createRequest() puis lu par
     * NotificationTargetRemise::addDataForTemplate() pour construire ##remise.sign_url##.
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

    // --- Types --------------------------------------------------------------------
    public const TYPE_HANDOVER = 0; // remise
    public const TYPE_RETURN   = 1; // restitution
    // 2 volontairement inutilise (ancien TYPE_EXCHANGE, retire : un transfert direct
    // entre deux personnes est desormais traite comme une remise (TYPE_HANDOVER)
    // au nouveau detenteur, cf. handleUserBasedTrigger())

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

    public static function getTypeName($nb = 0): string
    {
        return _n('Remise', 'Remises', $nb, 'remise');
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-signature';
    }

    public function getSearchOptions(): array
    {
        $options = [];

        $options[1] = [
            'table' => self::getTable(),
            'field' => 'id',
            'name'  => __('ID'),
            'datatype' => 'number',
        ];
        $options[2] = [
            'table'    => self::getTable(),
            'field'    => 'itemtype',
            'name'     => __('Type de matériel', 'remise'),
            'datatype' => 'itemtype',
        ];
        $options[3] = [
            'table'    => self::getTable(),
            'field'    => 'items_id',
            'name'     => __('Matériel', 'remise'),
            'datatype' => 'itemlink',
            'itemlink_type' => '',
        ];
        $options[4] = [
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield' => 'users_id',
            'name'     => __('Bénéficiaire', 'remise'),
            'datatype' => 'itemlink',
            'itemlink_type' => 'User',
        ];
        $options[5] = [
            'table'    => self::getTable(),
            'field'    => 'status',
            'name'     => __('Statut', 'remise'),
            'datatype' => 'specific',
            'searchtype' => 'equals',
        ];
        $options[6] = [
            'table'    => self::getTable(),
            'field'    => 'type',
            'name'     => __('Type de remise', 'remise'),
            'datatype' => 'specific',
            'searchtype' => 'equals',
        ];
        $options[7] = [
            'table'    => self::getTable(),
            'field'    => 'date_sent',
            'name'     => __('Date d\'envoi', 'remise'),
            'datatype' => 'datetime',
        ];
        $options[8] = [
            'table'    => self::getTable(),
            'field'    => 'date_signed',
            'name'     => __('Date de signature', 'remise'),
            'datatype' => 'datetime',
        ];
        $options[9] = [
            'table'    => self::getTable(),
            'field'    => 'reminder_count',
            'name'     => __('Nombre de relances', 'remise'),
            'datatype' => 'number',
        ];

        return $options;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return self::getStatuses()[$values['status']] ?? $values['status'];
        }
        if ($field === 'type') {
            return self::getTypes()[$values['type']] ?? $values['type'];
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Fiche en lecture seule : une Remise est un enregistrement genere
     * automatiquement (jamais saisi manuellement), le formulaire n'a donc
     * pas vocation a etre editable comme un CommonDBTM classique.
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@remise/remise_form.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'statuses'     => self::getStatuses(),
            'types'        => self::getTypes(),
            'beneficiary'  => $this->isNewID($ID) ? [] : $this->getBeneficiary(),
            'target_item'  => $this->isNewID($ID) ? [] : $this->getTargetItem(),
            'reminders'    => $this->isNewID($ID) ? 0 : Reminder::countForRemise((int) $ID),
            'can_remind'   => !$this->isNewID($ID)
                && in_array((int) $this->fields['status'], self::STATUSES_AWAITING_SIGNATURE, true)
                && \Session::haveRight(self::$rightname, UPDATE),
            'accessories'          => $this->isNewID($ID) ? [] : $this->getAccessories(),
            'can_edit_accessories' => !$this->isNewID($ID) && $this->isStillEditable() && \Session::haveRight(self::$rightname, UPDATE),
            'csrf_token'   => \Session::getNewCSRFToken(),
        ]);

        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
            return '';
        }
        $count = countElementsInTable(
            self::getTable(),
            ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_deleted' => 0]
        );
        return self::createTabEntry(__('Remises', 'remise'), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof CommonDBTM)) {
            return false;
        }
        self::showForItem($item);
        return true;
    }

    public static function showForItem(CommonDBTM $item): void
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_deleted' => 0],
            'ORDER' => 'date_creation DESC',
        ]);

        $twig = \Glpi\Application\View\TemplateRenderer::getInstance();
        $twig->display('@remise/remise_tab.html.twig', [
            'item'    => $item,
            'remises' => iterator_to_array($rows),
            'statuses' => self::getStatuses(),
        ]);
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT     => __('Brouillon', 'remise'),
            self::STATUS_PENDING   => __('En attente', 'remise'),
            self::STATUS_SENT      => __('Envoyé', 'remise'),
            self::STATUS_VIEWED    => __('Consulté', 'remise'),
            self::STATUS_SIGNED    => __('Signé', 'remise'),
            self::STATUS_EXPIRED   => __('Expiré', 'remise'),
            self::STATUS_CANCELLED => __('Annulé', 'remise'),
        ];
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_HANDOVER => __('Remise', 'remise'),
            self::TYPE_RETURN   => __('Restitution', 'remise'),
        ];
    }

    /**
     * Formulations pretes a l'emploi pour le PDF, par type de remise — evite de
     * tenter des accords grammaticaux ("de" vs "d'echange", "remis" vs "echange")
     * par concatenation dans le gabarit Twig.
     */
    /**
     * Titres du PDF volontairement NON traduits via __() : contrairement a une
     * notification (localisee par destinataire, cf. NotificationTargetRemise),
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
    public static function getPdfHeadings(int $type): array
    {
        return match ($type) {
            self::TYPE_RETURN => [
                'page_title'       => 'Fiche de restitution de matériel',
                'material_heading' => 'Matériel restitué',
            ],
            default => [
                'page_title'       => 'Fiche de remise de matériel',
                'material_heading' => 'Matériel remis',
            ],
        };
    }

    // ================================================================================
    // Detection de l'affectation (appele depuis hook.php)
    // ================================================================================

    public static function handleItemAssignment(CommonDBTM $item): void
    {
        $config = Config::getForEntity((int) ($item->fields['entities_id'] ?? 0));

        if (!in_array($item->getType(), $config->getManagedItemtypes(), true)) {
            return;
        }

        // Le declenchement par affectation (users_id) est prioritaire : s'il a
        // agi, on n'evalue pas aussi le declenchement par Etat pour la meme
        // requete (evite de creer deux remises si un technicien change les
        // deux champs en une seule fois).
        if (self::handleUserBasedTrigger($item, $config)) {
            return;
        }

        self::handleStateBasedTrigger($item, $config);
    }

    /** @return bool true si une remise a ete declenchee par ce mecanisme */
    private static function handleUserBasedTrigger(CommonDBTM $item, Config $config): bool
    {
        // Rien n'a change sur le champ users_id (ex: mise a jour d'un autre champ) ;
        // pour un nouvel element (item_add), il n'y a pas d'oldvalues du tout, on
        // considere alors l'ancien detenteur comme "aucun" (0).
        if (!array_key_exists('users_id', $item->oldvalues ?? []) && $item->isNewItem() === false) {
            return false;
        }

        $old_user = (int) ($item->oldvalues['users_id'] ?? 0);
        $new_user = (int) ($item->fields['users_id'] ?? 0);

        if ($old_user === $new_user) {
            return false;
        }

        if ($old_user === 0 && $new_user !== 0) {
            if ($config->fields['sign_on_assignment']) {
                self::createRemise($item, self::TYPE_HANDOVER, $new_user);
                return true;
            }
            return false;
        }

        if ($old_user !== 0 && $new_user === 0) {
            if ($config->fields['sign_on_return']) {
                self::createRemise($item, self::TYPE_RETURN, $old_user);
                return true;
            }
            return false;
        }

        if ($old_user !== 0 && $new_user !== 0) {
            // Un transfert direct entre deux personnes (l'ancien detenteur n'est
            // jamais passe par 0) est traite comme une remise normale au nouveau
            // detenteur : pas de type "Echange" distinct (retire, cf. TYPE_EXCHANGE).
            if ($config->fields['sign_on_reassignment']) {
                self::createRemise($item, self::TYPE_HANDOVER, $new_user);
                return true;
            }
        }

        return false;
    }

    /**
     * Declenchement alternatif base sur le champ "Etat" (states_id) du materiel,
     * configurable dans Remise & signature > Configuration : utile pour les
     * organisations qui pilotent le cycle de vie du materiel via son Etat
     * (ex: "En prêt" / "Disponible") plutot que via l'affectation d'utilisateur
     * elle-meme — les listes d'Etats variant fortement d'un GLPI a l'autre,
     * rien n'est presuppose ici : l'administrateur choisit ses propres Etats.
     */
    private static function handleStateBasedTrigger(CommonDBTM $item, Config $config): void
    {
        if (!array_key_exists('states_id', $item->oldvalues ?? [])) {
            return;
        }

        $newState = (int) ($item->fields['states_id'] ?? 0);
        $oldState = (int) ($item->oldvalues['states_id'] ?? 0);
        if ($newState === $oldState) {
            return;
        }

        $currentUser = (int) ($item->fields['users_id'] ?? 0);
        if ($currentUser === 0) {
            return; // personne a notifier
        }

        if (in_array($newState, $config->getHandoverStates(), true)) {
            self::createRemise($item, self::TYPE_HANDOVER, $currentUser);
            return;
        }

        if (in_array($newState, $config->getReturnStates(), true)) {
            self::createRemise($item, self::TYPE_RETURN, $currentUser);
        }
    }

    public static function archiveForPurgedItem(CommonDBTM $item): void
    {
        global $DB;

        // Le materiel est purge : on conserve l'historique des remises (preuve),
        // on ne les supprime jamais en cascade.
        $DB->update(self::getTable(), ['is_deleted' => 1], [
            'itemtype' => $item->getType(),
            'items_id' => $item->getID(),
        ]);
    }

    private static function createRemise(CommonDBTM $item, int $type, int $users_id): void
    {
        $config = Config::getForEntity((int) $item->fields['entities_id']);
        $template = Template::getDefaultFor($type, (int) $item->fields['entities_id']);

        self::cancelPendingRemisesFor($item);

        $remise = new self();
        $id = $remise->add([
            'entities_id'                 => $item->fields['entities_id'],
            'itemtype'                    => $item->getType(),
            'items_id'                    => $item->getID(),
            'users_id'                    => $users_id,
            'users_id_tech'               => Session::getLoginUserID() ?: 0,
            'locations_id'                => $item->fields['locations_id'] ?? 0,
            'plugin_remise_templates_id'  => $template ? $template->getID() : 0,
            'type'                        => $type,
            'status'                      => self::STATUS_PENDING,
            'comment'                     => '',
        ]);

        if (!$id) {
            trigger_error('Plugin remise : echec de creation de la remise pour ' . $item->getType() . ' #' . $item->getID(), E_USER_WARNING);
            return;
        }

        $remise->getFromDB($id);
        $remise->launchWorkflow($config);
    }

    /**
     * Annule toute remise encore en attente de signature pour ce materiel (statuts
     * DRAFT/PENDING/SENT/VIEWED) et invalide son jeton de signature.
     *
     * Sans cela, un materiel reaffecte une deuxieme fois avant que le premier
     * beneficiaire ait signe laisserait son lien de signature actif : il pourrait
     * signer un document de remise pour un materiel qu'il ne detient plus, pendant
     * qu'une deuxieme remise part en parallele pour le nouveau detenteur.
     */
    private static function cancelPendingRemisesFor(CommonDBTM $item): void
    {
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
            Token::invalidateForRemise((int) $row['id']);
        }
    }

    /**
     * Genere le PDF, cree le jeton de signature, envoie la notification initiale.
     */
    public function launchWorkflow(?Config $config = null): void
    {
        $config ??= Config::getForEntity((int) $this->fields['entities_id']);

        $builder = new Pdf\HandoverPdfBuilder();
        $document = $builder->build($this);

        $this->update([
            'id'                   => $this->getID(),
            'document_id_unsigned' => $document->getID(),
        ]);

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
    public function getSignUrl(?string $rawToken = null): string
    {
        $rawToken ??= $this->_current_raw_token ?? null;
        if ($rawToken === null) {
            return '';
        }
        $base = rtrim($GLOBALS['CFG_GLPI']['url_base'] ?? '', '/');
        return $base . '/plugins/remise/front/sign.php?t=' . urlencode($rawToken);
    }

    public function getExpiryDate(): ?string
    {
        return Token::getExpiryForRemise($this->getID());
    }

    public function getBeneficiary(): array
    {
        $user = new \User();
        $user->getFromDB((int) $this->fields['users_id']);
        // glpi_users ne porte pas de colonne email (elle vit dans glpi_useremails) :
        // on la fusionne ici pour que les gabarits (PDF, notifications) l'utilisent simplement.
        $fields = $user->fields;
        $fields['email'] = \UserEmail::getDefaultForUser((int) $this->fields['users_id']) ?: '';
        return $fields;
    }

    public function getTargetItem(): array
    {
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
     * personnalises qui l'activent.
     */
    private static function resolveManufacturerName(CommonDBTM $item): string
    {
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
     */
    private static function resolveModelName(CommonDBTM $item): string
    {
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
    public function getDocumentTitle(): string
    {
        $user = $this->getBeneficiary();
        $userName = trim(\formatUserName(0, $user['name'] ?? '', $user['realname'] ?? '', $user['firstname'] ?? ''));

        $item = $this->getTargetItem();
        $itemName = $item['name'] ?? '';

        $type = self::getCanonicalTypeLabel((int) $this->fields['type']);

        // Date en tete : plusieurs remises pour le meme couple (personne, materiel)
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
     * apparaissant tantot "(Remise) [signée]", tantot "(Handover) [signed]"
     * selon la langue du compte de la personne agissante — deux documents
     * de la MEME remise pouvant meme finir dans deux langues differentes.
     * Le nom de fichier est un identifiant interne de classement, pas un
     * contenu utilisateur : il n'a pas besoin d'etre traduit.
     */
    private static function getCanonicalTypeLabel(int $type): string
    {
        return match ($type) {
            self::TYPE_RETURN => 'Restitution',
            default           => 'Remise',
        };
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
    public static function getCanonicalItemtypeLabel(string $itemtype): string
    {
        return match ($itemtype) {
            'Computer'   => 'Ordinateur',
            'Monitor'    => 'Écran',
            'Peripheral' => 'Périphérique',
            'Phone'      => 'Téléphone',
            default      => $itemtype::getTypeName(1),
        };
    }

    public function getAccessories(): array
    {
        global $DB;
        $rows = $DB->request([
            'SELECT' => [
                'glpi_plugin_remise_accessories.id AS accessories_id',
                'glpi_plugin_remise_accessories.name',
                'glpi_plugin_remise_remiseaccessories.quantity',
                'glpi_plugin_remise_remiseaccessories.comment',
            ],
            'FROM'   => 'glpi_plugin_remise_remiseaccessories',
            'INNER JOIN' => [
                'glpi_plugin_remise_accessories' => [
                    'FKEY' => [
                        'glpi_plugin_remise_remiseaccessories' => 'plugin_remise_accessories_id',
                        'glpi_plugin_remise_accessories'       => 'id',
                    ],
                ],
            ],
            'WHERE' => ['glpi_plugin_remise_remiseaccessories.plugin_remise_remises_id' => $this->getID()],
            'ORDER' => 'glpi_plugin_remise_accessories.name',
        ]);
        return iterator_to_array($rows);
    }

    /**
     * Types de statuts pour lesquels le document PDF non signe peut encore
     * etre modifie (accessoires, regeneration...) : tout ce qui precede une
     * vraie signature. Une fois SIGNED/EXPIRED/CANCELLED, le PDF ne doit plus
     * bouger (preuve figee).
     */
    private function isStillEditable(): bool
    {
        return in_array((int) $this->fields['status'], self::STATUSES_STILL_EDITABLE, true);
    }

    /**
     * Ajoute (ou met a jour la quantite d') un accessoire a cette remise, puis
     * regenere immediatement le PDF non signe pour qu'il reflete la liste a
     * jour — sans quoi le beneficiaire verrait un document qui ne mentionne
     * jamais les accessoires ajoutes apres la creation automatique de la remise.
     * Sans effet si la remise est deja signee/expiree/annulee.
     */
    public function addAccessory(int $accessories_id, int $quantity, string $comment = ''): void
    {
        if (!$this->isStillEditable()) {
            return;
        }
        RemiseAccessory::attach($this->getID(), $accessories_id, max(1, $quantity), $comment);
        $this->regenerateUnsignedPdf();
    }

    /** Retire un accessoire de cette remise et regenere le PDF non signe. */
    public function removeAccessory(int $accessories_id): void
    {
        if (!$this->isStillEditable()) {
            return;
        }
        RemiseAccessory::detach($this->getID(), $accessories_id);
        $this->regenerateUnsignedPdf();
    }

    /**
     * Reconstruit le PDF non signe (nouveau Document, l'ancien est purge) a
     * partir de l'etat courant de la remise. Utilise apres toute modification
     * des accessoires ; n'a aucun effet sur une remise deja signee.
     */
    private function regenerateUnsignedPdf(): void
    {
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

    public function getTemplate(): ?Template
    {
        if (!$this->fields['plugin_remise_templates_id']) {
            return null;
        }
        $template = new Template();
        return $template->getFromDB($this->fields['plugin_remise_templates_id']) ? $template : null;
    }

    // ================================================================================
    // Transitions de statut
    // ================================================================================

    public function markViewed(): void
    {
        if ((int) $this->fields['status'] === self::STATUS_SENT) {
            $this->update([
                'id'         => $this->getID(),
                'status'     => self::STATUS_VIEWED,
                'date_viewed'=> date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function markSigned(string $signedPdfPath, array $proof): void
    {
        // "[signée]" volontairement non traduit : cf. le commentaire de
        // getCanonicalTypeLabel() — traduire ici ferait dependre la langue du
        // nom de fichier de la session du beneficiaire qui signe, incoherent
        // avec le suffixe "[non signée]" du PDF non signe (deja fixe en dur).
        $document = $this->attachDocument($signedPdfPath, $this->getDocumentTitle() . ' [signée]');

        Signature::recordProof($this, $proof);

        $this->update([
            'id'                 => $this->getID(),
            'status'             => self::STATUS_SIGNED,
            'document_id_signed' => $document->getID(),
            'date_signed'        => date('Y-m-d H:i:s'),
        ]);

        Token::invalidateForRemise($this->getID());
        NotificationEvent::raiseEvent('signed', $this);
    }

    private function attachDocument(string $filePath, string $name): Document
    {
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

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'remiseReminders' => ['description' => __('Envoie les relances de signature dues', 'remise')],
            'remiseExpire'    => ['description' => __('Marque comme expirées les remises hors délai', 'remise')],
            default           => [],
        };
    }

    /**
     * Envoie une relance immediatement pour CETTE remise, sans attendre le delai
     * planifie — utilise aussi bien par le bouton "Relancer maintenant" (action
     * manuelle d'un technicien) que par runReminders() (execution automatique).
     *
     * @throws \RuntimeException si le statut ne permet pas de relance (deja signee, expiree...)
     */
    public function sendReminderNow(): void
    {
        if (!in_array((int) $this->fields['status'], self::STATUSES_AWAITING_SIGNATURE, true)) {
            throw new \RuntimeException('Cette remise ne peut plus être relancée (déjà signée ou expirée).');
        }

        $config = Config::getForEntity((int) $this->fields['entities_id']);
        $provider = Provider\ProviderFactory::for($config);

        // Nouvelle demande a chaque relance : le jeton precedent est invalide dans le meme mouvement.
        $provider->createRequest($this, '');
        NotificationEvent::raiseEvent('reminder', $this);

        $newCount = (int) $this->fields['reminder_count'] + 1;
        Reminder::log($this, $newCount);
        $this->update(['id' => $this->getID(), 'reminder_count' => $newCount]);
    }

    /**
     * Logique de relance automatique, independante de tout mecanisme de
     * planification — appelee par le CronTask GLPI (cronRemiseReminders) et par
     * la commande console plugins:remise:run-reminders (cf. README, section
     * "Alternative au cron GLPI").
     */
    public static function runReminders(): int
    {
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
            $remise = new self();
            $remise->fields = $row;

            // Config resolue pour l'entite DE CETTE REMISE (pas un reglage global) :
            // deux entites peuvent avoir des delais de relance differents, cf.
            // README section "Heritage de configuration par entite". Mise en cache
            // le temps de cette boucle : plusieurs remises partagent souvent la
            // meme entite, inutile de refaire la meme resolution pour chacune.
            $entityId = (int) $row['entities_id'];
            $remiseConfig = $configByEntity[$entityId] ??= Config::getForEntity($entityId);

            $provider = Provider\ProviderFactory::for($remiseConfig);
            if ($provider->managesReminders()) {
                continue; // le prestataire (SaaS) gere ses propres relances
            }

            $max = (int) $remiseConfig->fields['max_reminders'];
            if ($max > 0 && (int) $remise->fields['reminder_count'] >= $max) {
                continue;
            }

            $delays = $remiseConfig->getReminderDelays();
            $daysSinceSend = self::daysSince($remise->fields['date_sent']);
            $reminderIndex = (int) $remise->fields['reminder_count'];
            $delayIndex = min($reminderIndex, count($delays) - 1);
            $dueDay = array_sum(array_slice($delays, 0, $reminderIndex + 1, true)) ?: $delays[$delayIndex];

            if ($daysSinceSend >= $dueDay) {
                $remise->sendReminderNow();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Logique d'expiration automatique, independante de tout mecanisme de
     * planification — appelee par le CronTask GLPI (cronRemiseExpire) et par
     * la commande console plugins:remise:run-expiration.
     */
    public static function runExpiration(): int
    {
        global $DB;

        // Le filtre de delai ne peut plus se faire dans le SQL (comme avant) :
        // chaque remise doit etre comparee a la duree de validite resolue pour
        // SA PROPRE entite (Config::getForEntity()), pas une valeur globale
        // unique — cf. README section "Heritage de configuration par entite".
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
            $remise = new self();
            $remise->fields = $row;

            $entityId = (int) $row['entities_id'];
            $remiseConfig = $configByEntity[$entityId] ??= Config::getForEntity($entityId);
            $validity = (int) $remiseConfig->fields['link_validity_days'];

            $daysSinceSend = self::daysSince($remise->fields['date_sent']);
            if ($daysSinceSend <= $validity) {
                continue;
            }

            $remise->update([
                'id'          => $remise->getID(),
                'status'      => self::STATUS_EXPIRED,
                'date_expired'=> date('Y-m-d H:i:s'),
            ]);
            Token::invalidateForRemise($remise->getID());
            NotificationEvent::raiseEvent('expired', $remise);
            $count++;
        }

        return $count;
    }

    /** Nombre de jours pleins ecoules depuis une date (format GLPI 'Y-m-d H:i:s'). */
    private static function daysSince(string $dateTime): int
    {
        return (int) floor((time() - strtotime($dateTime)) / DAY_TIMESTAMP);
    }

    public static function cronRemiseReminders(CronTask $task): int
    {
        $count = self::runReminders();
        $task->addVolume($count);
        return $count > 0 ? 1 : 0;
    }

    public static function cronRemiseExpire(CronTask $task): int
    {
        $count = self::runExpiration();
        $task->addVolume($count);
        return $count > 0 ? 1 : 0;
    }

    // ================================================================================
    // Installation
    // ================================================================================

    public static function install(Migration $migration): void
    {
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
                `locations_id` int unsigned NOT NULL DEFAULT 0,
                `plugin_remise_templates_id` int unsigned NOT NULL DEFAULT 0,
                `type` tinyint NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 0,
                `document_id_unsigned` int unsigned NOT NULL DEFAULT 0,
                `document_id_signed` int unsigned NOT NULL DEFAULT 0,
                `reminder_count` int unsigned NOT NULL DEFAULT 0,
                `date_sent` timestamp NULL DEFAULT NULL,
                `date_viewed` timestamp NULL DEFAULT NULL,
                `date_signed` timestamp NULL DEFAULT NULL,
                `date_expired` timestamp NULL DEFAULT NULL,
                `comment` text,
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
                KEY `plugin_remise_templates_id` (`plugin_remise_templates_id`),
                KEY `is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $DB->doQuery($query);
        }
    }
}
