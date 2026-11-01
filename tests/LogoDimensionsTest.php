<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use PHPUnit\Framework\TestCase;

/**
 * Issue #281 : un logo aux dimensions excessives (bombe de décompression) est refusé, sans
 * décompresser l'image : seul l'en-tête est lu.
 */
class LogoDimensionsTest extends TestCase
{
    /**
     * PNG minimal (signature + en-tête IHDR) déclarant les dimensions voulues : quelques octets,
     * suffisants pour getimagesize().
     */
    private function pngDeclaring(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x06\x00\x00\x00";
        $chunk = pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk);
        return $path;
    }

    public function testHugeDeclaredDimensionsAreRefused(): void
    {
        $path = $this->pngDeclaring(20000, 20000);
        $this->assertLessThan(100, filesize($path), 'Le fichier lui-même reste minuscule.');
        $this->assertTrue(Config::isLogoTooLarge($path));
        unlink($path);
    }

    public function testNormalLogoIsAccepted(): void
    {
        $path = $this->pngDeclaring(400, 120);
        $this->assertFalse(Config::isLogoTooLarge($path));
        unlink($path);
    }

    public function testLimitIsInclusive(): void
    {
        $path = $this->pngDeclaring(Config::LOGO_MAX_PIXELS, Config::LOGO_MAX_PIXELS);
        $this->assertFalse(Config::isLogoTooLarge($path));
        unlink($path);
    }
}
