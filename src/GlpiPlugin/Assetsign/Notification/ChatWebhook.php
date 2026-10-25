<?php

namespace GlpiPlugin\Assetsign\Notification;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Compat\Http;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #231 : avis dans un outil de messagerie (Teams, Slack, Mattermost) par webhook entrant,
 * en plus des e-mails : a l'envoi d'une fiche et a chaque relance.
 *
 * Le message ne contient JAMAIS le lien de signature personnel (il porte un jeton secret et le
 * canal est partage) : seulement un lien vers « Mes signatures », qui exige la connexion du
 * beneficiaire. L'URL du webhook, qui vaut mot de passe, est chiffree en base (GLPIKey).
 * Un echec d'envoi est journalise et n'interrompt jamais l'envoi de la fiche ni la relance.
 */
final class ChatWebhook
{
   public const FORMATS = ['teams', 'slack', 'mattermost'];

    /** Adresses jamais appelees : boucle locale, lien local (dont les metadonnees cloud). */
   private const FORBIDDEN_HOST_PATTERN = '/^(localhost|127\.|0\.|169\.254\.|\[?::1\]?$|\[?fe80:)/i';

    /**
     * Code d'erreur si l'URL n'est pas acceptable, null sinon.
     */
   public static function validateUrl(string $url): ?string {
       $parts = parse_url(trim($url));
      if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
          return 'https_required';
      }
      if (preg_match(self::FORBIDDEN_HOST_PATTERN, (string) $parts['host']) === 1) {
          return 'forbidden_host';
      }
       return null;
   }

    /**
     * Corps JSON attendu par chaque outil.
     *
     * @return array<string, mixed>
     */
   public static function payload(string $format, string $title, string $text, string $url, string $button): array {
      if ($format === 'teams') {
          // Webhook des « Workflows » Teams : carte adaptative dans une piece jointe.
          return [
              'type'        => 'message',
              'attachments' => [[
                  'contentType' => 'application/vnd.microsoft.card.adaptive',
                  'content'     => [
                      '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                      'type'    => 'AdaptiveCard',
                      'version' => '1.4',
                      'body'    => [
                          ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'wrap' => true],
                          ['type' => 'TextBlock', 'text' => $text, 'wrap' => true],
                      ],
                      'actions' => [['type' => 'Action.OpenUrl', 'title' => $button, 'url' => $url]],
                  ],
              ]],
          ];
      }
      if ($format === 'slack') {
          return ['text' => sprintf("*%s*\n%s\n<%s|%s>", self::slackEscape($title), self::slackEscape($text), $url, self::slackEscape($button))];
      }
       return ['text' => sprintf("**%s**\n%s\n[%s](%s)", $title, $text, $button, $url)];
   }

   public static function encryptUrl(string $url): string {
       return $url === '' ? '' : (new \GLPIKey())->encrypt($url);
   }

   public static function decryptUrl(string $stored): string {
       return $stored === '' ? '' : (string) (new \GLPIKey())->decrypt($stored);
   }

    /**
     * Avis pour une fiche : 'sent' (envoi) ou 'reminder' (relance).
     */
   public static function notify(Assetsign $assetsign, string $event): bool {
       $config = Config::getForEntity((int) $assetsign->fields['entities_id']);
       $enabled = $event === 'reminder' ? 'chat_on_reminder' : 'chat_on_send';
      if (empty($config->fields[$enabled]) || !in_array($config->fields['chat_webhook_format'] ?? '', self::FORMATS, true)) {
          return false;
      }

       $beneficiary = $assetsign->getBeneficiary();
       $name = trim(($beneficiary['firstname'] ?? '') . ' ' . ($beneficiary['realname'] ?? '')) ?: (string) ($beneficiary['name'] ?? '');
       $item = (string) ($assetsign->getTargetItem()['name'] ?? '');
      if ($event === 'reminder') {
          $title = __('Rappel : signature en attente', 'assetsign');
          $text = sprintf(
              __('La fiche « %1$s » de %2$s attend sa signature depuis le %3$s.', 'assetsign'),
              $item,
              $name,
              \Html::convDate((string) ($assetsign->fields['date_sent'] ?? ''))
          );
      } else {
          $title = __('Nouvelle fiche à signer', 'assetsign');
          $text = sprintf(__('La fiche « %1$s » attend la signature de %2$s.', 'assetsign'), $item, $name);
      }

       return self::send($config, $title, $text);
   }

    /**
     * Envoie un message ; vrai si l'outil l'a accepte (code HTTP 2xx).
     */
   public static function send(Config $config, string $title, string $text): bool {
       global $CFG_GLPI;

       $url = self::decryptUrl((string) ($config->fields['chat_webhook_url'] ?? ''));
       $format = (string) ($config->fields['chat_webhook_format'] ?? '');
      if ($url === '' || !in_array($format, self::FORMATS, true) || self::validateUrl($url) !== null) {
          return false;
      }
       $link = rtrim((string) $CFG_GLPI['url_base'], '/') . '/plugins/assetsign/front/mysignatures.php';
       $error = '';
       $ok = Http::postJson($url, self::payload($format, $title, $text, $link, __('Ouvrir mes signatures', 'assetsign')), $error);
      if (!$ok) {
          \Toolbox::logInFile('assetsign', sprintf("Webhook %s refusé : %s\n", $format, $error));
      }
       return $ok;
   }

   private static function slackEscape(string $text): string {
       return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
   }
}
