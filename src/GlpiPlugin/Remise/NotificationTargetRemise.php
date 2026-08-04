<?php

namespace GlpiPlugin\Remise;

// Qualifie explicitement (plutot que "Notification\DefaultNotificationContent") :
// le "use Notification;" ci-dessus fait resoudre tout prefixe "Notification\..."
// contre la classe globale \Notification importee, pas contre le sous-namespace
// GlpiPlugin\Remise\Notification — constate en conditions reelles (plugin:install
// --force echouait avec "Attempted to load class DefaultNotificationContent from
// namespace Notification").
use GlpiPlugin\Remise\Notification\DefaultNotificationContent;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Cible et contenu des notifications de remise. Resolue automatiquement par
 * GLPI pour l'itemtype namespace GlpiPlugin\Remise\Remise (convention de nom
 * NotificationTarget::getInstanceClass, aucun hook a enregistrer).
 *
 * @extends NotificationTarget<Remise>
 */
class NotificationTargetRemise extends NotificationTarget
{
    /**
     * Types de cible personnalises pour le beneficiaire et le technicien d'une
     * remise (champs users_id / users_id_tech de glpi_plugin_remise_remises).
     *
     * Volontairement PAS Notification::ITEM_USER / ITEM_TECH_IN_CHARGE : ces
     * types natifs font un INNER JOIN sur Profile_User (cf.
     * NotificationTarget::getProfileJoinCriteria()) qui exige que l'utilisateur
     * possede un profil GLPI actif dans l'entite, sans quoi GLPI l'ignore
     * silencieusement — aucune erreur, aucun log, simplement aucun e-mail
     * envoye. Verifie en conditions reelles : ITEM_TECH_IN_CHARGE ne notifiait
     * JAMAIS le technicien dans cet environnement de test, malgre un profil
     * actif en base, un e-mail configure et users_id_tech correctement rempli.
     * Or la plupart des beneficiaires (employes synchronises depuis l'Active
     * Directory pour la seule gestion de parc) et bien des techniciens n'ont
     * pas ce type de profil. Ces types personnalises notifient directement
     * l'adresse e-mail de l'utilisateur, sans exiger de droits GLPI
     * (cf. addSpecificTargets()).
     */
   public const TARGET_BENEFICIARY = 900001;
   public const TARGET_TECHNICIAN  = 900002;

   public function getEvents(): array {
       return [
           'new'           => __('Nouvelle remise de matériel', 'remise'),
           'reminder'      => __('Relance de signature', 'remise'),
           'signed'        => __('Document signé', 'remise'),
           'expired'       => __('Document expiré', 'remise'),
           'expiring_soon' => __('Document sur le point d\'expirer', 'remise'),
       ];
   }

   public function addAdditionalTargets($event = '') {
       // Le beneficiaire recoit deja des relances periodiques pendant la meme
       // fenetre (evenement "reminder", cf. Remise::runReminders()) : lui envoyer
       // aussi "expiring_soon" (qui s'adresse au technicien, pas a lui) ferait
       // doublon. Tous les autres evenements le concernent directement.
      if ($event !== 'expiring_soon') {
          $this->addTarget(self::TARGET_BENEFICIARY, __('Bénéficiaire', 'remise'));
      }

       // Le technicien qui a declenche la remise (users_id_tech) est notifie sur
       // les evenements qui appellent une action de sa part : une fois signee (pour
       // archivage/suivi), si elle expire sans signature (sans quoi personne cote IT
       // n'est jamais informe qu'un document est reste sans suite — seul le
       // beneficiaire, qui n'a justement pas signe, recevait l'e-mail), et surtout
       // AVANT l'expiration reelle (evenement "expiring_soon", cf. Remise::
       // runExpiryWarnings()) : sans cette alerte anticipee, le technicien n'apprend
       // qu'un document est reste sans suite qu'une fois le lien deja invalide, trop
       // tard pour relancer le beneficiaire autrement (appel, passage sur place).
      if (in_array($event, ['signed', 'expired', 'expiring_soon'], true)) {
          $this->addTarget(self::TARGET_TECHNICIAN, __('Technicien', 'remise'));
      }
   }

    /**
     * Point d'extension appele par NotificationTarget::addForTarget() pour tout
     * type de cible non reconnu nativement — ici, nos TARGET_BENEFICIARY/TECHNICIAN.
     */
   public function addSpecificTargets($data, $options) {
       $target = (int) ($data['items_id'] ?? 0);

      if ($target === self::TARGET_BENEFICIARY) {
          $this->addUserFieldByEmail('users_id');
      } else if ($target === self::TARGET_TECHNICIAN) {
          $this->addUserFieldByEmail('users_id_tech');
      }
   }

    /**
     * Notifie directement l'utilisateur porte par le champ $field de la remise
     * courante (users_id ou users_id_tech), par e-mail, sans passer par le
     * mecanisme de jointure sur profil de GLPI (cf. le commentaire sur
     * TARGET_BENEFICIARY/TARGET_TECHNICIAN plus haut).
     */
   private function addUserFieldByEmail(string $field): void {
       /** @var Remise $remise */
       $remise = $this->obj;

       $usersId = (int) ($remise->fields[$field] ?? 0);
      if ($usersId <= 0) {
          return;
      }

       $user = new \User();
      if (!$user->getFromDB($usersId)) {
          return;
      }

       $email = \UserEmail::getDefaultForUser($user->getID());
      if (empty($email)) {
          return; // cf. gestion d'erreur "utilisateur sans e-mail" (section 6 de la spec)
      }

       $this->addToRecipientsList([
           'language' => $user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB'),
           'name'     => formatUserName(0, $user->fields['name'], $user->fields['realname'], $user->fields['firstname']),
           'email'    => $email,
       ]);
   }

   public function addDataForTemplate($event, $options = []) {
       /** @var Remise $remise */
       $remise = $this->obj;

       $events = $this->getAllEvents();
       $this->data['##remise.action##'] = $events[$event] ?? '';
       $this->data['##remise.id##']     = (string) $remise->getID();
       $this->data['##remise.type##']   = Remise::getTypes()[(int) $remise->fields['type']] ?? '';

       $item = $remise->getTargetItem();
       $this->data['##remise.item.name##'] = $item['name'] ?? '';

       $user = $remise->getBeneficiary();
       $this->data['##remise.user.name##'] = formatUserName(0, $user['name'] ?? '', $user['realname'] ?? '', $user['firstname'] ?? '');

       $this->data['##remise.sign_url##'] = $remise->getSignUrl();
       $this->data['##remise.deadline##'] = $remise->getExpiryDate() ?? '';

       $this->getTags();
      foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
         if (!isset($this->data[$tag])) {
            $this->data[$tag] = $values['label'];
         }
      }
   }

   public function getTags() {
       $tags = [
           'remise.action'    => __('Événement', 'remise'),
           'remise.id'        => __('Identifiant', 'remise'),
           'remise.type'      => __('Type de remise', 'remise'),
           'remise.item.name' => __('Matériel', 'remise'),
           'remise.user.name' => __('Bénéficiaire', 'remise'),
           'remise.sign_url'  => __('Lien de signature', 'remise'),
           'remise.deadline'  => __('Date limite de signature', 'remise'),
       ];

       foreach ($tags as $tag => $label) {
           $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
       }

       asort($this->tag_descriptions);
   }

    /**
     * Langues traduites (interface plugin ET notifications), en plus de la
     * traduction universelle 'fr_FR' toujours semee sous 'language' => ''
     * (cf. NotificationTemplate::getByLanguage() : `WHERE language IN
     * ($language, '') ORDER BY language DESC LIMIT 1`). Sans une ligne dediee
     * pour chacune, un destinataire dont le compte GLPI est dans une de ces
     * langues recevrait quand meme l'e-mail en francais — contrairement a
     * l'interface web du plugin (deja traduite via locales/<lang>.po).
     */
   private const NOTIFICATION_LANGUAGES = ['en_GB', 'es_ES', 'de_DE', 'it_IT'];

    /**
     * Seme les 5 notifications par defaut a l'installation (editables ensuite
     * dans Configuration > Notifications comme n'importe quelle notification
     * native GLPI). Idempotent : ne recree rien si deja present.
     *
     * Chaque gabarit recoit une traduction 'fr_FR' (ligne 'language' => '',
     * universelle) PLUS une ligne par langue de self::NOTIFICATION_LANGUAGES.
     */
   public static function install(): void {
       // Une seule notification par evenement, valable pour tous les types de
       // fiche (Remise, Restitution, et les types a venir) : le mot de type
       // exact est insere via le tag ##remise.type## (deja resolu par
       // addDataForTemplate() ci-dessus), pas besoin d'une notification par
       // type — cf. Notification\DefaultNotificationContent pour le contenu.
      foreach (array_keys((new self())->getEvents()) as $event) {
          $def = DefaultNotificationContent::forEvent($event);
          $existing = new Notification();
          $alreadyInstalled = $existing->getFromDBByCrit(['itemtype' => Remise::class, 'event' => $event]);

         if ($alreadyInstalled) {
            // Montee de version : ajoute uniquement les traductions manquantes
            // sur une installation existante (semee avant l'ajout de chaque
            // langue), sans toucher au reste ni recreer quoi que ce soit.
            foreach (self::NOTIFICATION_LANGUAGES as $language) {
                self::addMissingTranslation(self::getTemplateIdForEvent($event), $language, $def[$language]);
            }

            if (in_array($event, ['signed', 'expired', 'expiring_soon'], true)) {
                self::migrateTechnicianTarget((int) $existing->getID());
            }
            continue;
         }

          $template = new NotificationTemplate();
          $templates_id = $template->add([
              'name'     => $def['name'],
              'itemtype' => Remise::class,
          ]);

          (new NotificationTemplateTranslation())->add([
              'notificationtemplates_id' => $templates_id,
              'language'                 => '',
              'subject'                  => $def['fr_FR']['subject'],
              'content_html'             => $def['fr_FR']['html'],
              'content_text'             => strip_tags(str_replace('</p>', "\n", $def['fr_FR']['html'])),
          ]);

         foreach (self::NOTIFICATION_LANGUAGES as $language) {
             (new NotificationTemplateTranslation())->add([
                 'notificationtemplates_id' => $templates_id,
                 'language'                 => $language,
                 'subject'                  => $def[$language]['subject'],
                 'content_html'             => $def[$language]['html'],
                 'content_text'             => strip_tags(str_replace('</p>', "\n", $def[$language]['html'])),
             ]);
         }

          $notification = new Notification();
          $notifications_id = $notification->add([
              'name'         => $def['name'],
              'entities_id'  => 0,
              'is_recursive' => 1,
              'itemtype'     => Remise::class,
              'event'        => $event,
              'is_active'    => 1,
          ]);

          (new Notification_NotificationTemplate())->add([
              'notifications_id'         => $notifications_id,
              'mode'                     => \Notification_NotificationTemplate::MODE_MAIL, // 'mailing', pas 'mail'
              'notificationtemplates_id' => $templates_id,
          ]);

         if ($event !== 'expiring_soon') {
            (new NotificationTarget())->add([
              'notifications_id' => $notifications_id,
              'type'             => Notification::USER_TYPE,
              'items_id'         => self::TARGET_BENEFICIARY,
            ]);
         }

         if (in_array($event, ['signed', 'expired', 'expiring_soon'], true)) {
            (new NotificationTarget())->add([
                'notifications_id' => $notifications_id,
                'type'             => Notification::USER_TYPE,
                'items_id'         => self::TARGET_TECHNICIAN,
            ]);
         }
      }
   }

    /**
     * Montee de version : remplace, pour une notification 'signed'/'expired'
     * deja installee, l'ancienne cible native Notification::ITEM_TECH_IN_CHARGE
     * (qui ne notifie jamais le technicien sans profil GLPI actif dans
     * l'entite, cf. commentaire sur TARGET_TECHNICIAN) par notre cible
     * personnalisee TARGET_TECHNICIAN. Idempotent.
     */
   private static function migrateTechnicianTarget(int $notifications_id): void {
       global $DB;

       $DB->delete(NotificationTarget::getTable(), [
           'notifications_id' => $notifications_id,
           'items_id'         => Notification::ITEM_TECH_IN_CHARGE,
       ]);

       $target = new NotificationTarget();
      if ($target->getFromDBByCrit(['notifications_id' => $notifications_id, 'items_id' => self::TARGET_TECHNICIAN])) {
          return;
      }

       $target->add([
           'notifications_id' => $notifications_id,
           'type'             => Notification::USER_TYPE,
           'items_id'         => self::TARGET_TECHNICIAN,
       ]);
   }

    /**
     * Retrouve le gabarit associe a l'evenement (via Notification_NotificationTemplate).
     */
   private static function getTemplateIdForEvent(string $event): int {
       global $DB;

       $notification = new Notification();
      if (!$notification->getFromDBByCrit(['itemtype' => Remise::class, 'event' => $event])) {
          return 0;
      }

      foreach ($DB->request([
           'FROM'  => Notification_NotificationTemplate::getTable(),
           'WHERE' => ['notifications_id' => $notification->getID()],
       ]) as $row) {
          return (int) $row['notificationtemplates_id'];
      }

       return 0;
   }

    /**
     * Ajoute la traduction $language pour le gabarit $templates_id si elle
     * n'existe pas deja (montee de version, idempotent).
     */
   private static function addMissingTranslation(int $templates_id, string $language, array $content): void {
      if ($templates_id <= 0) {
          return;
      }

       $translation = new NotificationTemplateTranslation();
      if ($translation->getFromDBByCrit(['notificationtemplates_id' => $templates_id, 'language' => $language])) {
          return;
      }

       $translation->add([
           'notificationtemplates_id' => $templates_id,
           'language'                 => $language,
           'subject'                  => $content['subject'],
           'content_html'             => $content['html'],
           'content_text'             => strip_tags(str_replace('</p>', "\n", $content['html'])),
       ]);
   }

    /**
     * Retire les notifications, gabarits et cibles semes par install().
     * Sans cela, une desinstallation/reinstallation laisse les anciennes
     * lignes en place et le garde-fou d'idempotence d'install() empeche
     * toute correction ulterieure du seed (ex: changement de type de cible).
     */
   public static function uninstall(): void {
       global $DB;

       $notifIds = [];
      foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => Remise::class]]) as $row) {
          $notifIds[] = (int) $row['id'];
      }

      if ($notifIds === []) {
          return;
      }

       $templateIds = [];
      foreach ($DB->request([
           'FROM'  => Notification_NotificationTemplate::getTable(),
           'WHERE' => ['notifications_id' => $notifIds],
       ]) as $row) {
          $templateIds[] = (int) $row['notificationtemplates_id'];
      }

       $DB->delete(NotificationTarget::getTable(), ['notifications_id' => $notifIds]);
       $DB->delete(Notification_NotificationTemplate::getTable(), ['notifications_id' => $notifIds]);
       $DB->delete(Notification::getTable(), ['id' => $notifIds]);

      if ($templateIds !== []) {
          $DB->delete(NotificationTemplateTranslation::getTable(), ['notificationtemplates_id' => $templateIds]);
          $DB->delete(NotificationTemplate::getTable(), ['id' => $templateIds]);
      }
   }
}
