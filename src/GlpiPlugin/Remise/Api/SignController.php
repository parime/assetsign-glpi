<?php

namespace GlpiPlugin\Remise\Api;

use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Token;
use GlpiPlugin\Remise\Pdf\SignatureStamper;
use GlpiPlugin\Remise\Pdf\SignatureImageValidator;
use RuntimeException;
use Session;

/**
 * Logique partagee par front/sign.php.
 *
 * Connexion GLPI obligatoire (cf. Firewall::STRATEGY_AUTHENTICATED dans setup.php) :
 * un simple lien avec jeton valide ne suffit plus a lui seul. En plus de la validite
 * du jeton (Token::validate()), on verifie ici que l'utilisateur EFFECTIVEMENT
 * connecte est bien le beneficiaire de la remise — sans quoi un autre utilisateur
 * authentifie qui mettrait la main sur le lien (transfert d'e-mail, etc.) pourrait
 * sinon consulter ou signer un document qui ne le concerne pas.
 */
final class SignController
{
    /**
     * Valide le jeton, charge la remise et verifie que l'utilisateur connecte
     * en est bien le beneficiaire. Reutilise par show()/submit() et par
     * le flux de telechargement du PDF (front/sign.php, action=pdf).
     *
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas le beneficiaire
     */
    public function loadAuthorizedRemise(string $rawToken): Remise
    {
        $token = Token::validate($rawToken);

        $remise = new Remise();
        if (!$remise->getFromDB((int) $token->fields['plugin_remise_remises_id'])) {
            throw new RuntimeException('Remise introuvable.');
        }

        $this->assertCurrentUserIsBeneficiary($remise);

        return $remise;
    }

    /**
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas le beneficiaire
     */
    public function show(string $rawToken): array
    {
        $token = Token::validate($rawToken);

        $remise = new Remise();
        if (!$remise->getFromDB((int) $token->fields['plugin_remise_remises_id'])) {
            throw new RuntimeException('Remise introuvable.');
        }

        $this->assertCurrentUserIsBeneficiary($remise);

        if ((int) $remise->fields['status'] === Remise::STATUS_SENT) {
            $remise->markViewed();
        }

        return [
            'remise' => $remise,
            'user'   => $remise->getBeneficiary(),
            'item'   => $remise->getTargetItem(),
            'expiry' => $token->fields['date_expiration'],
        ];
    }

    /**
     * @param string $signatureImagePng Image PNG encodee en base64 (data URI complet)
     * @throws RuntimeException
     */
    public function submit(string $rawToken, string $signatureImagePng, array $meta): void
    {
        $token = Token::validate($rawToken);

        $remise = new Remise();
        if (!$remise->getFromDB((int) $token->fields['plugin_remise_remises_id'])) {
            throw new RuntimeException('Remise introuvable.');
        }

        $this->assertCurrentUserIsBeneficiary($remise);

        // Le controle cote client (signature_pad.isEmpty()) ne protege que contre
        // les erreurs d'usage normales ; un appel direct (ou un client modifie)
        // pourrait envoyer n'importe quoi, d'ou cette verification independante.
        SignatureImageValidator::assertValid($signatureImagePng);

        $stamper = new SignatureStamper();
        $result = $stamper->apply($remise, $signatureImagePng, $meta);

        $user = $remise->getBeneficiary();

        $remise->markSigned($result['path'], [
            'provider'      => 'canvas',
            'signer_name'   => trim(($user['firstname'] ?? '') . ' ' . ($user['realname'] ?? '')),
            'signer_email'  => $user['email'] ?? '',
            'ip_address'    => $meta['ip'] ?? '',
            'user_agent'    => $meta['user_agent'] ?? '',
            'document_hash' => $result['hash'],
            'signed_at'     => $result['signed_at'],
            'proof_data'    => [
                'method'          => 'canvas_signature_pad',
                'glpi_users_id'   => Session::getLoginUserID(),
            ],
        ]);

        $token->markUsed();
    }

    private function assertCurrentUserIsBeneficiary(Remise $remise): void
    {
        $currentUserId = (int) Session::getLoginUserID();

        if ($currentUserId <= 0) {
            // Ne devrait pas arriver (Firewall::STRATEGY_AUTHENTICATED impose deja
            // une session) ; filet de securite si jamais l'appel se fait autrement.
            throw new RuntimeException('Vous devez être connecté pour accéder à ce document.');
        }

        if ($currentUserId !== (int) $remise->fields['users_id']) {
            throw new RuntimeException('Ce document ne correspond pas à votre compte utilisateur.');
        }
    }
}
