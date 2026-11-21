<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ConditionPhoto;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;

/**
 * Issue #369 : photos de l'etat des lieux (controle du fichier, metadonnees retirees, droits,
 * entites, comparaison avant/apres, PDF).
 */
final class ConditionPhotoTest extends AssetsignTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function file(string $binary): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phpunit-photo-');
        file_put_contents($path, $binary);
        $this->files[] = $path;
        return $path;
    }

    private function image(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);
        return (string) ob_get_clean();
    }

    /** JPEG avec un segment APP1 Exif portant une fausse position GPS, juste apres SOI. */
    private function jpegWithGps(): string
    {
        $jpeg = $this->image(40, 30, 'jpeg');
        $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00" . 'GPSLatitude=48.8566;GPSLongitude=2.3522;PHPUNIT-GPS';
        $payload = "Exif\x00\x00" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    private function sheet(int $entityId, int $type = Assetsign::TYPE_HANDOVER, int $status = Assetsign::STATUS_SENT, ?int $itemsId = null): Assetsign
    {
        $sheet = $this->createBareAssetsign($entityId, $type, $status);
        if ($itemsId !== null) {
            $sheet->update(['id' => $sheet->getID(), 'items_id' => $itemsId]);
            $sheet->getFromDB($sheet->getID());
        }
        return $sheet;
    }

    public function testGpsAndOtherMetadataAreRemoved(): void
    {
        $source = $this->jpegWithGps();
        $this->assertStringContainsString('PHPUNIT-GPS', $source);

        $clean = ConditionPhoto::sanitize($this->file($source));

        $this->assertStringStartsWith("\xFF\xD8", $clean);
        $this->assertStringNotContainsString('PHPUNIT-GPS', $clean);
        $this->assertStringNotContainsString("Exif\x00\x00", $clean);
        $this->assertStringNotContainsString("\xFF\xE1", $clean);
        $size = getimagesizefromstring($clean);
        $this->assertSame([40, 30, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function testTrappedFilesAreRefused(): void
    {
        $gif = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        // En-tete PNG annoncant 50 000 x 50 000 pixels (image « bombe ») : refusee avant decodage.
        $bomb = $this->image(1, 1, 'png');
        $bomb = substr($bomb, 0, 16) . pack('NN', 50000, 50000) . substr($bomb, 24);

        $cases = [
            'script PHP renomme en .jpg' => '<?php echo "PHPUNIT-EXECUTE"; ?>',
            'GIF (type non admis)'       => $gif,
            'fichier vide'               => '',
            'image bombe'                => $bomb,
            'JPEG tronque'               => substr($this->image(40, 30, 'jpeg'), 0, 40),
        ];
        foreach ($cases as $label => $binary) {
            try {
                ConditionPhoto::sanitize($this->file($binary));
                $this->fail($label . ' : accepte');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }

        $this->expectException(\RuntimeException::class);
        ConditionPhoto::sanitize($this->file(str_repeat('A', ConditionPhoto::MAX_BYTES + 1)));
    }

    public function testPolyglotPayloadIsDroppedByReencoding(): void
    {
        $polyglot = $this->image(20, 20, 'png') . '<?php echo "PHPUNIT-POLYGLOT"; ?>';

        $clean = ConditionPhoto::sanitize($this->file($polyglot));

        $this->assertStringNotContainsString('PHPUNIT-POLYGLOT', $clean);
        $this->assertStringNotContainsString('<?php', $clean);
        $this->assertSame('image/jpeg', getimagesizefromstring($clean)['mime']);
    }

    public function testLargePhotoIsDownscaled(): void
    {
        $clean = ConditionPhoto::sanitize($this->file($this->image(2400, 1200, 'png')));

        $size = getimagesizefromstring($clean);
        $this->assertSame([ConditionPhoto::MAX_SIDE, ConditionPhoto::MAX_SIDE / 2], [$size[0], $size[1]]);
    }

    public function testPhotoIsAGlpiDocumentOfTheSheetEntity(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Photos remise');
        $sheet = $this->sheet($entityId);
        $other = $this->sheet($entityId);

        $photoId = ConditionPhoto::addToSheet($sheet, $this->file($this->jpegWithGps()), 2);

        $photos = ConditionPhoto::getForAssetsign($sheet->getID());
        $this->assertCount(1, $photos);
        $this->assertSame($photoId, $photos[0]['id']);
        $document = new \Document();
        $this->assertTrue($document->getFromDB($photos[0]['documents_id']));
        $this->assertSame($entityId, (int) $document->fields['entities_id']);
        $this->assertSame('image/jpeg', $document->fields['mime']);
        $this->assertSame(1, countElementsInTable('glpi_documents_items', [
            'documents_id' => $document->getID(), 'itemtype' => Assetsign::class, 'items_id' => $sheet->getID(),
        ]));

        $path = ConditionPhoto::resolveFile($sheet, $photoId);
        $this->assertNotNull($path);
        $this->assertSame($photos[0]['sha256'], hash_file('sha256', (string) $path));
        $this->assertStringNotContainsString('PHPUNIT-GPS', (string) file_get_contents((string) $path));
        // Une photo n'est servie que par la fiche qui la porte.
        $this->assertNull(ConditionPhoto::resolveFile($other, $photoId));
        // Pas de doublon dans « Documents joints » : les photos ont leur propre bloc.
        $this->assertNotContains($document->getID(), array_column(Assetsign::getAttachedDocuments($sheet->getID()), 'id'));

        ConditionPhoto::removeFromSheet($sheet, $photoId);
        $this->assertSame([], ConditionPhoto::getForAssetsign($sheet->getID()));
        $this->assertFalse((new \Document())->getFromDB($document->getID()));
    }

    public function testRefusedOnSignedSheetOtherTypesOrWithoutUpdateRight(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Photos refus');
        $png = $this->file($this->image(10, 10, 'png'));

        foreach ([
            'fiche signee' => $this->sheet($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED),
            'fiche de don' => $this->sheet($entityId, Assetsign::TYPE_DON),
        ] as $label => $sheet) {
            try {
                ConditionPhoto::addToSheet($sheet, $png, 2);
                $this->fail($label . ' : photo acceptee');
            } catch (\RuntimeException) {
                $this->assertSame([], ConditionPhoto::getForAssetsign($sheet->getID()), $label);
            }
        }

        $sheet = $this->sheet($entityId);
        $right = $_SESSION['glpiactiveprofile'][Assetsign::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][Assetsign::$rightname] = READ;
        try {
            $this->assertFalse(ConditionPhoto::canEdit($sheet));
            $this->expectException(\RuntimeException::class);
            ConditionPhoto::addToSheet($sheet, $png, 2);
        } finally {
            $_SESSION['glpiactiveprofile'][Assetsign::$rightname] = $right;
        }
    }

    public function testReturnShowsHandoverPhotosSideBySide(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Photos avant apres');
        $computer = $this->createTestComputer($entityId, 'PHPUNIT-PC-PHOTOS');
        $handover = $this->sheet($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT, $computer->getID());
        $beforeId = ConditionPhoto::addToSheet($handover, $this->file($this->image(30, 20, 'png')), 2);
        $return = $this->sheet($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT, $computer->getID());
        $afterId = ConditionPhoto::addToSheet($return, $this->file($this->image(30, 20, 'jpeg')), 2);

        $data = ConditionPhoto::getDisplayData($return);
        $this->assertTrue($data['is_return']);
        $this->assertSame($beforeId, $data['before']['photos'][0]['id']);
        $this->assertSame($afterId, $data['photos'][0]['id']);

        ob_start();
        $return->showForm($return->getID());
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('id="assetsign-photos"', $html);
        $this->assertStringContainsString('assetsign-photo-pair', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('photo=' . $beforeId, $html);
        $this->assertStringContainsString('photo=' . $afterId, $html);
        $this->assertNoStrayNumericTextNode($html, '_condition_photos.html.twig');
    }

    public function testHandoverOfAnotherEntityIsNotShown(): void
    {
        $entityA = $this->createTestEntity(0, 'PHPUnit Photos entite A');
        $entityB = $this->createTestEntity(0, 'PHPUnit Photos entite B');
        $computer = $this->createTestComputer($entityA, 'PHPUNIT-PC-PHOTOS-B');
        $handover = $this->sheet($entityB, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT, $computer->getID());
        ConditionPhoto::addToSheet($handover, $this->file($this->image(30, 20, 'png')), 2);
        $return = $this->sheet($entityA, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT, $computer->getID());

        $entities = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        try {
            $this->assertNull(ConditionPhoto::getDisplayData($return)['before']);
            $this->assertFalse((bool) $handover->can($handover->getID(), READ));
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']] = $entities;
        }
        $this->assertNotNull(ConditionPhoto::getDisplayData($return)['before']);
    }

    public function testPhotosAreInThePdfOnlyWhenTheOptionIsEnabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Photos PDF');
        $sheet = $this->sheet($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT, $this->createTestComputer($entityId, 'PHPUNIT-PC-PHOTO-PDF')->getID());
        ConditionPhoto::addToSheet($sheet, $this->file($this->image(30, 20, 'png')), 2);
        $hash = ConditionPhoto::getForAssetsign($sheet->getID())[0]['sha256'];

        Config::upsertForEntity($entityId, ['photos_in_pdf' => 0]);
        $html = (new HandoverPdfBuilder())->renderHtml($sheet);
        $this->assertStringContainsString($hash, $html);
        $this->assertStringNotContainsString('data:image/jpeg;base64', $html);

        Config::upsertForEntity($entityId, ['photos_in_pdf' => 1]);
        $html = (new HandoverPdfBuilder())->renderHtml($sheet);
        $this->assertStringContainsString($hash, $html);
        $this->assertStringContainsString('data:image/jpeg;base64', $html);
    }
}
