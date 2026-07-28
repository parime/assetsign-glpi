<?php

namespace GlpiPlugin\Remise\Provider;

use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Token;

/**
 * Fournisseur par defaut : signature capturee directement sur la page
 * publique du plugin (signature_pad.js + PDF.js), sans aucun service tiers.
 * Gratuit, zero dependance externe, mais niveau de preuve "simple" (SES) :
 * aucune verification d'identite du signataire au-dela du lien recu par e-mail.
 */
final class CanvasProvider extends AbstractProvider
{
    public function getKey(): string
    {
        return 'canvas';
    }

    public function createRequest(Remise $remise, string $pdfPath): SignatureRequestResult
    {
        $raw = Token::regenerateForRemise($remise, $this->linkValidityDays);
        $remise->_current_raw_token = $raw;

        return new SignatureRequestResult(
            reference: null,
            signUrl: $remise->getSignUrl($raw),
        );
    }

    public function handleCallback(array $payload): SignatureCallbackResult
    {
        // Le canvas natif ne recoit jamais de webhook : la signature est traitee
        // en synchrone par Api\SignController::submit() sur notre propre page publique.
        return SignatureCallbackResult::ignored();
    }
}
