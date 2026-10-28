<?php

namespace GlpiPlugin\Assetsign;

use GlpiPlugin\Assetsign\Notification\NotificationTexts;
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
 *
 * @extends NotificationTarget<Attestation>
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
           // Issue #204
           'charter_invitation'      => __('Charte informatique à signer', 'assetsign'),
           'charter_reminder'        => __('Charte informatique : relance', 'assetsign'),
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
       return [
           'attestation_invitation' => [
               'name'    => 'Attestation de détention à signer',
               'targets' => [self::TARGET_HOLDER],
               'texts'   => NotificationTexts::load('attestation', 'attestation_invitation'),
           ],
           'attestation_reminder' => [
               'name'    => 'Attestation de détention : relance',
               'targets' => [self::TARGET_HOLDER],
               'texts'   => NotificationTexts::load('attestation', 'attestation_reminder'),
           ],
           'attestation_discrepancy' => [
               'name'    => 'Attestation de détention : écart signalé',
               'targets' => [self::TARGET_CAMPAIGN_OWNER],
               'texts'   => NotificationTexts::load('attestation', 'attestation_discrepancy'),
           ],
           // Issue #204 : signature de la charte informatique.
           'charter_invitation' => [
               'name'    => 'Charte informatique à signer',
               'targets' => [self::TARGET_HOLDER],
               'texts'   => NotificationTexts::load('attestation', 'charter_invitation'),
           ],
           'charter_reminder' => [
               'name'    => 'Charte informatique : relance',
               'targets' => [self::TARGET_HOLDER],
               'texts'   => NotificationTexts::load('attestation', 'charter_reminder'),
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
