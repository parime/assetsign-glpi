<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\QrLabelController;
use GlpiPlugin\Assetsign\Config;
use InvalidArgumentException;

/**
 * Couvre Api\QrLabelController (étiquette QR code imprimable du Passeport
 * matériel, cf. ROADMAP.md V3, issue #82) : dispatch de front/qrlabel.php,
 * testé directement sans passer par le vrai front/*.php (qui appelle
 * Html::displayNotFoundError()/exit(), incompatible avec PHPUnit — cf.
 * TROUBLESHOOTING.md), même motif que AssetsignFormControllerTest.
 */
class QrLabelControllerTest extends AssetsignTestCase
{
    public function testResolveReturnsQrCodeAndAbsoluteForcetabUrl(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit QrLabel Resolve');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC QrLabel');
        $computer->fields['serial'] = 'SN-QRLABEL-001';
        global $DB;
        $DB->update('glpi_computers', ['serial' => 'SN-QRLABEL-001'], ['id' => $computer->getID()]);

        $data = (new QrLabelController())->resolve('Computer', $computer->getID());

        $this->assertSame('PHPUnit PC QrLabel', $data['item_name']);
        $this->assertSame('SN-QRLABEL-001', $data['item_serial']);
        $this->assertNotNull($data['qr_data_uri'], 'BaconQrCode est fourni par le coeur GLPI : la generation ne doit pas echouer ici.');
        $this->assertStringStartsWith('data:image/png;base64,', $data['qr_data_uri']);

        // URL absolue (domaine inclus, pas juste root_doc) : indispensable pour un
        // QR code scanne depuis un appareil externe. forcetab$1 : PassportEvent
        // n'enregistre qu'un seul onglet par materiel (cf. PassportEvent::
        // getTabNameForItem()), donc toujours le suffixe $1.
        $this->assertStringContainsString('computer.form.php?id=' . $computer->getID(), $data['target_url']);
        $this->assertStringContainsString('forcetab=' . urlencode('GlpiPlugin\Assetsign\PassportEvent$1'), $data['target_url']);
        $this->assertMatchesRegularExpression('#^https?://#', $data['target_url'], 'Un QR code doit encoder une URL absolue, jamais relative.');
    }

    public function testResolveRejectsUnmanagedItemtype(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // 'Ticket' n'est jamais un itemtype gere par ce plugin (cf.
        // Config::getAllManageableItemtypes()) : aucun onglet Passeport a viser.
        (new QrLabelController())->resolve('Ticket', 1);
    }

    public function testResolveRejectsUnreachableItem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QrLabelController())->resolve('Computer', 999999999);
    }

    public function testResolveRejectsWhenFeatureDisabledForEntity(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit QrLabel Disabled');
        Config::upsertForEntity($entityId, ['enable_qr_label' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC QrLabel Disabled');

        $this->expectException(InvalidArgumentException::class);
        (new QrLabelController())->resolve('Computer', $computer->getID());
    }
}
