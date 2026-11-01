<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * Issue #277 : lien de signature à copier (Teams, chat) : un nouveau jeton remplace l'ancien, le
 * lien n'est affiché qu'une fois, et une fiche qui n'attend plus de signature est refusée.
 */
class ShareableLinkTest extends AssetsignTestCase
{
    private function rawToken(string $url): string
    {
        return urldecode(substr($url, (int) strpos($url, '?t=') + 3));
    }

    public function testNewLinkReplacesThePreviousOneAndIsShownOnce(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Lien a copier');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Lien a copier');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $first = $assetsign->createShareableLink();
        $second = $assetsign->createShareableLink();

        $this->assertStringContainsString('/plugins/assetsign/front/sign.php?t=', $second);
        $this->assertNotSame($this->rawToken($first), $this->rawToken($second));
        $this->assertInstanceOf(Token::class, Token::validate($this->rawToken($second)));

        $this->assertSame($second, Assetsign::pullSharedLink($assetsign->getID()));
        $this->assertSame('', Assetsign::pullSharedLink($assetsign->getID()), 'Affiché une seule fois.');

        $this->expectException(RuntimeException::class);
        Token::validate($this->rawToken($first));
    }

    public function testRefusedWhenNoLongerAwaitingSignature(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Lien a copier signe');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Lien a copier signe');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $assetsign->update(['id' => $assetsign->getID(), 'status' => Assetsign::STATUS_SIGNED]);

        $this->expectException(RuntimeException::class);
        $assetsign->createShareableLink();
    }
}
