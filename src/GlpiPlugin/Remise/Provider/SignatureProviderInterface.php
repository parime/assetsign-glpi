<?php

namespace GlpiPlugin\Remise\Provider;

use GlpiPlugin\Remise\Remise;

/**
 * Abstraction du prestataire de signature. Le plugin n'est jamais lui-meme
 * un moteur de signature : il orchestre et delegue toujours a une
 * implementation de cette interface, interchangeable par configuration.
 */
interface SignatureProviderInterface
{
    /** Initie une demande de signature pour la remise donnee. */
    public function createRequest(Remise $remise, string $pdfPath): SignatureRequestResult;

    /** Interprete un rappel entrant (webhook du prestataire). */
    public function handleCallback(array $payload): SignatureCallbackResult;

    /** true si ce fournisseur gere lui-meme les relances (SaaS), false si le plugin doit les piloter. */
    public function managesReminders(): bool;

    /** Identifiant court du fournisseur (stocke dans glpi_plugin_remise_signatures.provider). */
    public function getKey(): string;
}
