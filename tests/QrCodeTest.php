<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\QrCode;
use PHPUnit\Framework\TestCase;

/**
 * QrCode est de la logique pure (encode une URL en image), sans acces base de
 * donnees : ce test n'a pas besoin d'etendre AssetsignTestCase. Classe extraite
 * de Pdf\PdfRenderingHelpers (jusqu'ici privee, ne servait que le PDF) au moment
 * d'ajouter l'etiquette QR code imprimable du Passeport materiel (cf. ROADMAP.md
 * V3, issue #82) - ce test couvre le point d'entree partage par les deux usages.
 */
class QrCodeTest extends TestCase
{
    public function testToDataUriReturnsAPngDataUri(): void
    {
        // BaconQrCode est fourni par le coeur GLPI (deja utilise pour les QR
        // codes de double authentification) : toujours present sur l'instance
        // de test dediee decrite dans tests/bootstrap.php.
        $dataUri = QrCode::toDataUri('https://glpi.example.test/plugins/assetsign/front/qrlabel.php?itemtype=Computer&items_id=1');

        $this->assertNotNull($dataUri, 'BaconQrCode est fourni par le coeur GLPI : la generation ne doit pas echouer ici.');
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);

        $binary = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($binary, 'Le contenu encode doit etre du base64 valide.');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary, 'Le binaire decode doit etre un vrai PNG (signature de fichier).');
    }

    public function testToDataUriEncodesTheExactUrlGiven(): void
    {
        // Deux URL differentes doivent produire des QR codes differents - garde-fou
        // simple contre un bug qui ignorerait silencieusement le parametre $url.
        $first = QrCode::toDataUri('https://glpi.example.test/front/computer.form.php?id=1');
        $second = QrCode::toDataUri('https://glpi.example.test/front/computer.form.php?id=2');

        $this->assertNotSame($first, $second);
    }
}
