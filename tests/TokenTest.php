<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Token;
use RuntimeException;

/**
 * Couvre le jeton de signature a usage unique : c'est le mecanisme qui protege
 * l'acces a une signature, jamais teste automatiquement jusqu'ici (verifie
 * uniquement a la main via des scripts Docker).
 */
class TokenTest extends RemiseTestCase
{
    public function testCreateForRemiseReturnsAUsableRawToken(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Create');
        $remise = $this->createBareRemise($entityId);

        $raw = Token::createForRemise($remise, 30);

        $this->assertNotEmpty($raw);
        $token = Token::validate($raw);
        $this->assertSame($remise->getID(), (int) $token->fields['plugin_remise_remises_id']);
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
        $remise = $this->createBareRemise($entityId);
        $raw = Token::createForRemise($remise, 30);

        // Force l'expiration dans le passe (contourne validityDays pour le test).
        $DB->update('glpi_plugin_remise_tokens', ['date_expiration' => date('Y-m-d H:i:s', time() - 3600)], [
            'plugin_remise_remises_id' => $remise->getID(),
        ]);

        $this->expectException(RuntimeException::class);
        Token::validate($raw);
    }

    public function testValidateRejectsAlreadyUsedToken(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Used');
        $remise = $this->createBareRemise($entityId);
        $raw = Token::createForRemise($remise, 30);

        $token = Token::validate($raw);
        $token->markUsed();

        $this->expectException(RuntimeException::class);
        Token::validate($raw);
    }

    public function testRegenerateInvalidatesPreviousToken(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Regenerate');
        $remise = $this->createBareRemise($entityId);

        $firstRaw = Token::createForRemise($remise, 30);
        $secondRaw = Token::regenerateForRemise($remise, 30);

        $this->assertNotSame($firstRaw, $secondRaw);

        $this->expectException(RuntimeException::class);
        Token::validate($firstRaw);
    }

    public function testInvalidateForRemiseInvalidatesAllTokens(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Invalidate');
        $remise = $this->createBareRemise($entityId);
        $raw = Token::createForRemise($remise, 30);

        Token::invalidateForRemise($remise->getID());

        $this->expectException(RuntimeException::class);
        Token::validate($raw);
    }

    public function testTokenIsDisabledAfterTooManyAttempts(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token MaxAttempts');
        $remise = $this->createBareRemise($entityId);
        $raw = Token::createForRemise($remise, 30);

        // MAX_ATTEMPTS vaut 20 (constante privee de Token) : 20 validations
        // reussissent, la 21e doit echouer et desactiver definitivement le jeton.
        for ($i = 0; $i < 20; $i++) {
            Token::validate($raw);
        }

        try {
            Token::validate($raw);
            $this->fail('La 21e tentative aurait du etre rejetee (MAX_ATTEMPTS depasse).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tentatives', $e->getMessage());
        }

        // Le jeton est desormais desactive : meme un appel "propre" (dans la
        // limite du nombre de tentatives) doit continuer a echouer, avec le
        // message "plus valide" plutot que "trop de tentatives" cette fois.
        try {
            Token::validate($raw);
            $this->fail('Le jeton desactive ne doit plus jamais etre accepte.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('plus valide', $e->getMessage());
        }
    }

    public function testGetExpiryForRemiseReturnsLatestValidTokenExpiry(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Token Expiry');
        $remise = $this->createBareRemise($entityId);

        $this->assertNull(Token::getExpiryForRemise($remise->getID()), 'Aucun jeton cree : aucune expiration a renvoyer.');

        Token::createForRemise($remise, 15);
        $expiry = Token::getExpiryForRemise($remise->getID());

        $this->assertNotNull($expiry);
        $expectedDay = date('Y-m-d', time() + 15 * DAY_TIMESTAMP);
        $this->assertStringStartsWith($expectedDay, $expiry);
    }
}
