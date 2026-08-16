<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Pdf\SignatureImageValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SignatureImageValidator est de la logique pure (manipulation GD), sans acces
 * base de donnees : ce test n'a pas besoin d'etendre AssetsignTestCase.
 */
class SignatureImageValidatorTest extends TestCase
{
    public function testMalformedDataUriIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SignatureImageValidator::assertValid('data:image/jpeg;base64,dGhpcyBpcyBub3QgYSBwbmc=');
    }

    public function testEmptyBase64PayloadIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SignatureImageValidator::assertValid('data:image/png;base64,');
    }

    public function testTransparentEmptyCanvasIsRejected(): void
    {
        // Un canevas de signature jamais touche : entierement transparent.
        $this->expectException(RuntimeException::class);
        SignatureImageValidator::assertValid(self::emptyCanvasDataUri(300, 100));
    }

    public function testTooSmallImageIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SignatureImageValidator::assertValid(self::emptyCanvasDataUri(10, 5));
    }

    public function testRealSignatureStrokeIsAccepted(): void
    {
        SignatureImageValidator::assertValid(self::canvasWithStrokeDataUri(300, 100));
        $this->addToAssertionCount(1); // aucune exception levee = succes
    }

    private static function emptyCanvasDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        return self::toDataUri($image);
    }

    private static function canvasWithStrokeDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $ink = imagecolorallocatealpha($image, 0, 0, 0, 0);
        imageline($image, 20, $height / 2, $width - 20, $height / 2, $ink);
        imageline($image, $width / 3, 15, $width / 2, $height - 15, $ink);

        return self::toDataUri($image);
    }

    private static function toDataUri($image): string
    {
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
    }
}
