<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Provider;

use GlpiPlugin\Assetsign\Assetsign;

/**
 * Abstraction du prestataire de signature. Le plugin n'est jamais lui-meme
 * un moteur de signature : il orchestre et delegue toujours a une
 * implementation de cette interface, interchangeable par configuration.
 */
interface SignatureProviderInterface
{
    /** Initie une demande de signature pour la remise donnee. */
   public function createRequest(Assetsign $assetsign, string $pdfPath): void;

    /** true si ce fournisseur gere lui-meme les relances (SaaS), false si le plugin doit les piloter. */
   public function managesReminders(): bool;
}
