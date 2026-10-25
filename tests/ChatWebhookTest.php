<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Notification\ChatWebhook;

/**
 * Issue #231 : avis dans Teams, Slack ou Mattermost. Aucun appel reseau ici : on verifie l'adresse
 * acceptee, le corps envoye a chaque outil, le chiffrement de l'adresse et l'absence d'avis tant
 * que rien n'est configure.
 */
final class ChatWebhookTest extends AssetsignTestCase
{
   public function testUrlMustBeHttpsAndNotTheServerItself(): void {
       $this->assertNull(ChatWebhook::validateUrl('https://hooks.slack.example/services/T000/B000/XXXX'));
       $this->assertNull(ChatWebhook::validateUrl('https://mattermost.example.org/hooks/abc'));
       $this->assertSame('https_required', ChatWebhook::validateUrl('http://hooks.slack.example/services/x'));
       $this->assertSame('https_required', ChatWebhook::validateUrl('pas une adresse'));
       $this->assertSame('forbidden_host', ChatWebhook::validateUrl('https://127.0.0.1/hook'));
       $this->assertSame('forbidden_host', ChatWebhook::validateUrl('https://localhost/hook'));
       $this->assertSame('forbidden_host', ChatWebhook::validateUrl('https://169.254.169.254/latest/meta-data'));
   }

   public function testPayloadPerTool(): void {
       $teams = ChatWebhook::payload('teams', 'Titre', 'Texte', 'https://glpi.example/mysignatures.php', 'Ouvrir');
       $card = $teams['attachments'][0]['content'];
       $this->assertSame('AdaptiveCard', $card['type']);
       $this->assertSame('https://glpi.example/mysignatures.php', $card['actions'][0]['url']);

       $slack = ChatWebhook::payload('slack', 'A <b>', 'Texte & co', 'https://glpi.example/x', 'Ouvrir');
       $this->assertSame("*A &lt;b&gt;*\nTexte &amp; co\n<https://glpi.example/x|Ouvrir>", $slack['text']);

       $mattermost = ChatWebhook::payload('mattermost', 'Titre', 'Texte', 'https://glpi.example/x', 'Ouvrir');
       $this->assertSame("**Titre**\nTexte\n[Ouvrir](https://glpi.example/x)", $mattermost['text']);
   }

   public function testWebhookUrlIsEncryptedAndKeptWhenLeftEmpty(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Chat webhook');
       $url = 'https://hooks.slack.example/services/T000/B000/SECRET';

       Config::upsertForEntity($entityId, ['chat_webhook_format' => 'slack', 'chat_webhook_url' => $url, 'chat_on_send' => 1]);
       $stored = (string) Config::getForEntity($entityId)->fields['chat_webhook_url'];
       $this->assertNotSame($url, $stored, 'Jamais en clair en base.');
       $this->assertSame($url, ChatWebhook::decryptUrl($stored));

       Config::upsertForEntity($entityId, ['chat_webhook_format' => 'slack', 'chat_webhook_url' => '']);
       $this->assertSame($stored, (string) Config::getForEntity($entityId)->fields['chat_webhook_url'], 'Champ vide : adresse conservée.');

       Config::upsertForEntity($entityId, ['chat_webhook_format' => 'slack', 'chat_webhook_url' => 'http://insecure.example/x']);
       $this->assertSame($stored, (string) Config::getForEntity($entityId)->fields['chat_webhook_url'], 'Adresse refusée : rien ne change.');

       Config::upsertForEntity($entityId, ['chat_webhook_format' => 'unknown', 'chat_webhook_remove' => 1]);
       $config = Config::getForEntity($entityId);
       $this->assertSame('', (string) $config->fields['chat_webhook_url']);
       $this->assertSame('', (string) $config->fields['chat_webhook_format']);
   }

   public function testNoNoticeWithoutConfiguredTool(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Chat sans outil');
       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

       $this->assertFalse(ChatWebhook::notify($assetsign, 'sent'));
       $this->assertFalse(ChatWebhook::notify($assetsign, 'reminder'));
   }
}
