<?php

namespace GlpiPlugin\Remise\Provider;

/**
 * Resultat de l'interpretation d'un rappel entrant (webhook) d'un prestataire.
 */
final class SignatureCallbackResult
{
    public const STATE_VIEWED  = 'viewed';
    public const STATE_SIGNED  = 'signed';
    public const STATE_REFUSED = 'refused';
    public const STATE_IGNORED = 'ignored';

    private function __construct(
        public readonly string $state,
        public readonly array $payload = [],
    ) {
    }

    public static function viewed(array $payload): self
    {
        return new self(self::STATE_VIEWED, $payload);
    }

    public static function signed(array $payload): self
    {
        return new self(self::STATE_SIGNED, $payload);
    }

    public static function refused(array $payload): self
    {
        return new self(self::STATE_REFUSED, $payload);
    }

    public static function ignored(): self
    {
        return new self(self::STATE_IGNORED);
    }
}
