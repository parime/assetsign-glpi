<?php

namespace GlpiPlugin\Assetsign\Security;

use CommonDBTM;

/**
 * Logique de garde de front/assign_user_asset.php, extraite pour la rendre testable en PHPUnit
 * contre de vraies fixtures (entite/materiel/session) plutot que seulement verifiee a la main -
 * meme motif que Security\OpcacheResetGuard, adapte ici a une garde qui a besoin d'un vrai objet
 * GLPI (pas de logique purement scalaire possible, contrairement au jeton d'OpcacheResetGuard).
 *
 * `is_subclass_of($itemtype, CommonDBTM::class)` restreint l'instanciation a la famille GLPI
 * CommonDBTM (faux positif tainted-object-instantiation deja revu, cf. ARCHITECTURE.md) ; puis
 * `can($items_id, UPDATE)` encadre tout acces aux donnees de l'objet instancie - meme garde-fou de
 * segregation par entite que Assetsign::createManual()/Maintenance::createWithChecklist().
 */
final class AssetAssignmentGuard
{
    /**
     * @return CommonDBTM|null L'objet charge et autorise en modification, ou null si l'itemtype
     *         n'est pas une classe GLPI valide, si le materiel n'existe pas, ou si l'utilisateur
     *         courant n'a pas le droit de le modifier (garde-fou de segregation par entite).
     */
   public function resolveAssignableItem(string $itemtype, int $itemsId): ?CommonDBTM {
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          return null;
      }

       $item = new $itemtype(); // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation

      if (!$item->getFromDB($itemsId) || !$item->can($itemsId, UPDATE)) {
          return null;
      }

       return $item;
   }
}
