<?php

namespace GlpiPlugin\Remise\Provider;

/**
 * Resultat de l'interpretation d'un rappel entrant (webhook) d'un prestataire.
 * Seul l'etat "ignored" existe pour l'instant : le fournisseur canvas (le seul
 * implemente) ne recoit jamais de webhook, cf. CanvasProvider::handleCallback().
 * Un futur fournisseur externe asynchrone ajoutera ses propres etats
 * (signe/consulte/refuse...) quand il sera reellement implemente, plutot que
 * de les predefinir ici sans aucun code pour les produire ou les consommer.
 */
final class SignatureCallbackResult
{
    public const STATE_IGNORED = 'ignored';

    private function __construct(
        public readonly string $state,
        public readonly array $payload = [],
    ) {
    }

    public static function ignored(): self
    {
        return new self(self::STATE_IGNORED);
    }
}
