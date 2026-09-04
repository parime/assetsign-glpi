<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\EnvironmentalData;

/**
 * Logique partagée par front/environmentaldata.form.php (saisie manuelle de
 * l'empreinte environnementale sur l'onglet Passeport matériel, issue #80) —
 * même motivation/structure que Api\ResidualValueFormController : rendre ce
 * dispatch testable en PHPUnit, sans passer par le vrai front/*.php
 * (Html::back()/exit()).
 */
final class EnvironmentalDataFormController
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
       // conversion en float - chaine vide = effacement explicite (jamais 0,
       // qui serait une vraie empreinte nulle).
       $rawValue = trim((string) ($post['carbon_footprint_manufacturing'] ?? ''));
       $carbonFootprint = $rawValue === '' ? null : (float) str_replace(',', '.', $rawValue);
      if ($carbonFootprint !== null && $carbonFootprint < 0) {
          throw new \InvalidArgumentException(__('L\'empreinte de fabrication ne peut pas être négative.', 'assetsign'));
      }

       $source = (string) ($post['source'] ?? '');
       $confidence = (string) ($post['confidence_level'] ?? '');
      if ($carbonFootprint !== null && !array_key_exists($source, EnvironmentalData::getSourceLabels())) {
          throw new \InvalidArgumentException(__('Source invalide.', 'assetsign'));
      }
      if ($carbonFootprint !== null && !array_key_exists($confidence, EnvironmentalData::getConfidenceLabels())) {
          throw new \InvalidArgumentException(__('Niveau de confiance invalide.', 'assetsign'));
      }

       EnvironmentalData::upsertForItem($itemtype, $items_id, $carbonFootprint, $source ?: null, $confidence ?: null);

       return $carbonFootprint === null
           ? __('Empreinte environnementale effacée.', 'assetsign')
           : __('Empreinte environnementale enregistrée.', 'assetsign');
   }
}
