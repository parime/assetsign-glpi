<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\ConfigTransfer;
use GlpiPlugin\Assetsign\Notification\ChatWebhook;
use GlpiPlugin\Assetsign\Template;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #279 : export et import de la configuration. Le fichier importé est une donnée non fiable :
 * schéma strict, secrets jamais exportés ni importés, objets GLPI désignés par leur nom et
 * revérifiés dans l'entité cible, logo contrôlé sur son contenu réel, simulation sans écriture.
 */
class ConfigTransferTest extends AssetsignTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private const NO_OPTIONS = ['logo' => false, 'templates' => false];

    /** Toute colonne de la table de configuration doit être classée : exportée, propre à l'instance ou secrète. */
    public function testEveryConfigColumnIsClassified(): void
    {
        global $DB;

        $known = array_merge(
            array_keys(ConfigTransfer::getSchema()),
            ConfigTransfer::INSTANCE_FIELDS,
            ConfigTransfer::SECRET_FIELDS
        );
        foreach (array_keys($DB->listFields(Config::getTable())) as $column) {
            $this->assertContains(
                $column,
                $known,
                "Colonne « $column » absente de ConfigTransfer::SCHEMA, INSTANCE_FIELDS et SECRET_FIELDS."
            );
        }
    }

    public function testExportNeverContainsSecretsOrInstanceIdentifiers(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Transfert export');
        Config::upsertForEntity($entityId, [
            'sender_name'         => 'Parc informatique fictif',
            'chat_webhook_format' => 'teams',
            'chat_webhook_url'    => 'https://exemple.invalid/webhook/jeton-secret-fictif',
        ]);
        $this->assertNotSame('', (string) Config::getForEntity($entityId)->fields['chat_webhook_url']);

        $export = ConfigTransfer::export($entityId, true, true);
        $json = (string) json_encode($export);

        $this->assertSame(ConfigTransfer::FORMAT_VERSION, $export['format_version']);
        $this->assertSame('Parc informatique fictif', $export['config']['sender_name']);
        foreach (array_merge(ConfigTransfer::SECRET_FIELDS, ConfigTransfer::INSTANCE_FIELDS) as $key) {
            $this->assertArrayNotHasKey($key, $export['config']);
        }
        $this->assertStringNotContainsString('jeton-secret-fictif', $json);
        $this->assertSame(ConfigTransfer::SECRET_FIELDS, $export['excluded']);
    }

    public function testRoundTripReferencesStatesByNameAndAppliesToAnotherEntity(): void
    {
        $source = $this->createTestEntity(0, 'PHPUnit Transfert source');
        $target = $this->createTestEntity(0, 'PHPUnit Transfert cible');
        $stock = $this->createState('PHPUnit Transfert En stock', 0, true);
        $returned = $this->createState('PHPUnit Transfert Restitue', 0, true);

        Config::upsertForEntity($source, [
            'sender_name'           => 'Service parc fictif',
            'link_validity_days'    => 12,
            'stock_states'          => [$stock],
            'return_returned_state' => $returned,
            'manage_Computer'       => 1,
            'manage_Monitor'        => 1,
            'enable_signature_delegation'    => 1,
            'enable_self_service_delegation' => 1,
            'reference_format'      => '{prefixe}-{annee}-{seq}',
            'reference_prefix'      => 'RM',
        ]);

        $export = ConfigTransfer::export($source, false, false);
        $this->assertSame(['PHPUnit Transfert En stock'], $export['config']['stock_states']);
        $this->assertSame('PHPUnit Transfert Restitue', $export['config']['return_returned_state']);

        $payload = ConfigTransfer::parse((string) json_encode($export));
        $plan = ConfigTransfer::plan($payload, $target, self::NO_OPTIONS);
        $this->assertFalse($plan['own_row']);
        $this->assertContains('sender_name', array_column($plan['changes'], 'key'));
        $this->assertNotSame('Service parc fictif', Config::getForEntity($target)->fields['sender_name'], 'La simulation ne doit rien écrire.');

        $result = ConfigTransfer::apply($payload, $target, self::NO_OPTIONS);
        $this->assertGreaterThan(0, $result['settings']);

        $config = Config::getForEntity($target);
        $this->assertSame($target, (int) $config->fields['entities_id']);
        $this->assertSame('Service parc fictif', $config->fields['sender_name']);
        $this->assertSame(12, (int) $config->fields['link_validity_days']);
        $this->assertSame([$stock], $config->getStockStates());
        $this->assertSame($returned, (int) $config->fields['return_returned_state']);
        $this->assertEqualsCanonicalizing(['Computer', 'Monitor'], $config->getManagedItemtypes());
        $this->assertSame(1, (int) $config->fields['enable_self_service_delegation']);
        $this->assertSame('{prefixe}-{annee}-{seq}', $config->fields['reference_format']);
        $this->assertSame('RM', $config->fields['reference_prefix']);

        // Réimporter le même fichier ne change plus rien.
        $this->assertSame([], ConfigTransfer::plan($payload, $target, self::NO_OPTIONS)['changes']);
    }

    public function testStateOutOfTargetEntityScopeIsIgnoredAndReported(): void
    {
        $other = $this->createTestEntity(0, 'PHPUnit Transfert autre');
        $target = $this->createTestEntity(0, 'PHPUnit Transfert cible etat');
        $this->createState('PHPUnit Transfert Etat prive', $other, false);

        $payload = ConfigTransfer::parse($this->file(['stock_states' => ['PHPUnit Transfert Etat prive', 'PHPUnit Etat inexistant']]));
        $plan = ConfigTransfer::plan($payload, $target, self::NO_OPTIONS);

        $this->assertCount(2, $plan['report']);
        $this->assertSame([], $plan['input']['stock_states']);
        ConfigTransfer::apply($payload, $target, self::NO_OPTIONS);
        $this->assertSame([], Config::getForEntity($target)->getStockStates());
    }

    public function testSecretsInImportedFileAreIgnoredAndReported(): void
    {
        $target = $this->createTestEntity(0, 'PHPUnit Transfert secret');
        Config::upsertForEntity($target, ['chat_webhook_format' => 'teams', 'chat_webhook_url' => 'https://exemple.invalid/hook/origine']);
        $before = (string) Config::getForEntity($target)->fields['chat_webhook_url'];

        $payload = ConfigTransfer::parse($this->file([
            'sender_name'      => 'Importe',
            'chat_webhook_url' => 'https://attaquant.invalid/hook',
            'provider_config'  => '{"api_key":"fictive"}',
            'entities_id'      => 0,
            'logo_documents_id' => 1,
        ]));
        $this->assertEqualsCanonicalizing(['chat_webhook_url', 'provider_config', 'entities_id', 'logo_documents_id'], $payload['ignored']);

        $plan = ConfigTransfer::plan($payload, $target, self::NO_OPTIONS);
        $this->assertCount(4, $plan['report']);
        ConfigTransfer::apply($payload, $target, self::NO_OPTIONS);

        $config = Config::getForEntity($target);
        $this->assertSame('Importe', $config->fields['sender_name']);
        $this->assertSame($before, (string) $config->fields['chat_webhook_url']);
        $this->assertSame('https://exemple.invalid/hook/origine', ChatWebhook::decryptUrl($before));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidFiles(): array
    {
        $base = ['format_version' => 1, 'plugin' => 'assetsign'];
        $with = static fn (array $config): string => (string) json_encode($base + ['config' => $config]);

        return [
            'pas du JSON'              => ['{pas du json'],
            'autre plugin'             => [(string) json_encode(['format_version' => 1, 'plugin' => 'autre', 'config' => []])],
            'version de format'        => [(string) json_encode(['format_version' => 2, 'plugin' => 'assetsign', 'config' => []])],
            'clé racine inconnue'      => [(string) json_encode($base + ['config' => [], 'path' => '/etc/passwd'])],
            'réglage inconnu'          => [$with(['is_admin' => 1])],
            'entier en texte'          => [$with(['link_validity_days' => '30'])],
            'entier hors bornes'       => [$with(['link_validity_days' => 999999])],
            'booléen invalide'         => [$with(['protect_pdf' => 2])],
            'URL javascript'           => [$with(['charter_url' => 'javascript:alert(1)'])],
            'e-mail invalide'          => [$with(['sender_email' => 'pas-un-email'])],
            'texte trop long'          => [$with(['company_name' => str_repeat('a', 256)])],
            'couleur CSS libre'        => [$with(['health_score_good_color' => 'red;background:url(x)'])],
            'statuts par identifiant'  => [$with(['stock_states' => [12]])],
            'liste trop longue'        => [$with(['stock_states' => array_map('strval', range(1, 201))])],
            'énumération'              => [$with(['confirm_before_sending' => 7])],
            'référence avec balise'    => [$with(['reference_format' => '<b>{seq}</b>'])],
            'gabarit clé inconnue'     => [(string) json_encode($base + ['templates' => [['type' => 0, 'name' => 'x', 'entities_id' => 3]]])],
            'gabarit type inconnu'     => [(string) json_encode($base + ['templates' => [['type' => 99, 'name' => 'x']]])],
            'gabarit en double'        => [(string) json_encode($base + ['templates' => [['type' => 0, 'name' => 'x'], ['type' => 0, 'name' => 'y']]])],
            'logo avec chemin'         => [(string) json_encode($base + ['logo' => ['path' => '/etc/passwd']])],
            'logo SVG'                 => [(string) json_encode($base + ['logo' => ['mime_type' => 'image/svg+xml', 'data' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]])],
            'logo PHP'                 => [(string) json_encode($base + ['logo' => ['mime_type' => 'image/png', 'data' => base64_encode('<?php echo 1; ?>')]])],
            'logo type annoncé faux'   => [(string) json_encode($base + ['logo' => ['mime_type' => 'image/png', 'data' => self::GIF_1X1]])],
            'logo base64 invalide'     => [(string) json_encode($base + ['logo' => ['mime_type' => 'image/png', 'data' => '***']])],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConfigTransfer::parse($json);
    }

    public function testOversizedFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConfigTransfer::parse(str_repeat(' ', ConfigTransfer::MAX_FILE_BYTES + 1));
    }

    public function testRejectionMessageNeverEchoesALongKey(): void
    {
        try {
            ConfigTransfer::parse($this->file([str_repeat('x', 500) => 1]));
            $this->fail('Clé inconnue acceptée.');
        } catch (InvalidArgumentException $e) {
            $this->assertLessThan(300, mb_strlen($e->getMessage()));
        }
    }

    public function testLogoIsCheckedAndImported(): void
    {
        $this->assertSame(['image/png', 'png'], ConfigTransfer::checkLogoBytes((string) base64_decode(self::PNG_1X1)));
        $this->assertSame(['image/gif', 'gif'], ConfigTransfer::checkLogoBytes((string) base64_decode(self::GIF_1X1)));

        $target = $this->createTestEntity(0, 'PHPUnit Transfert logo');
        // Configuration propre sans logo : le logo herite de la racine ne doit pas fausser le test.
        Config::upsertForEntity($target, ['logo_documents_id' => 0]);
        $payload = ConfigTransfer::parse((string) json_encode([
            'format_version' => 1,
            'plugin'         => 'assetsign',
            'config'         => [],
            'logo'           => ['mime_type' => 'image/png', 'data' => self::PNG_1X1],
        ]));

        $this->assertSame('none', ConfigTransfer::plan($payload, $target, self::NO_OPTIONS)['logo']);
        $options = ['logo' => true, 'templates' => false];
        $this->assertSame('replace', ConfigTransfer::plan($payload, $target, $options)['logo']);

        $result = ConfigTransfer::apply($payload, $target, $options);
        $this->assertTrue($result['logo']);
        $documentId = (int) Config::getForEntity($target)->fields['logo_documents_id'];
        $document = new \Document();
        $this->assertTrue($document->getFromDB($documentId));
        $this->assertSame($target, (int) $document->fields['entities_id']);
        $this->assertSame('image/png', $document->fields['mime']);
        $this->assertSame('unchanged', ConfigTransfer::plan($payload, $target, $options)['logo']);
    }

    public function testTemplatesAreCreatedInTargetEntityOnly(): void
    {
        $target = $this->createTestEntity(0, 'PHPUnit Transfert gabarit');
        $payload = ConfigTransfer::parse((string) json_encode([
            'format_version' => 1,
            'plugin'         => 'assetsign',
            'templates'      => [[
                'type'            => Assetsign::TYPE_HANDOVER,
                'name'            => 'Gabarit fictif importe',
                'content'         => '<p>Conditions fictives</p>',
                'charter_content' => '',
                'include_content' => 1,
                'include_charter' => 0,
            ]],
        ]));
        $options = ['logo' => false, 'templates' => true];

        $plan = ConfigTransfer::plan($payload, $target, $options);
        $this->assertSame('create', $plan['templates'][0]['action']);
        $this->assertNotSame('Gabarit fictif importe', Template::getDefaultFor(Assetsign::TYPE_HANDOVER, $target)?->fields['name']);

        $this->assertSame(1, ConfigTransfer::apply($payload, $target, $options)['templates']);
        $template = Template::getDefaultFor(Assetsign::TYPE_HANDOVER, $target);
        $this->assertNotNull($template);
        $this->assertSame($target, (int) $template->fields['entities_id']);
        $this->assertSame('Gabarit fictif importe', $template->fields['name']);
        $this->assertSame(0, (int) $template->fields['include_charter']);

        $this->assertSame('unchanged', ConfigTransfer::plan($payload, $target, $options)['templates'][0]['action']);
    }

    public function testTemplatesAreSkippedWithoutTemplateRight(): void
    {
        $target = $this->createTestEntity(0, 'PHPUnit Transfert sans droit');
        $payload = ConfigTransfer::parse((string) json_encode([
            'format_version' => 1,
            'plugin'         => 'assetsign',
            'templates'      => [['type' => Assetsign::TYPE_RETURN, 'name' => 'Gabarit refuse']],
        ]));

        $saved = $_SESSION['glpiactiveprofile'][Template::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][Template::$rightname] = READ;
        try {
            $options = ['logo' => false, 'templates' => true];
            $plan = ConfigTransfer::plan($payload, $target, $options);
            $this->assertSame('denied', $plan['templates'][0]['action']);
            $this->assertSame(0, ConfigTransfer::apply($payload, $target, $options)['templates']);
        } finally {
            $_SESSION['glpiactiveprofile'][Template::$rightname] = $saved;
        }
    }

    public function testPendingImportIsSingleUseAndBoundToItsToken(): void
    {
        $payload = ConfigTransfer::parse($this->file(['sender_name' => 'x']));
        $token = ConfigTransfer::storePending($payload, 0, self::NO_OPTIONS);

        $this->assertNull(ConfigTransfer::takePending(str_repeat('0', 32)));
        $token = ConfigTransfer::storePending($payload, 0, self::NO_OPTIONS);
        $pending = ConfigTransfer::takePending($token);
        $this->assertNotNull($pending);
        $this->assertSame(0, $pending['entities_id']);
        $this->assertNull(ConfigTransfer::takePending($token));
    }

    /** @param array<string, mixed> $config */
    private function file(array $config): string
    {
        return (string) json_encode(['format_version' => 1, 'plugin' => 'assetsign', 'config' => $config]);
    }

    private function createState(string $name, int $entityId, bool $recursive): int
    {
        $state = new \State();
        $id = (int) $state->add(['name' => $name, 'entities_id' => $entityId, 'is_recursive' => (int) $recursive]);
        $this->assertGreaterThan(0, $id);
        return $id;
    }
}
