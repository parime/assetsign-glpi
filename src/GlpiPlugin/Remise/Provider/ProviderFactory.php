<?php

namespace GlpiPlugin\Remise\Provider;

use GlpiPlugin\Remise\Config;

/**
 * Seul CanvasProvider est reellement implemente. La structure reste en place
 * (interface + factory) pour permettre d'ajouter un vrai fournisseur externe
 * plus tard sans tout refondre, mais aucun squelette non fonctionnel n'est
 * expose tant qu'il n'y a rien derriere (cf. suppression de YousignProvider :
 * un admin pouvait le selectionner dans la configuration et faire planter la
 * creation de toute remise).
 */
final class ProviderFactory
{
   public static function for(Config $config): SignatureProviderInterface {
       $providerConfig = json_decode($config->fields['provider_config'] ?? '', true) ?: [];
       $validityDays = (int) $config->fields['link_validity_days'];

       return new CanvasProvider($providerConfig, $validityDays);
   }
}
