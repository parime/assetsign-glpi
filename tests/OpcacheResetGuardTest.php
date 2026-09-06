<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Security\OpcacheResetGuard;
use PHPUnit\Framework\TestCase;

/**
 * OpcacheResetGuard est de la logique pure (pas d'acces base de donnees ni de session
 * GLPI) : ce test n'a pas besoin d'etendre AssetsignTestCase, comme SignatureImageValidatorTest.
 *
 * Garde de non-regression pour front/opcache_reset.php, le seul point d'entree deja vise par
 * une vraie faille (revue de securite marketplace GLPI, low, #98 : IP seule contournable
 * derriere un reverse-proxy en mode loopback) et qui n'avait jusqu'ici aucun test — une
 * regression future (ex: remplacer hash_equals() par ===, ou oublier la verification d'IP)
 * ne se serait jamais vue autrement qu'a la prochaine revue de securite manuelle.
 */
class OpcacheResetGuardTest extends TestCase
{
    public function testRejectsRequestFromNonLoopbackAddressEvenWithCorrectToken(): void
    {
        $guard = new OpcacheResetGuard();

        $this->assertFalse($guard->isAuthorized('203.0.113.10', 'le-bon-jeton', 'le-bon-jeton'));
    }

    public function testAcceptsIpv4AndIpv6LoopbackWithCorrectToken(): void
    {
        $guard = new OpcacheResetGuard();

        $this->assertTrue($guard->isAuthorized('127.0.0.1', 'le-bon-jeton', 'le-bon-jeton'));
        $this->assertTrue($guard->isAuthorized('::1', 'le-bon-jeton', 'le-bon-jeton'));
    }

    public function testRejectsWrongToken(): void
    {
        $guard = new OpcacheResetGuard();

        $this->assertFalse($guard->isAuthorized('127.0.0.1', 'mauvais-jeton', 'le-bon-jeton'));
    }

    public function testRejectsMissingToken(): void
    {
        $guard = new OpcacheResetGuard();

        $this->assertFalse($guard->isAuthorized('127.0.0.1', null, 'le-bon-jeton'));
        $this->assertFalse($guard->isAuthorized('127.0.0.1', '', 'le-bon-jeton'));
    }

    /**
     * Si le fichier jeton n'a jamais ete cree (installation anterieure au correctif #98, ou
     * probleme de permissions), $expectedToken vaut '' cote appelant — une chaine vide ne doit
     * JAMAIS etre consideree valide meme si providedToken est aussi vide, sans quoi l'endpoint
     * redeviendrait protege par la seule IP.
     */
    public function testRejectsWhenExpectedTokenIsEmpty(): void
    {
        $guard = new OpcacheResetGuard();

        $this->assertFalse($guard->isAuthorized('127.0.0.1', '', ''));
        $this->assertFalse($guard->isAuthorized('127.0.0.1', null, ''));
    }
}
