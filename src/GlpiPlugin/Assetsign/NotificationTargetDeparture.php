<?php

namespace GlpiPlugin\Assetsign;

use Html;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Issue #157 : notifications du dossier de départ (Departure) — invitation unique à signer la
 * restitution de tout le matériel, relances, et avis de signature au gestionnaire du parc
 * (technicien qui a préparé le départ). Même modèle que NotificationTargetAssetsign : cibles
 * propres (personne qui part, technicien), modèles en 5 langues semés à l'installation et
 * modifiables ensuite dans Configuration > Notifications.
 */
class NotificationTargetDeparture extends NotificationTarget
{
   public const TARGET_LEAVING_USER = 900101;
   public const TARGET_TECHNICIAN   = 900102;

   private const LANGUAGES = ['en_GB', 'es_ES', 'de_DE', 'it_IT'];

   public function getEvents() {
      return [
         'departure_created'  => __('Départ : restitution du matériel à signer', 'assetsign'),
         'departure_reminder' => __('Départ : relance de signature', 'assetsign'),
         'departure_signed'   => __('Départ : restitution signée', 'assetsign'),
      ];
   }

   public function addAdditionalTargets($event = '') {
      $this->addTarget(self::TARGET_LEAVING_USER, __('Personne qui part', 'assetsign'));
      $this->addTarget(self::TARGET_TECHNICIAN, __('Technicien ayant préparé le départ', 'assetsign'));
   }

   public function addSpecificTargets($data, $options) {
      $field = match ((int) ($data['items_id'] ?? 0)) {
         self::TARGET_LEAVING_USER => 'users_id',
         self::TARGET_TECHNICIAN   => 'users_id_tech',
         default                   => null,
      };
      if ($field === null) {
         return;
      }
      $usersId = (int) ($this->obj->fields[$field] ?? 0);
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
      /** @var Departure $departure */
      $departure = $this->obj;
      $events = $this->getAllEvents();
      $lines = $departure->getItemLines();

      $this->data['##departure.action##']   = $events[$event] ?? '';
      $this->data['##departure.user##']     = getUserName((int) $departure->fields['users_id']);
      $this->data['##departure.tech##']     = getUserName((int) $departure->fields['users_id_tech']);
      $this->data['##departure.date##']     = Html::convDate($departure->fields['date_departure'] ?? null);
      $this->data['##departure.count##']    = (string) count($lines);
      $this->data['##departure.trigger##']  = Departure::getTriggerLabels()[(int) $departure->fields['trigger_type']] ?? '';
      $this->data['##departure.sign_url##'] = $departure->getSignUrl();
      $items = [];
      foreach ($lines as $line) {
         $items[] = [
            '##item.type##'   => $line['type_label'],
            '##item.name##'   => $line['name'],
            '##item.serial##' => $line['serial'],
         ];
      }
      // Bloc ##FOREACHitems## : liste de lignes, forme attendue par le moteur de modèles de GLPI.
      $this->data['items'] = $items; // @phpstan-ignore assign.propertyType

      $this->getTags();
      foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
         if (!isset($this->data[$tag])) {
            $this->data[$tag] = $values['label'];
         }
      }
   }

   public function getTags() {
      $tags = [
         'departure.action'   => _n('Event', 'Events', 1),
         'departure.user'     => __('Personne qui part', 'assetsign'),
         'departure.tech'     => __('Technicien', 'assetsign'),
         'departure.date'     => __('Date de départ', 'assetsign'),
         'departure.count'    => __('Nombre de matériels', 'assetsign'),
         'departure.trigger'  => __('Déclencheur', 'assetsign'),
         'departure.sign_url' => __('Lien de signature', 'assetsign'),
         'item.type'          => __('Type de matériel', 'assetsign'),
         'item.name'          => __('Matériel', 'assetsign'),
         'item.serial'        => __('N° de série', 'assetsign'),
      ];
      foreach ($tags as $tag => $label) {
         $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
      }
      $this->addTagToList(['tag' => 'items', 'label' => __('Matériel à restituer', 'assetsign'), 'value' => false, 'foreach' => true]);

      asort($this->tag_descriptions);
   }

   /**
    * @return array<string, array{name: string, targets: list<int>, texts: array<string, array{subject: string, html: string}>}>
    */
   private static function defaults(): array {
      $list = '<ul>##FOREACHitems##<li>##item.type## <strong>##item.name##</strong> ##item.serial##</li>##ENDFOREACHitems##</ul>';

      return [
         'departure_created' => [
            'name'    => 'Départ : restitution du matériel à signer',
            'targets' => [self::TARGET_LEAVING_USER, self::TARGET_TECHNICIAN],
            'texts'   => [
               'fr_FR' => ['subject' => 'Restitution de votre matériel : ##departure.count## élément(s) à signer',
                  'html' => '<p>Bonjour ##departure.user##,</p><p>Dans le cadre de votre départ, merci de restituer le matériel suivant et de signer la fiche de restitution :</p>'
                     . $list . '<p><a href="##departure.sign_url##">Consulter et signer la restitution</a></p>'],
               'en_GB' => ['subject' => 'Returning your equipment: ##departure.count## item(s) to sign',
                  'html' => '<p>Hello ##departure.user##,</p><p>As part of your departure, please return the following equipment and sign the return form:</p>'
                     . $list . '<p><a href="##departure.sign_url##">View and sign the return</a></p>'],
               'es_ES' => ['subject' => 'Devolución de su material: ##departure.count## elemento(s) que firmar',
                  'html' => '<p>Hola ##departure.user##,</p><p>Con motivo de su salida, devuelva el siguiente material y firme la ficha de devolución:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Consultar y firmar la devolución</a></p>'],
               'de_DE' => ['subject' => 'Rückgabe Ihrer Geräte: ##departure.count## Element(e) zu unterschreiben',
                  'html' => '<p>Hallo ##departure.user##,</p><p>Im Rahmen Ihres Austritts geben Sie bitte die folgenden Geräte zurück und unterschreiben das Rückgabeformular:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Rückgabe ansehen und unterschreiben</a></p>'],
               'it_IT' => ['subject' => 'Restituzione della sua attrezzatura: ##departure.count## elemento/i da firmare',
                  'html' => '<p>Buongiorno ##departure.user##,</p><p>In vista della sua partenza, la preghiamo di restituire la seguente attrezzatura e di firmare la scheda di restituzione:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Consultare e firmare la restituzione</a></p>'],
            ],
         ],
         'departure_reminder' => [
            'name'    => 'Départ : relance de signature',
            'targets' => [self::TARGET_LEAVING_USER],
            'texts'   => [
               'fr_FR' => ['subject' => 'Rappel : restitution de votre matériel à signer',
                  'html' => '<p>Bonjour ##departure.user##,</p><p>La restitution du matériel suivant attend toujours votre signature :</p>'
                     . $list . '<p><a href="##departure.sign_url##">Signer la restitution</a></p>'],
               'en_GB' => ['subject' => 'Reminder: equipment return awaiting your signature',
                  'html' => '<p>Hello ##departure.user##,</p><p>The return of the following equipment is still awaiting your signature:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Sign the return</a></p>'],
               'es_ES' => ['subject' => 'Recordatorio: devolución de su material pendiente de firma',
                  'html' => '<p>Hola ##departure.user##,</p><p>La devolución del siguiente material sigue pendiente de su firma:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Firmar la devolución</a></p>'],
               'de_DE' => ['subject' => 'Erinnerung: Rückgabe Ihrer Geräte wartet auf Ihre Unterschrift',
                  'html' => '<p>Hallo ##departure.user##,</p><p>Die Rückgabe der folgenden Geräte wartet noch auf Ihre Unterschrift:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Rückgabe unterschreiben</a></p>'],
               'it_IT' => ['subject' => 'Promemoria: restituzione della sua attrezzatura da firmare',
                  'html' => '<p>Buongiorno ##departure.user##,</p><p>La restituzione della seguente attrezzatura è ancora in attesa della sua firma:</p>'
                     . $list . '<p><a href="##departure.sign_url##">Firmare la restituzione</a></p>'],
            ],
         ],
         'departure_signed' => [
            'name'    => 'Départ : restitution signée',
            'targets' => [self::TARGET_TECHNICIAN],
            'texts'   => [
               'fr_FR' => ['subject' => 'Restitution signée : ##departure.user##',
                  'html' => '<p>##departure.user## a signé la restitution de ##departure.count## matériel(s) :</p>' . $list
                     . '<p><a href="##departure.sign_url##">Voir le départ et le PDF récapitulatif</a></p>'],
               'en_GB' => ['subject' => 'Return signed: ##departure.user##',
                  'html' => '<p>##departure.user## signed the return of ##departure.count## item(s):</p>' . $list
                     . '<p><a href="##departure.sign_url##">View the departure and the summary PDF</a></p>'],
               'es_ES' => ['subject' => 'Devolución firmada: ##departure.user##',
                  'html' => '<p>##departure.user## firmó la devolución de ##departure.count## elemento(s):</p>' . $list
                     . '<p><a href="##departure.sign_url##">Ver la salida y el PDF resumen</a></p>'],
               'de_DE' => ['subject' => 'Rückgabe unterschrieben: ##departure.user##',
                  'html' => '<p>##departure.user## hat die Rückgabe von ##departure.count## Gerät(en) unterschrieben:</p>' . $list
                     . '<p><a href="##departure.sign_url##">Austritt und Zusammenfassungs-PDF ansehen</a></p>'],
               'it_IT' => ['subject' => 'Restituzione firmata: ##departure.user##',
                  'html' => '<p>##departure.user## ha firmato la restituzione di ##departure.count## elemento/i:</p>' . $list
                     . '<p><a href="##departure.sign_url##">Vedere la partenza e il PDF riepilogativo</a></p>'],
            ],
         ],
      ];
   }

   public static function install(): void {
      foreach (self::defaults() as $event => $def) {
         $existing = new Notification();
         if ($existing->getFromDBByCrit(['itemtype' => Departure::class, 'event' => $event])) {
            continue;
         }

         $templatesId = (new NotificationTemplate())->add(['name' => $def['name'], 'itemtype' => Departure::class]);
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
            'itemtype'     => Departure::class,
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
         'SELECT' => ['id'],
         'FROM'   => Notification::getTable(),
         'WHERE'  => ['itemtype' => Departure::class],
      ]), false), 'id'));
      $templateIds = array_map('intval', array_column(iterator_to_array($DB->request([
         'SELECT' => ['id'],
         'FROM'   => NotificationTemplate::getTable(),
         'WHERE'  => ['itemtype' => Departure::class],
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
