<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use CommonGLPI;
use Migration;
use State;

/**
 * Configuration du plugin, une ligne par entite qui souhaite surcharger
 * les reglages par defaut (herites sinon de l'entite racine, id=0).
 */
class Config extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_CONFIG;

   private const DEFAULTS = [
        'id'                                  => 0,
        'entities_id'                         => 0,
        'is_recursive'                        => 1,
        'sender_name'                         => 'GLPI - Gestion du parc',
        'sender_email'                        => '',
        'logo_documents_id'                   => 0,
        'logo_force_children'                 => 0,
        'company_name'                        => '',
        'protect_pdf'                         => 0,
        'enable_passport'                     => 1,
        'passport_retention_years'            => 3,
        'passport_visible_types'              => '[0,1,2,3,4]',
        'show_infocom_dates'                  => 1,
        'show_linked_tickets'                 => 1,
        'charter_url'                         => '',
        'default_provider'                    => 'canvas',
        'provider_config'                     => '',
        'reminder_delays'                     => '3,7,7',
        'max_reminders'                       => 0,
        'link_validity_days'                  => 30,
        'expiry_warning_days'                  => 3,
        'signature_required'                  => 1,
        'enable_observations'                 => 0,
        'enable_don'                          => 0,
        'enable_vente'                        => 0,
        'enable_damage_annotation'            => 0,
        'enable_maintenance_signature'        => 0,
        'show_qr_code'                        => 0,
        'currency_symbol'                     => '€',
        'preview_watermark_text'              => 'APERÇU',
        'preview_watermark_opacity'           => 25,
        'sign_on_assignment'                  => 1,
        'sign_on_reassignment'                => 1,
        'sign_on_return'                      => 0,
        'managed_itemtypes'                   => '["Computer","Monitor","Peripheral","Phone"]',
        'handover_states'                     => '[]',
        'return_states'                       => '[]',
        'donation_states'                     => '[]',
        'vente_states'                        => '[]',
    ];

   public static function getTypeName($nb = 0): string {
       return __('Configuration remise & signature', 'remise');
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if ($item->getType() === 'Entity') {
          return __('Remise & signature', 'remise');
      }
       return '';
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if ($item->getType() !== 'Entity') {
          return false;
      }
       self::showConfigForm((int) $item->getID());
       return true;
   }

    /**
     * Rendu du formulaire de configuration, partage par l'onglet Entity et
     * la page dediee du menu d'administration (front/config.php).
     */
   public static function showConfigForm(int $entities_id): void {
       $config = self::getForEntity($entities_id);

       $effectiveLogoId = self::getEffectiveLogoDocumentId($entities_id, (int) $config->fields['logo_documents_id']);
       $logoIsForced = $effectiveLogoId > 0 && $effectiveLogoId !== (int) $config->fields['logo_documents_id'];

       $logoDocument = null;
      if ($effectiveLogoId > 0) {
          $doc = new \Document();
         if ($doc->getFromDB($effectiveLogoId)) {
            $logoDocument = $doc;
         }
      }

       $previewHtml = (new Pdf\HandoverPdfBuilder())->renderPreview($entities_id);

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@remise/config_form.html.twig', [
           'config'          => $config->fields,
           'entity_id'       => $entities_id,
           'csrf_token'      => \Session::getNewCSRFToken(),
           // Jeton CSRF DEDIE au canal d'apercu en direct (live-preview.js),
           // totalement independant de celui du formulaire lui-meme
           // (ci-dessus, lu par le vrai bouton Enregistrer). Les deux sont a
           // usage unique : les faire cohabiter dans le MEME champ DOM fait
           // echouer l'un des deux en "Accès refusé" des qu'un appel
           // d'apercu en vol (case cochee, logo choisi...) consomme le jeton
           // juste avant que l'utilisateur ne clique Enregistrer — bug reel
           // persistant (constate encore apres une premiere tentative de
           // correctif qui se contentait de serialiser les appels d'apercu
           // ENTRE EUX sans les decoupler du vrai formulaire, cf.
           // TROUBLESHOOTING.md). Un jeton separe, jamais lu ni ecrit dans le
           // DOM du formulaire, elimine la course a la racine.
           //
           // IMPORTANT : Session::getNewCSRFToken() SANS argument reutilise en
           // realite le meme jeton deja genere dans la requete en cours
           // (variable globale $CURRENTCSRFTOKEN, cf. src/Session.php du
           // coeur GLPI) — un premier appel a getNewCSRFToken() (ci-dessus,
           // pour csrf_token) l'initialise, et un second appel SANS le
           // parametre $standalone=true renvoie EXACTEMENT LE MEME jeton, pas
           // un jeton independant. Le premier essai de decouplage ci-dessus
           // etait donc inoperant en pratique (les deux jetons etaient
           // silencieusement identiques) — bug confirme encore present par
           // l'utilisateur apres ce premier essai. `true` genere un jeton
           // reellement standalone (non partage avec $CURRENTCSRFTOKEN).
           'preview_csrf_token' => \Session::getNewCSRFToken(true),
           'preview_html'    => $previewHtml,
           // Seul "canvas" est reellement implemente (cf. Provider\ProviderFactory) : Yousign et
           // DocuSeal ont ete retires du choix pour ne pas laisser un admin selectionner un
           // fournisseur qui ferait planter la creation de toute remise (RuntimeException non
           // rattrapee auparavant, cf. plugin_remise_item_assignment()).
           'providers'       => ['canvas' => __('Signature intégrée (canvas)', 'remise')],
           'itemtype_labels' => self::getItemtypeLabels(),
           'managed'         => $config->getManagedItemtypes(),
           'all_states'      => self::getAllStates(),
           'handover_states' => $config->getHandoverStates(),
           'return_states'   => $config->getReturnStates(),
           'donation_states' => $config->getDonationStates(),
           'vente_states'    => $config->getVenteStates(),
           'logo_document'   => $logoDocument,
           'logo_is_forced'  => $logoIsForced,
           'installed_version'     => PLUGIN_REMISE_VERSION,
           'latest_github_version' => self::getLatestGithubVersion(),
           // Gabarit par defaut de chaque type, edite directement dans l'onglet
           // correspondant plutot que sur l'ecran separe front/template.form.php
           // (cf. ROADMAP.md, "Rapprocher la gestion des gabarits de
           // l'experience du plugin") — la sauvegarde elle-meme reste
           // entierement geree par Template::showForm()/CommonDBTM, ce bloc ne
           // fait que rapatrier l'affichage.
           'templates' => [
               Remise::TYPE_HANDOVER => Template::getDefaultFor(Remise::TYPE_HANDOVER, $entities_id),
               Remise::TYPE_RETURN   => Template::getDefaultFor(Remise::TYPE_RETURN, $entities_id),
               Remise::TYPE_DON      => Template::getDefaultFor(Remise::TYPE_DON, $entities_id),
               Remise::TYPE_VENTE    => Template::getDefaultFor(Remise::TYPE_VENTE, $entities_id),
           ],
           'passport_type_labels'   => PassportEvent::getTypeLabels(),
           'passport_visible_types' => $config->getPassportVisibleTypes(),
       ]);
   }

    /**
     * Formats d'image acceptes pour le logo, uploade directement depuis le
     * poste de l'admin (input type="file" classique, pas le composant de
     * televersement JS de GLPI) : traite ici comme n'importe quel fichier
     * genere par du code (meme convention que HandoverPdfBuilder::
     * storeAsDocument()) — deplace dans GLPI_TMP_DIR puis attache via
     * Document::add()/_filename, sans dependre du widget de televersement natif.
     */
   private const LOGO_ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * @return int L'ID du Document cree, ou 0 si l'upload est absent/invalide
     *             (message d'erreur ajoute a la session dans ce dernier cas).
     */
   public static function uploadLogo(array $file, int $entities_id): int {
      if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
          return 0; // aucun fichier selectionne : rien a faire, pas une erreur
      }

      if (($file['error'] ?? null) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du logo.', 'remise'), false, ERROR);
          return 0;
      }

       $extension = strtolower((string) pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
      if (!in_array($extension, self::LOGO_ALLOWED_EXTENSIONS, true) || @getimagesize($file['tmp_name']) === false) {
          \Session::addMessageAfterRedirect(__('Le logo doit être une image (PNG, JPG, GIF ou WEBP).', 'remise'), false, ERROR);
          return 0;
      }

       $tmpName = uniqid('remise_logo_', true) . '.' . $extension;
       // copy() (pas move_uploaded_file()) : GLPI 11 reconstruit en interne un
       // objet Symfony Request depuis $_FILES a certains points de la requete
       // (Session::isCron(), appele entre autres par Session::addMessageAfterRedirect()
       // plus loin dans Document::add()) — cette reconstruction tardive tente de
       // revalider le fichier a son chemin ORIGINAL ($file['tmp_name']). Le
       // deplacer avec move_uploaded_file() le fait disparaitre de ce chemin
       // avant cette revalidation, provoquant une exception fatale non rattrapee
       // ("The file ... does not exist") — bug reel signale par l'utilisateur
       // (page d'erreur generique sans trace, 500 a l'enregistrement du logo).
       // copy() laisse le fichier original en place (purge normalement par PHP
       // en fin de requete) : is_uploaded_file() a deja valide juste au-dessus
       // qu'il s'agit bien d'un upload legitime, copy() ne recree donc pas le
       // risque que cette verification existe pour ecarter.
      if (!copy($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
          \Session::addMessageAfterRedirect(__('Échec de l\'envoi du logo.', 'remise'), false, ERROR);
          return 0;
      }

       $document = new \Document();
       $documents_id = $document->add([
           'name'          => 'Logo remise & signature',
           'entities_id'   => $entities_id,
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);

       return $documents_id ?: 0;
   }

    /**
     * Envoie un e-mail de test a l'utilisateur courant (celui qui clique sur
     * le bouton), independamment du moteur de notifications GLPI (qui exige
     * une vraie remise pour resoudre ses tags ##remise.xxx##) : verifie
     * seulement que le serveur SMTP configure dans GLPI accepte l'envoi, avec
     * l'expediteur (sender_name/sender_email) de CETTE entite.
     * @return string Message d'erreur si l'envoi echoue, chaine vide si ok.
     */
   public static function sendTestEmail(int $entities_id): string {
       $userId = \Session::getLoginUserID();
       $email = \UserEmail::getDefaultForUser((int) $userId);
      if (!$email) {
          return __('Votre compte GLPI n\'a pas d\'adresse e-mail enregistrée.', 'remise');
      }

       $config = self::getForEntity($entities_id);
       $senderName = trim($config->fields['sender_name'] ?? '') ?: 'GLPI';
       $senderEmail = trim($config->fields['sender_email'] ?? '');

      try {
          $mailer = new \GLPIMailer();
          $mail = $mailer->getEmail();
         if ($senderEmail !== '') {
             $mail->from(new \Symfony\Component\Mime\Address($senderEmail, $senderName));
         }
          $mail->to($email);
          $mail->subject(__('E-mail de test — Remise & signature', 'remise'));
          $mail->html('<p>' . __('Cet e-mail confirme que la configuration d\'envoi du plugin Remise & signature fonctionne.', 'remise') . '</p>');

         if (!$mailer->send()) {
             return __('Le serveur de messagerie a refusé l\'envoi.', 'remise');
         }
      } catch (\Throwable $e) {
          return $e->getMessage();
      }

       return '';
   }

    /**
     * Derniere version publiee sur GitHub (release la plus recente, hors
     * pre-release), pour affichage a cote de la version installee en
     * configuration — permet de detecter immediatement qu'un environnement
     * tourne sur une version anterieure a celle publiee, sans avoir a
     * comparer des fichiers un par un (piege deja rencontre, cf.
     * TROUBLESHOOTING.md : deux environnements affichant le meme numero mais
     * des commits en realite differents). Mise en cache 24h (meme duree et
     * meme mecanisme que RSSFeed::getRSSFeed() du cœur GLPI, `$GLPI_CACHE`) :
     * l'API GitHub non authentifiee est limitee a 60 requetes/heure par IP,
     * largement insuffisant si appelee a chaque affichage de la page.
     * Toolbox::getURLContent() (pas un appel HTTP direct) : reutilise la
     * gestion de proxy/timeout/erreurs deja etablie par le cœur GLPI pour ce
     * type d'appel (meme fonction que Toolbox::checkNewVersionAvailable(),
     * qui fait exactement ceci pour GLPI lui-meme).
     * @return string|null Numero de version (sans le "v" du tag), ou null si
     *         l'appel a echoue (pas de connexion, API GitHub indisponible...).
     */
   public static function getLatestGithubVersion(): ?string {
       global $GLPI_CACHE;

       $cacheKey = 'plugin_remise_latest_github_version';
       $cached = $GLPI_CACHE->get($cacheKey);
      if ($cached !== null) {
          return $cached === '' ? null : $cached;
      }

       $error = '';
       $json = \Toolbox::getURLContent('https://api.github.com/repos/parime/remise-glpi/releases/latest', $error);
       $version = null;
      if (!empty($json)) {
          $data = json_decode($json, true);
         if (is_array($data) && !empty($data['tag_name']) && is_string($data['tag_name'])) {
             $version = ltrim($data['tag_name'], 'v');
         }
      }

       $GLPI_CACHE->set($cacheKey, $version ?? '', DAY_TIMESTAMP);
       return $version;
   }

   public static function getItemtypeLabels(): array {
       $labels = [];
      foreach (self::getAllManageableItemtypes() as $itemtype) {
          $labels[$itemtype] = $itemtype::getTypeName(2);
      }
       return $labels;
   }

    /**
     * Types geres "en dur" (Computer, Monitor...) + tous les actifs personnalises
     * actifs (Configuration > Actifs personnalisés / Glpi\Asset\AssetDefinitionManager),
     * enumeres dynamiquement a chaque appel : un actif personnalise cree ou active
     * apres coup est donc pris en compte sans modification ni reinstallation du
     * plugin. Chaque actif personnalise possede toujours users_id et states_id
     * (Glpi\Asset\Asset implemente AssignableItemInterface et StateInterface), la
     * logique de declenchement de Remise::handleItemAssignment() fonctionne donc
     * sans adaptation.
     */
   public static function getAllManageableItemtypes(): array {
       global $DB;

       $itemtypes = PLUGIN_REMISE_DEFAULT_ITEMTYPES;

       // Volontairement une requete SQL directe plutot que
       // Glpi\Asset\AssetDefinitionManager::getInstance()->getDefinitions() : cette
       // methode est utilisee depuis plugin_init_remise(), qui s'execute (listener
       // "InitializePlugins", priorite 110) AVANT que GLPI ne charge les definitions
       // d'actifs personnalises en memoire (listener "CustomObjectsBoot", priorite
       // 100 - donc plus tard). A ce stade du cycle de vie de la requete,
       // AssetDefinitionManager::getDefinitions() renvoie toujours un tableau vide,
       // meme si des actifs personnalises actifs existent en base. La table SQL,
       // elle, est deja disponible bien plus tot (connexion DB = priorite 190).
      if ($DB->tableExists('glpi_assets_assetdefinitions')) {
         foreach ($DB->request(['FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['is_active' => 1]]) as $row) {
            // Convention de nommage GLPI : voir Glpi\CustomObject\AbstractDefinition::
            // getCustomObjectClassName() et Glpi\Asset\AssetDefinition::getCustomObjectNamespace().
            $itemtypes[] = 'Glpi\\CustomAsset\\' . $row['system_name'] . 'Asset';
         }
      }

       return $itemtypes;
   }

    /**
     * Liste (id => nom) de tous les Etats GLPI (glpi_states), pour peupler les
     * menus deroulants de declenchement par statut. Volontairement toutes
     * entites confondues : un Etat est une liste deroulante globale dans GLPI.
     */
   public static function getAllStates(): array {
       global $DB;

       $states = [];
      foreach ($DB->request(['FROM' => State::getTable(), 'ORDER' => 'name']) as $row) {
          $states[(int) $row['id']] = $row['name'];
      }
       return $states;
   }

    /**
     * Renvoie la configuration effective pour une entite : sa propre ligne si
     * elle existe, sinon celle de l'ancetre le plus proche qui en a une (pas
     * seulement "elle-meme puis directement la racine" : une organisation avec
     * une hierarchie a plusieurs niveaux, ex. Racine > Region > Site, doit voir
     * la config d'une entite intermediaire s'appliquer a ses entites enfants qui
     * n'ont pas leur propre config, meme si la racine n'en a pas non plus).
     * Sinon, valeurs par defaut en memoire (le plugin fonctionne meme sans
     * configuration explicite).
     */
   public static function getForEntity(int $entities_id): self {
       global $DB;

       $config = new self();
      if ($DB->tableExists(self::getTable())) {
          $candidateIds = getAncestorsOf('glpi_entities', $entities_id);
          $candidateIds[$entities_id] = $entities_id;

          $rows = iterator_to_array($DB->request([
              'FROM'  => self::getTable(),
              'WHERE' => ['entities_id' => array_values($candidateIds)],
          ]));

         if ($rows !== []) {
            $config->getFromDB((int) self::pickRowOfClosestEntity($rows)['id']);
            return $config;
         }
      }

       $config->fields = self::DEFAULTS;
       $config->fields['entities_id'] = $entities_id;
       return $config;
   }

    /**
     * Renvoie l'ID du Document a utiliser comme logo pour cette entite, en
     * tenant compte d'un logo impose par une entite ANCETRE (champ
     * `logo_force_children`) — qui l'emporte meme si l'entite elle-meme a deja
     * son propre logo configure. getForEntity() n'hérite le logo (comme le
     * reste de la config) que pour une entite qui n'a AUCUNE ligne propre :
     * une entite qui a deja personnalise autre chose (ex: son propre e-mail
     * d'expediteur) resterait sinon bloquee sur son propre logo — ou l'absence
     * de logo — sans moyen pour une entite parente d'imposer malgre tout le
     * sien a l'ensemble de ses filiales, quel que soit leur niveau de
     * profondeur (`getAncestorsOf()` remonte toute la chaine, pas seulement le
     * parent direct).
     *
     * @param int|null $ownLogoDocumentsId Logo propre de l'entite, si deja connu
     *        de l'appelant (cf. showConfigForm()) : evite de rappeler getForEntity()
     *        pour la meme entite quand aucun ancetre n'impose de logo.
     */
   public static function getEffectiveLogoDocumentId(int $entities_id, ?int $ownLogoDocumentsId = null): int {
       global $DB;

      if ($DB->tableExists(self::getTable())) {
          $ancestorIds = getAncestorsOf('glpi_entities', $entities_id);

         if ($ancestorIds !== []) {
            $rows = iterator_to_array($DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'entities_id'         => array_values($ancestorIds),
                    'logo_force_children' => 1,
                    ['logo_documents_id' => ['>', 0]],
                ],
            ]));

            if ($rows !== []) {
               // Plusieurs ancetres (sur des branches differentes de la
               // hierarchie) peuvent chacun imposer leur propre logo : celui
               // de l'ancetre le plus proche l'emporte, comme pour la
               // resolution habituelle de la config (cf. getForEntity()).
               return (int) self::pickRowOfClosestEntity($rows)['logo_documents_id'];
            }
         }
      }

       return $ownLogoDocumentsId ?? (int) self::getForEntity($entities_id)->fields['logo_documents_id'];
   }

    /**
     * Parmi des lignes candidates portant chacune un champ 'entities_id', renvoie
     * celle de l'entite la plus profonde (le "level" GLPI le plus eleve = la plus
     * proche) — algorithme partage par getForEntity() (heritage ligne entiere) et
     * getEffectiveLogoDocumentId() (imposition d'un champ precis), qui ont toutes
     * deux besoin de departager plusieurs ancetres candidats de la meme facon.
     */
   private static function pickRowOfClosestEntity(array $rows): array {
       global $DB;

      if (count($rows) === 1) {
          return reset($rows);
      }

       $levels = [];
      foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['id' => array_column($rows, 'entities_id')]]) as $erow) {
          $levels[(int) $erow['id']] = (int) $erow['level'];
      }
       usort($rows, static fn($a, $b) => ($levels[(int) $b['entities_id']] ?? 0) <=> ($levels[(int) $a['entities_id']] ?? 0));

       return $rows[0];
   }

    /**
     * Cree ou met a jour la ligne de configuration d'une entite (formulaire d'onglet Entity).
     */
   public static function upsertForEntity(int $entities_id, array $input): void {
       $managedItemtypes = [];
      foreach (self::getAllManageableItemtypes() as $itemtype) {
         if (!empty($input['manage_' . $itemtype])) {
            $managedItemtypes[] = $itemtype;
         }
      }

       $data = [
           'sender_name'          => $input['sender_name'] ?? '',
           'sender_email'         => $input['sender_email'] ?? '',
           'logo_documents_id'    => (int) ($input['logo_documents_id'] ?? 0),
           'logo_force_children'  => (int) ($input['logo_force_children'] ?? 0),
           'charter_url'          => trim($input['charter_url'] ?? ''),
           'default_provider'     => $input['default_provider'] ?? 'canvas',
           'reminder_delays'      => $input['reminder_delays'] ?? '3,7,7',
           'max_reminders'        => (int) ($input['max_reminders'] ?? 0),
           'link_validity_days'   => (int) ($input['link_validity_days'] ?? 30),
           'expiry_warning_days'  => (int) ($input['expiry_warning_days'] ?? 3),
           'company_name'         => trim($input['company_name'] ?? ''),
           'protect_pdf'          => (int) ($input['protect_pdf'] ?? 0),
           'enable_passport'      => (int) ($input['enable_passport'] ?? 0),
           'passport_retention_years' => (int) ($input['passport_retention_years'] ?? 3),
           'passport_visible_types'   => json_encode(array_map('intval', $input['passport_visible_types'] ?? [])),
           'show_infocom_dates'   => (int) ($input['show_infocom_dates'] ?? 0),
           'show_linked_tickets'  => (int) ($input['show_linked_tickets'] ?? 0),
           'signature_required'   => (int) ($input['signature_required'] ?? 0),
           'enable_observations'  => (int) ($input['enable_observations'] ?? 0),
           'enable_don'           => (int) ($input['enable_don'] ?? 0),
           'enable_vente'         => (int) ($input['enable_vente'] ?? 0),
           'enable_damage_annotation' => (int) ($input['enable_damage_annotation'] ?? 0),
           'enable_maintenance_signature' => (int) ($input['enable_maintenance_signature'] ?? 0),
           'show_qr_code'         => (int) ($input['show_qr_code'] ?? 0),
           'currency_symbol'      => trim($input['currency_symbol'] ?? '') ?: '€',
           'preview_watermark_text'    => trim($input['preview_watermark_text'] ?? '') ?: 'APERÇU',
           'preview_watermark_opacity' => max(5, min(100, (int) ($input['preview_watermark_opacity'] ?? 25))),
           'sign_on_assignment'   => (int) ($input['sign_on_assignment'] ?? 0),
           'sign_on_reassignment' => (int) ($input['sign_on_reassignment'] ?? 0),
           'sign_on_return'       => (int) ($input['sign_on_return'] ?? 0),
           'managed_itemtypes'    => json_encode($managedItemtypes),
           'handover_states'      => json_encode(array_map('intval', $input['handover_states'] ?? [])),
           'return_states'        => json_encode(array_map('intval', $input['return_states'] ?? [])),
           'donation_states'      => json_encode(array_map('intval', $input['donation_states'] ?? [])),
           'vente_states'         => json_encode(array_map('intval', $input['vente_states'] ?? [])),
       ];

       $config = new self();
       if ($config->getFromDBByCrit(['entities_id' => $entities_id])) {
           $config->update(['id' => $config->getID()] + $data);
           return;
       }

       $config->add(['entities_id' => $entities_id, 'is_recursive' => 1] + $data);
   }

   public function getManagedItemtypes(): array {
       $decoded = json_decode($this->fields['managed_itemtypes'] ?? '', true);
       return is_array($decoded) && count($decoded) > 0 ? $decoded : PLUGIN_REMISE_DEFAULT_ITEMTYPES;
   }

   public function getReminderDelays(): array {
       return array_map('intval', explode(',', $this->fields['reminder_delays'] ?: '3,7,7'));
   }

    /** États GLPI (glpi_states) qui, une fois atteints, déclenchent une remise. */
   public function getHandoverStates(): array {
       $decoded = json_decode($this->fields['handover_states'] ?? '', true);
       return is_array($decoded) ? array_map('intval', $decoded) : [];
   }

    /** États GLPI (glpi_states) qui, une fois atteints, déclenchent une restitution. */
   public function getReturnStates(): array {
       $decoded = json_decode($this->fields['return_states'] ?? '', true);
       return is_array($decoded) ? array_map('intval', $decoded) : [];
   }

    /** États GLPI (glpi_states) qui, une fois atteints, déclenchent un don. */
   public function getDonationStates(): array {
       $decoded = json_decode($this->fields['donation_states'] ?? '', true);
       return is_array($decoded) ? array_map('intval', $decoded) : [];
   }

    /** États GLPI (glpi_states) qui, une fois atteints, déclenchent une vente. */
   public function getVenteStates(): array {
       $decoded = json_decode($this->fields['vente_states'] ?? '', true);
       return is_array($decoded) ? array_map('intval', $decoded) : [];
   }

    /** Types d'evenement (PassportEvent::TYPE_*) a afficher dans la frise du Passeport materiel. */
   public function getPassportVisibleTypes(): array {
       $decoded = json_decode($this->fields['passport_visible_types'] ?? '', true);
       return is_array($decoded) ? array_map('intval', $decoded) : [0, 1, 2, 3, 4];
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `sender_name` varchar(255) DEFAULT NULL,
                `sender_email` varchar(255) DEFAULT NULL,
                `logo_documents_id` int unsigned NOT NULL DEFAULT 0,
                `logo_force_children` tinyint NOT NULL DEFAULT 0,
                `company_name` varchar(255) NOT NULL DEFAULT '',
                `protect_pdf` tinyint NOT NULL DEFAULT 0,
                `enable_passport` tinyint NOT NULL DEFAULT 1,
                `passport_retention_years` int unsigned NOT NULL DEFAULT 3,
                `passport_visible_types` text,
                `show_infocom_dates` tinyint NOT NULL DEFAULT 1,
                `show_linked_tickets` tinyint NOT NULL DEFAULT 1,
                `charter_url` varchar(255) DEFAULT NULL,
                `default_provider` varchar(32) NOT NULL DEFAULT 'canvas',
                `provider_config` text,
                `reminder_delays` varchar(255) NOT NULL DEFAULT '3,7,7',
                `max_reminders` int unsigned NOT NULL DEFAULT 0,
                `link_validity_days` int unsigned NOT NULL DEFAULT 30,
                `expiry_warning_days` int unsigned NOT NULL DEFAULT 3,
                `signature_required` tinyint NOT NULL DEFAULT 1,
                `enable_observations` tinyint NOT NULL DEFAULT 0,
                `enable_don` tinyint NOT NULL DEFAULT 0,
                `enable_vente` tinyint NOT NULL DEFAULT 0,
                `enable_damage_annotation` tinyint NOT NULL DEFAULT 0,
                `enable_maintenance_signature` tinyint NOT NULL DEFAULT 0,
                `show_qr_code` tinyint NOT NULL DEFAULT 0,
                `currency_symbol` varchar(255) NOT NULL DEFAULT '€',
                `preview_watermark_text` varchar(255) NOT NULL DEFAULT 'APERÇU',
                `preview_watermark_opacity` int unsigned NOT NULL DEFAULT 25,
                `sign_on_assignment` tinyint NOT NULL DEFAULT 1,
                `sign_on_reassignment` tinyint NOT NULL DEFAULT 1,
                `sign_on_return` tinyint NOT NULL DEFAULT 0,
                `managed_itemtypes` text,
                `handover_states` text,
                `return_states` text,
                `donation_states` text,
                `vente_states` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`),
                KEY `is_recursive` (`is_recursive`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

          $DB->insert($table, [
              'entities_id'        => 0,
              'is_recursive'       => 1,
              'sender_name'        => self::DEFAULTS['sender_name'],
              'reminder_delays'    => self::DEFAULTS['reminder_delays'],
              'link_validity_days' => self::DEFAULTS['link_validity_days'],
              'expiry_warning_days' => self::DEFAULTS['expiry_warning_days'],
              'signature_required' => 1,
              'enable_observations' => self::DEFAULTS['enable_observations'],
              'enable_don'         => self::DEFAULTS['enable_don'],
              'enable_vente'       => self::DEFAULTS['enable_vente'],
              'enable_damage_annotation' => self::DEFAULTS['enable_damage_annotation'],
              'enable_maintenance_signature' => self::DEFAULTS['enable_maintenance_signature'],
              'show_qr_code'       => self::DEFAULTS['show_qr_code'],
              'currency_symbol'    => self::DEFAULTS['currency_symbol'],
              'preview_watermark_text'    => self::DEFAULTS['preview_watermark_text'],
              'preview_watermark_opacity' => self::DEFAULTS['preview_watermark_opacity'],
              'sign_on_assignment' => 1,
              'sign_on_reassignment' => 1,
              'managed_itemtypes'  => self::DEFAULTS['managed_itemtypes'],
              'handover_states'    => self::DEFAULTS['handover_states'],
              'return_states'      => self::DEFAULTS['return_states'],
              'donation_states'    => self::DEFAULTS['donation_states'],
              'vente_states'       => self::DEFAULTS['vente_states'],
              'date_creation'      => date('Y-m-d H:i:s'),
          ]);
      } else {
         if (!$DB->fieldExists($table, 'handover_states')) {
             // Montee de version : ajoute les colonnes sans recreer la table.
             $migration->addField($table, 'handover_states', 'text');
             $migration->addField($table, 'return_states', 'text');
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'charter_url')) {
             $migration->addField($table, 'charter_url', 'varchar(255)');
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'logo_force_children')) {
             // 'bool' (pas 'tinyint') : c'est un des rares types "logiques" reconnus
             // par Migration::fieldFormat() pour lesquels 'value' construit reellement
             // une clause DEFAULT — un type SQL brut passe tel quel sans NOT NULL ni
             // DEFAULT, ce qui rendrait la colonne NULL par defaut (constate en
             // conditions reelles : WHERE champ => 0 n'egale jamais NULL en SQL).
             $migration->addField($table, 'logo_force_children', 'bool', ['value' => 0, 'after' => 'logo_documents_id']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'expiry_warning_days')) {
             $migration->addField($table, 'expiry_warning_days', 'integer', ['value' => 3, 'after' => 'link_validity_days']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'enable_observations')) {
             $migration->addField($table, 'enable_observations', 'bool', ['value' => 0, 'after' => 'signature_required']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'enable_don')) {
             $migration->addField($table, 'enable_don', 'bool', ['value' => 0, 'after' => 'enable_observations']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'enable_vente')) {
             $migration->addField($table, 'enable_vente', 'bool', ['value' => 0, 'after' => 'enable_don']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'enable_damage_annotation')) {
             $migration->addField($table, 'enable_damage_annotation', 'bool', ['value' => 0, 'after' => 'enable_vente']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'enable_maintenance_signature')) {
             $migration->addField($table, 'enable_maintenance_signature', 'bool', ['value' => 0, 'after' => 'enable_damage_annotation']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'donation_states')) {
             $migration->addField($table, 'donation_states', 'text', ['after' => 'return_states']);
             $migration->addField($table, 'vente_states', 'text', ['after' => 'donation_states']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'show_qr_code')) {
             $migration->addField($table, 'show_qr_code', 'bool', ['value' => 0, 'after' => 'enable_maintenance_signature']);
             $migration->addField($table, 'currency_symbol', 'string', ['value' => '€', 'after' => 'show_qr_code']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'preview_watermark_text')) {
             $migration->addField($table, 'preview_watermark_text', 'string', ['value' => 'APERÇU', 'after' => 'currency_symbol']);
             $migration->addField($table, 'preview_watermark_opacity', 'integer', ['value' => 25, 'after' => 'preview_watermark_text']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'company_name')) {
             $migration->addField($table, 'company_name', 'string', ['value' => '', 'after' => 'logo_force_children']);
             $migration->addField($table, 'protect_pdf', 'bool', ['value' => 0, 'after' => 'company_name']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'passport_retention_years')) {
             $migration->addField($table, 'passport_retention_years', 'integer', ['value' => 3, 'after' => 'protect_pdf']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'enable_passport')) {
             $migration->addField($table, 'enable_passport', 'bool', ['value' => 1, 'after' => 'protect_pdf']);
             $migration->addField($table, 'passport_visible_types', 'text', ['after' => 'passport_retention_years']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'show_infocom_dates')) {
             $migration->addField($table, 'show_infocom_dates', 'bool', ['value' => 1, 'after' => 'passport_visible_types']);
             $migration->migrationOneTable($table);
         }
         if (!$DB->fieldExists($table, 'show_linked_tickets')) {
             $migration->addField($table, 'show_linked_tickets', 'bool', ['value' => 1, 'after' => 'show_infocom_dates']);
             $migration->migrationOneTable($table);
         }
          // Audit code mort : jamais branchee (aucun formulaire ne la soumet,
          // upsertForEntity() ne l'ecrit jamais) — le mecanisme de gabarit par
          // defaut reellement utilise est Template::getDefaultFor(), base sur
          // la colonne is_default de glpi_plugin_remise_templates.
         if ($DB->fieldExists($table, 'default_plugin_remise_templates_id')) {
             $migration->dropField($table, 'default_plugin_remise_templates_id');
         }
      }
   }
}
