<?php

namespace GlpiPlugin\Remise\Provider;

/**
 * Resultat du lancement d'une demande de signature aupres d'un prestataire.
 */
final class SignatureRequestResult
{
    public function __construct(
        public readonly ?string $reference,
        public readonly ?string $signUrl,
    ) {
    }
}
