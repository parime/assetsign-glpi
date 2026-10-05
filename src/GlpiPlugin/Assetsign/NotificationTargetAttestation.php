<?php

namespace GlpiPlugin\Assetsign;

use Html;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Issue #158 : notifications des attestations de détention — invitation et relances à la personne
 * qui atteste, alerte au gestionnaire du parc (auteur de la campagne) quand un écart est signalé.
 * Même modèle que NotificationTargetDeparture : cibles propres, modèles en 5 langues semés à
 * l'installation et modifiables ensuite dans Configuration > Notifications.
 */
class NotificationTargetAttestation extends NotificationTarget
{
   public const TARGET_HOLDER         = 900201;
   public const TARGET_CAMPAIGN_OWNER = 900202;

   private const LANGUAGES = ['en_GB', 'es_ES', 'de_DE', 'it_IT'];

   public function getEvents() {
       return [
           'attestation_invitation'  => __('Attestation de détention à signer', 'assetsign'),
           'attestation_reminder'    => __('Attestation de détention : relance', 'assetsign'),
           'attestation_discrepancy' => __('Attestation de détention : écart signalé', 'assetsign'),
       ];
   }

   public function addAdditionalTargets($event = '') {
       $this->addTarget(self::TARGET_HOLDER, __('Personne qui atteste', 'assetsign'));
       $this->addTarget(self::TARGET_CAMPAIGN_OWNER, __('Auteur de la campagne', 'assetsign'));
   }

   public function addSpecificTargets($data, $options) {
       /** @var Attestation $attestation */
       $attestation = $this->obj;
       $usersId = match ((int) ($data['items_id'] ?? 0)) {
           self::TARGET_HOLDER         => (int) $attestation->fields['users_id'],
           self::TARGET_CAMPAIGN_OWNER => (int) ($attestation->getCampaign()?->fields['users_id'] ?? 0),
           default                     => 0,
       };
       $user = new \User();
      if ($usersId <= 0 || !$user->getFromDB($usersId)) {
          return;
      }
       $email = \UserEmail::getDefaultForUser($usersId);
      if (empty($email)) {
          return; // sans e-mail : page « Mes documents à signer » et bandeau d'accueil (#150)
      }
       $this->addToRecipientsList([
           'language' => $user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB'),
           'name'     => formatUserName(0, $user->fields['name'], $user->fields['realname'], $user->fields['firstname']),
           'email'    => $email,
       ]);
   }

   public function addDataForTemplate($event, $options = []) {
       /** @var Attestation $attestation */
       $attestation = $this->obj;
       $campaign = $attestation->getCampaign();
       $events = $this->getAllEvents();
       $items = $attestation->getItems();

       $this->data['##attestation.action##']   = $events[$event] ?? '';
       $this->data['##attestation.user##']     = getUserName((int) $attestation->fields['users_id']);
       $this->data['##attestation.campaign##'] = $campaign !== null ? (string) $campaign->fields['name'] : '';
       $this->data['##attestation.deadline##'] = $campaign !== null ? (string) Html::convDate($campaign->fields['date_deadline']) : '';
       $this->data['##attestation.count##']    = (string) count($items);
       $this->data['##attestation.url##']      = $attestation->getUrl();
       $this->data['##attestation.comment##']  = (string) ($attestation->fields['comment'] ?? '');
       $this->data['##attestation.ticket##']   = (int) $attestation->fields['tickets_id'] > 0 ? (string) $attestation->fields['tickets_id'] : '';
       $lines = [];
      foreach ($items as $item) {
          $lines[] = [
              '##item.type##'   => $item['type_label'],
              '##item.name##'   => $item['name'],
              '##item.serial##' => $item['serial'],
          ];
      }
       // Bloc ##FOREACHitems## : liste de lignes, forme attendue par le moteur de modèles de GLPI.
       $this->data['items'] = $lines; // @phpstan-ignore assign.propertyType

       $this->getTags();
      foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
         if (!isset($this->data[$tag])) {
             $this->data[$tag] = $values['label'];
         }
      }
   }

   public function getTags() {
       $tags = [
           'attestation.action'   => _n('Event', 'Events', 1),
           'attestation.user'     => __('Personne qui atteste', 'assetsign'),
           'attestation.campaign' => __('Campagne', 'assetsign'),
           'attestation.deadline' => __('Date limite', 'assetsign'),
           'attestation.count'    => __('Nombre de matériels', 'assetsign'),
           'attestation.url'      => __('Lien de signature', 'assetsign'),
           'attestation.comment'  => __('Commentaire', 'assetsign'),
           'attestation.ticket'   => __('Ticket', 'assetsign'),
           'item.type'            => __('Type de matériel', 'assetsign'),
           'item.name'            => __('Matériel', 'assetsign'),
           'item.serial'          => __('N° de série', 'assetsign'),
       ];
       foreach ($tags as $tag => $label) {
          $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
       }
       $this->addTagToList(['tag' => 'items', 'label' => __('Matériel détenu', 'assetsign'), 'value' => false, 'foreach' => true]);

       asort($this->tag_descriptions);
   }

   /**
    * @return array<string, array{name: string, targets: list<int>, texts: array<string, array{subject: string, html: string}>}>
    */
   private static function defaults(): array {
       $list = '<ul>##FOREACHitems##<li>##item.type## <strong>##item.name##</strong> ##item.serial##</li>##ENDFOREACHitems##</ul>';

       return [
           'attestation_invitation' => [
               'name'    => 'Attestation de détention à signer',
               'targets' => [self::TARGET_HOLDER],
               'texts'   => [
                   'fr_FR' => ['subject' => 'Attestation de détention de matériel : ##attestation.campaign##',
                       'html' => '<p>Bonjour ##attestation.user##,</p><p>Merci de vérifier la liste du matériel qui vous est attribué, puis de la confirmer en signant (ou de nous signaler un écart) avant le ##attestation.deadline## :</p>'
                           . $list . '<p><a href="##attestation.url##">Vérifier et signer mon attestation</a></p>'],
                   'en_GB' => ['subject' => 'Equipment holding attestation: ##attestation.campaign##',
                       'html' => '<p>Hello ##attestation.user##,</p><p>Please check the list of equipment assigned to you, then confirm it by signing (or report a discrepancy) before ##attestation.deadline##:</p>'
                           . $list . '<p><a href="##attestation.url##">Check and sign my attestation</a></p>'],
                   'es_ES' => ['subject' => 'Certificación de tenencia de material: ##attestation.campaign##',
                       'html' => '<p>Hola ##attestation.user##,</p><p>Compruebe la lista del material que tiene asignado y confírmela firmando (o indíquenos una discrepancia) antes del ##attestation.deadline##:</p>'
                           . $list . '<p><a href="##attestation.url##">Comprobar y firmar mi certificación</a></p>'],
                   'de_DE' => ['subject' => 'Bestätigung des Gerätebesitzes: ##attestation.campaign##',
                       'html' => '<p>Hallo ##attestation.user##,</p><p>Bitte prüfen Sie die Liste der Ihnen zugewiesenen Geräte und bestätigen Sie sie mit Ihrer Unterschrift (oder melden Sie eine Abweichung) bis zum ##attestation.deadline##:</p>'
                           . $list . '<p><a href="##attestation.url##">Bestätigung prüfen und unterschreiben</a></p>'],
                   'it_IT' => ['subject' => 'Attestazione di detenzione dell\'attrezzatura: ##attestation.campaign##',
                       'html' => '<p>Buongiorno ##attestation.user##,</p><p>Verifichi l\'elenco dell\'attrezzatura a lei assegnata, poi lo confermi firmando (o ci segnali una discrepanza) entro il ##attestation.deadline##:</p>'
                           . $list . '<p><a href="##attestation.url##">Verificare e firmare la mia attestazione</a></p>'],
               ],
           ],
           'attestation_reminder' => [
               'name'    => 'Attestation de détention : relance',
               'targets' => [self::TARGET_HOLDER],
               'texts'   => [
                   'fr_FR' => ['subject' => 'Rappel : attestation de détention à signer',
                       'html' => '<p>Bonjour ##attestation.user##,</p><p>Votre attestation de détention de matériel attend toujours votre réponse (date limite : ##attestation.deadline##).</p>'
                           . $list . '<p><a href="##attestation.url##">Vérifier et signer mon attestation</a></p>'],
                   'en_GB' => ['subject' => 'Reminder: equipment holding attestation to sign',
                       'html' => '<p>Hello ##attestation.user##,</p><p>Your equipment holding attestation is still awaiting your answer (deadline: ##attestation.deadline##).</p>'
                           . $list . '<p><a href="##attestation.url##">Check and sign my attestation</a></p>'],
                   'es_ES' => ['subject' => 'Recordatorio: certificación de tenencia por firmar',
                       'html' => '<p>Hola ##attestation.user##,</p><p>Su certificación de tenencia de material sigue pendiente de respuesta (fecha límite: ##attestation.deadline##).</p>'
                           . $list . '<p><a href="##attestation.url##">Comprobar y firmar mi certificación</a></p>'],
                   'de_DE' => ['subject' => 'Erinnerung: Bestätigung des Gerätebesitzes',
                       'html' => '<p>Hallo ##attestation.user##,</p><p>Ihre Bestätigung des Gerätebesitzes wartet noch auf Ihre Antwort (Frist: ##attestation.deadline##).</p>'
                           . $list . '<p><a href="##attestation.url##">Bestätigung prüfen und unterschreiben</a></p>'],
                   'it_IT' => ['subject' => 'Promemoria: attestazione di detenzione da firmare',
                       'html' => '<p>Buongiorno ##attestation.user##,</p><p>La sua attestazione di detenzione dell\'attrezzatura è ancora in attesa di risposta (scadenza: ##attestation.deadline##).</p>'
                           . $list . '<p><a href="##attestation.url##">Verificare e firmare la mia attestazione</a></p>'],
               ],
           ],
           'attestation_discrepancy' => [
               'name'    => 'Attestation de détention : écart signalé',
               'targets' => [self::TARGET_CAMPAIGN_OWNER],
               'texts'   => [
                   'fr_FR' => ['subject' => 'Écart d\'inventaire signalé par ##attestation.user##',
                       'html' => '<p>##attestation.user## signale un écart dans sa liste de matériel (campagne ##attestation.campaign##) :</p><p>##attestation.comment##</p>'
                           . $list . '<p>Ticket n°##attestation.ticket##.</p>'],
                   'en_GB' => ['subject' => 'Inventory discrepancy reported by ##attestation.user##',
                       'html' => '<p>##attestation.user## reports a discrepancy in their equipment list (campaign ##attestation.campaign##):</p><p>##attestation.comment##</p>'
                           . $list . '<p>Ticket ###attestation.ticket##.</p>'],
                   'es_ES' => ['subject' => 'Discrepancia de inventario indicada por ##attestation.user##',
                       'html' => '<p>##attestation.user## indica una discrepancia en su lista de material (campaña ##attestation.campaign##):</p><p>##attestation.comment##</p>'
                           . $list . '<p>Ticket n.º ##attestation.ticket##.</p>'],
                   'de_DE' => ['subject' => 'Inventarabweichung gemeldet von ##attestation.user##',
                       'html' => '<p>##attestation.user## meldet eine Abweichung in der Geräteliste (Kampagne ##attestation.campaign##):</p><p>##attestation.comment##</p>'
                           . $list . '<p>Ticket Nr. ##attestation.ticket##.</p>'],
                   'it_IT' => ['subject' => 'Discrepanza di inventario segnalata da ##attestation.user##',
                       'html' => '<p>##attestation.user## segnala una discrepanza nel suo elenco di attrezzatura (campagna ##attestation.campaign##):</p><p>##attestation.comment##</p>'
                           . $list . '<p>Ticket n. ##attestation.ticket##.</p>'],
               ],
           ],
       ];
   }

   public static function install(): void {
      foreach (self::defaults() as $event => $def) {
          $existing = new Notification();
         if ($existing->getFromDBByCrit(['itemtype' => Attestation::class, 'event' => $event])) {
             continue;
         }
          $templatesId = (new NotificationTemplate())->add(['name' => $def['name'], 'itemtype' => Attestation::class]);
         foreach (['' => 'fr_FR'] + array_combine(self::LANGUAGES, self::LANGUAGES) as $language => $key) {
             (new NotificationTemplateTranslation())->add([
                 'notificationtemplates_id' => $templatesId,
                 'language'                 => $language,
                 'subject'                  => $def['texts'][$key]['subject'],
                 'content_html'             => $def['texts'][$key]['html'],
                 'content_text'             => strip_tags(str_replace(['</p>', '</li>'], "\n", $def['texts'][$key]['html'])),
             ]);
         }
          $notificationsId = (new Notification())->add([
              'name'         => $def['name'],
              'entities_id'  => 0,
              'is_recursive' => 1,
              'itemtype'     => Attestation::class,
              'event'        => $event,
              'is_active'    => 1,
          ]);
          (new Notification_NotificationTemplate())->add([
              'notifications_id'         => $notificationsId,
              'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
              'notificationtemplates_id' => $templatesId,
          ]);
         foreach ($def['targets'] as $target) {
             (new NotificationTarget())->add([
                 'notifications_id' => $notificationsId,
                 'type'             => Notification::USER_TYPE,
                 'items_id'         => $target,
             ]);
         }
      }
   }

   public static function uninstall(): void {
       global $DB;

       $notifIds = array_map('intval', array_column(iterator_to_array($DB->request([
           'SELECT' => ['id'], 'FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => Attestation::class],
       ]), false), 'id'));
       $templateIds = array_map('intval', array_column(iterator_to_array($DB->request([
           'SELECT' => ['id'], 'FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => Attestation::class],
       ]), false), 'id'));
      if ($notifIds !== []) {
          $DB->delete(NotificationTarget::getTable(), ['notifications_id' => $notifIds]);
          $DB->delete(Notification_NotificationTemplate::getTable(), ['notifications_id' => $notifIds]);
          $DB->delete(Notification::getTable(), ['id' => $notifIds]);
      }
      if ($templateIds !== []) {
          $DB->delete(NotificationTemplateTranslation::getTable(), ['notificationtemplates_id' => $templateIds]);
          $DB->delete(NotificationTemplate::getTable(), ['id' => $templateIds]);
      }
   }
}
