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

use GlpiPlugin\Assetsign\Config;

/**
 * Seul CanvasProvider est reellement implemente. La structure reste en place
 * (interface + factory) pour permettre d'ajouter un vrai fournisseur externe
 * plus tard sans tout refondre, mais aucun squelette non fonctionnel n'est
 * expose tant qu'il n'y a rien derriere (cf. suppression de YousignProvider :
 * un admin pouvait le selectionner dans la configuration et faire planter la
 * creation de toute assetsign).
 */
final class ProviderFactory
{
   public static function for(Config $config): SignatureProviderInterface {
       $providerConfig = json_decode($config->fields['provider_config'] ?? '', true) ?: [];
       $validityDays = (int) $config->fields['link_validity_days'];

       return new CanvasProvider($providerConfig, $validityDays);
   }
}
