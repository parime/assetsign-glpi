<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\SignController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * Couvre le controle d'identite de la page de signature
 * (assertCurrentUserIsBeneficiary(), appelee par show()/submit() via
 * loadAuthorizedAssetsign()) : c'est ce qui empeche un utilisateur authentifie
 * mais non concerne de consulter ou signer le document d'un autre — jamais
 * teste automatiquement jusqu'ici. Session::getLoginUserID() lit simplement
 * $_SESSION['glpiID'] (cf. src/Session.php du coeur), simulable directement.
 */
class SignControllerTest extends AssetsignTestCase
{
    private $originalGlpiId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGlpiId = $_SESSION['glpiID'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalGlpiId === null) {
            unset($_SESSION['glpiID']);
        } else {
            $_SESSION['glpiID'] = $this->originalGlpiId;
        }
        parent::tearDown();
    }

    public function testLoadAuthorizedAssetsignThrowsWhenNoUserLoggedIn(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign NoSession');
        $assetsign = $this->createBareAssetsign($entityId, usersId: 2);
        $raw = Token::createForAssetsign($assetsign, 30);

        unset($_SESSION['glpiID']);

        $this->expectException(RuntimeException::class);
        (new SignController())->loadAuthorizedAssetsign($raw);
    }

    public function testLoadAuthorizedAssetsignThrowsForWrongUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign WrongUser');
        $assetsign = $this->createBareAssetsign($entityId, usersId: 2);
        $raw = Token::createForAssetsign($assetsign, 30);

        // Un autre utilisateur, bien authentifie, mais qui n'est pas le
        // beneficiaire de CETTE remise (ex: le lien a fuite par e-mail).
        $_SESSION['glpiID'] = 4;

        $this->expectException(RuntimeException::class);
        (new SignController())->loadAuthorizedAssetsign($raw);
    }

    public function testLoadAuthorizedAssetsignSucceedsForRealBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign RightUser');
        $assetsign = $this->createBareAssetsign($entityId, usersId: 2);
        $raw = Token::createForAssetsign($assetsign, 30);

        $_SESSION['glpiID'] = 2;

        $loaded = (new SignController())->loadAuthorizedAssetsign($raw);

        $this->assertSame($assetsign->getID(), $loaded->getID());
    }

    public function testLoadAuthorizedAssetsignThrowsForInvalidToken(): void
    {
        $_SESSION['glpiID'] = 2;

        $this->expectException(RuntimeException::class);
        (new SignController())->loadAuthorizedAssetsign('jeton-invalide');
    }

    public function testShowMarksAssetsignViewedOnFirstAccess(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign Show');
        $assetsign = $this->createBareAssetsign($entityId, usersId: 2, status: Assetsign::STATUS_SENT);
        $raw = Token::createForAssetsign($assetsign, 30);

        $_SESSION['glpiID'] = 2;

        $data = (new SignController())->show($raw);

        // markViewed() met a jour le meme objet en memoire : l'objet retourne
        // par show() reflete deja le nouveau statut, pas celui d'avant l'appel.
        $this->assertSame(Assetsign::STATUS_VIEWED, (int) $data['assetsign']->fields['status']);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_VIEWED, (int) $assetsign->fields['status'], "L'acces reel a la fiche doit bien avoir bascule le statut a VIEWED en base.");
    }
}
