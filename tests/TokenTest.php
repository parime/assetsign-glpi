<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * Couvre le jeton de signature a usage unique : c'est le mecanisme qui protege
 * l'acces a une signature, jamais teste automatiquement jusqu'ici (verifie
 * uniquement a la main via des scripts Docker).
 */
class TokenTest extends AssetsignTestCase
{
    public function testCreateForAssetsignReturnsAUsableRawToken(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Create');
        $assetsign = $this->createBareAssetsign($entityId);

        $raw = Token::createForAssetsign($assetsign, 30);

        $this->assertNotEmpty($raw);
        $token = Token::validate($raw);
        $this->assertSame($assetsign->getID(), (int) $token->fields['plugin_assetsign_assetsigns_id']);
    }

    public function testValidateRejectsUnknownToken(): void
    {
        $this->expectException(RuntimeException::class);
        Token::validate('un-jeton-qui-n-existe-pas');
    }

    public function testValidateRejectsExpiredToken(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Token Expired');
        $assetsign = $this->createBareAssetsign($entityId);
        $raw = Token::createForAssetsign($assetsign, 30);

        // Force l'expiration dans le passe (contourne validityDays pour le test).
        $DB->update('glpi_plugin_assetsign_tokens', ['date_expiration' => date('Y-m-d H:i:s', time() - 3600)], [
            'plugin_assetsign_assetsigns_id' => $assetsign->getID(),
        ]);

        $this->expectException(RuntimeException::class);
        Token::validate($raw);
    }

    public function testValidateRejectsAlreadyUsedToken(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Used');
        $assetsign = $this->createBareAssetsign($entityId);
        $raw = Token::createForAssetsign($assetsign, 30);

        $token = Token::validate($raw);
        $token->markUsed();

        $this->expectException(RuntimeException::class);
        Token::validate($raw);
    }

    public function testRegenerateInvalidatesPreviousToken(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Regenerate');
        $assetsign = $this->createBareAssetsign($entityId);

        $firstRaw = Token::createForAssetsign($assetsign, 30);
        $secondRaw = Token::regenerateForAssetsign($assetsign, 30);

        $this->assertNotSame($firstRaw, $secondRaw);

        $this->expectException(RuntimeException::class);
        Token::validate($firstRaw);
    }

    public function testInvalidateForAssetsignInvalidatesAllTokens(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Invalidate');
        $assetsign = $this->createBareAssetsign($entityId);
        $raw = Token::createForAssetsign($assetsign, 30);

        Token::invalidateForAssetsign($assetsign->getID());

        $this->expectException(RuntimeException::class);
        Token::validate($raw);
    }

    public function testTokenIsDisabledAfterTooManyAttempts(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token MaxAttempts');
        $assetsign = $this->createBareAssetsign($entityId);
        $raw = Token::createForAssetsign($assetsign, 30);

        // MAX_ATTEMPTS vaut 20 (constante privee de Token) : 20 validations
        // reussissent, la 21e doit echouer et desactiver definitivement le jeton.
        for ($i = 0; $i < 20; $i++) {
            Token::validate($raw);
        }

        try {
            Token::validate($raw);
            $this->fail('La 21e tentative aurait du etre rejetee (MAX_ATTEMPTS depasse).');
        } catch (RuntimeException $e) {
            // __() avec la meme chaine source que Token::validate() plutot que le
            // texte francais en dur : ce test doit rester valable quelle que soit
            // la langue de l'environnement d'execution (meme piege que deja
            // rencontre et corrige sur AssetsignTest.php - cf. TROUBLESHOOTING.md).
            $this->assertSame(__('Trop de tentatives, lien désactivé par sécurité.', 'assetsign'), $e->getMessage());
        }

        // Le jeton est desormais desactive : meme un appel "propre" (dans la
        // limite du nombre de tentatives) doit continuer a echouer, avec le
        // message "plus valide" (jeton invalide) plutot que "trop de tentatives"
        // cette fois - deux chaines source distinctes, verifiees separement.
        try {
            Token::validate($raw);
            $this->fail('Le jeton desactive ne doit plus jamais etre accepte.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('Ce lien de signature n\'est plus valide.', 'assetsign'), $e->getMessage());
        }
    }

    public function testGetExpiryForAssetsignReturnsLatestValidTokenExpiry(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Expiry');
        $assetsign = $this->createBareAssetsign($entityId);

        $this->assertNull(Token::getExpiryForAssetsign($assetsign->getID()), 'Aucun jeton cree : aucune expiration a renvoyer.');

        Token::createForAssetsign($assetsign, 15);
        $expiry = Token::getExpiryForAssetsign($assetsign->getID());

        $this->assertNotNull($expiry);
        $expectedDay = date('Y-m-d', time() + 15 * DAY_TIMESTAMP);
        $this->assertStringStartsWith($expectedDay, $expiry);
    }
}
