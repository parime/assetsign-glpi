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
use GlpiPlugin\Assetsign\Token;

/**
 * Fournisseur par defaut : signature capturee directement sur la page
 * publique du plugin (signature_pad.js + PDF.js), sans aucun service tiers.
 * Gratuit, zero dependance externe, mais niveau de preuve "simple" (SES) :
 * aucune verification d'identite du signataire au-dela du lien recu par e-mail.
 */
final class CanvasProvider extends AbstractProvider
{
   public function createRequest(Assetsign $assetsign, string $pdfPath): void {
       $raw = Token::regenerateForAssetsign($assetsign, $this->linkValidityDays);
       $assetsign->_current_raw_token = $raw;
   }
}
