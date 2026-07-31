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
    public function createRequest(Remise $remise, string $pdfPath): void
    {
        $raw = Token::regenerateForRemise($remise, $this->linkValidityDays);
        $remise->_current_raw_token = $raw;
    }
}
