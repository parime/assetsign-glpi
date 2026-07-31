<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Api\SignController;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Token;
use RuntimeException;

/**
 * Couvre le controle d'identite de la page de signature
 * (assertCurrentUserIsBeneficiary(), appelee par show()/submit() via
 * loadAuthorizedRemise()) : c'est ce qui empeche un utilisateur authentifie
 * mais non concerne de consulter ou signer le document d'un autre — jamais
 * teste automatiquement jusqu'ici. Session::getLoginUserID() lit simplement
 * $_SESSION['glpiID'] (cf. src/Session.php du coeur), simulable directement.
 */
class SignControllerTest extends RemiseTestCase
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

    public function testLoadAuthorizedRemiseThrowsWhenNoUserLoggedIn(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign NoSession');
        $remise = $this->createBareRemise($entityId, usersId: 2);
        $raw = Token::createForRemise($remise, 30);

        unset($_SESSION['glpiID']);

        $this->expectException(RuntimeException::class);
        (new SignController())->loadAuthorizedRemise($raw);
    }

    public function testLoadAuthorizedRemiseThrowsForWrongUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign WrongUser');
        $remise = $this->createBareRemise($entityId, usersId: 2);
        $raw = Token::createForRemise($remise, 30);

        // Un autre utilisateur, bien authentifie, mais qui n'est pas le
        // beneficiaire de CETTE remise (ex: le lien a fuite par e-mail).
        $_SESSION['glpiID'] = 4;

        $this->expectException(RuntimeException::class);
        (new SignController())->loadAuthorizedRemise($raw);
    }

    public function testLoadAuthorizedRemiseSucceedsForRealBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign RightUser');
        $remise = $this->createBareRemise($entityId, usersId: 2);
        $raw = Token::createForRemise($remise, 30);

        $_SESSION['glpiID'] = 2;

        $loaded = (new SignController())->loadAuthorizedRemise($raw);

        $this->assertSame($remise->getID(), $loaded->getID());
    }

    public function testLoadAuthorizedRemiseThrowsForInvalidToken(): void
    {
        $_SESSION['glpiID'] = 2;

        $this->expectException(RuntimeException::class);
        (new SignController())->loadAuthorizedRemise('jeton-invalide');
    }

    public function testShowMarksRemiseViewedOnFirstAccess(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Sign Show');
        $remise = $this->createBareRemise($entityId, usersId: 2, status: Remise::STATUS_SENT);
        $raw = Token::createForRemise($remise, 30);

        $_SESSION['glpiID'] = 2;

        $data = (new SignController())->show($raw);

        // markViewed() met a jour le meme objet en memoire : l'objet retourne
        // par show() reflete deja le nouveau statut, pas celui d'avant l'appel.
        $this->assertSame(Remise::STATUS_VIEWED, (int) $data['remise']->fields['status']);
        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_VIEWED, (int) $remise->fields['status'], "L'acces reel a la fiche doit bien avoir bascule le statut a VIEWED en base.");
    }
}
