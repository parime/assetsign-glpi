<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\ResidualValue;

/**
 * Logique partagée par front/residualvalue.form.php (saisie manuelle de la
 * valeur résiduelle sur l'onglet Passeport matériel, issue #77) — même
 * motivation que Api\PassportBackfillController : rendre ce dispatch testable
 * en PHPUnit, sans passer par le vrai front/*.php (Html::back()/exit()).
 */
final class ResidualValueFormController
{
   public function run(array $post): string {
       $itemtype = (string) ($post['itemtype'] ?? '');
       $items_id = (int) ($post['items_id'] ?? 0);

      if (!is_subclass_of($itemtype, \CommonDBTM::class)) {
          throw new \InvalidArgumentException(__('Type de matériel invalide.', 'assetsign'));
      }

       $item = new $itemtype();
      if (!$item->getFromDB($items_id) || !$item->can($items_id, READ)) {
          throw new \InvalidArgumentException(__('Matériel introuvable.', 'assetsign'));
      }

       // Virgule decimale acceptee (saisie francaise) en plus du point, avant
       // conversion en float - chaine vide = repli explicite sur le calcul
       // automatique (jamais 0, qui serait une vraie valeur residuelle nulle).
       $rawValue = trim((string) ($post['manual_value'] ?? ''));
       $manualValue = $rawValue === '' ? null : (float) str_replace(',', '.', $rawValue);
      if ($manualValue !== null && $manualValue < 0) {
          throw new \InvalidArgumentException(__('La valeur résiduelle ne peut pas être négative.', 'assetsign'));
      }

       ResidualValue::upsertForItem($itemtype, $items_id, $manualValue);

       return $manualValue === null
           ? __('Valeur résiduelle repassée en calcul automatique.', 'assetsign')
           : __('Valeur résiduelle manuelle enregistrée.', 'assetsign');
   }
}
