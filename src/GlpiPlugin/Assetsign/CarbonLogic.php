<?php

/**
 * -------------------------------------------------------------------------
 * Assetsign plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign;

/**
 * Issue #228 : règles pures (sans GLPI) de la liaison avec le plugin Carbon (pluginsGLPI/carbon).
 * Testé sans base de données (tests/CarbonLogicTest.php).
 */
final class CarbonLogic
{
   public const SOURCE_MANUAL = 'manual';
   public const SOURCE_CARBON = 'carbon';

   /**
    * Consommation à partir de laquelle le facteur « énergie » du score de santé est entièrement
    * dégradé : 1 kWh par jour, soit 365 kWh par an (un poste fixe très sollicité). Un portable
    * (0,05 à 0,1 kWh/j) reste sous 10 %.
    */
   public const ENERGY_FULL_DEGRADATION_KWH_PER_DAY = 1.0;

   /**
    * Empreinte de fabrication retenue : la saisie manuelle (souvent la fiche constructeur) reste
    * prioritaire, Carbon complète les matériels sans saisie. Jamais d'estimation : null si
    * aucune des deux n'existe.
    *
    * @return array{value: float, source: string}|null
    */
   public static function footprint(?float $manual, ?float $carbon): ?array {
      if ($manual !== null) {
          return ['value' => $manual, 'source' => self::SOURCE_MANUAL];
      }
      if ($carbon !== null && $carbon > 0) {
          return ['value' => $carbon, 'source' => self::SOURCE_CARBON];
      }
       return null;
   }

   /**
    * Dégradation 0-100 du facteur « énergie » à partir de la consommation moyenne journalière
    * calculée par Carbon ; null sans donnée (le facteur est alors ignoré, pas compté à 0).
    */
   public static function energyDegradation(?float $kwhPerDay): ?int {
      if ($kwhPerDay === null || $kwhPerDay < 0) {
          return null;
      }
       return min(100, (int) round($kwhPerDay / self::ENERGY_FULL_DEGRADATION_KWH_PER_DAY * 100));
   }

   /** Grammes (unité de Carbon) vers kilogrammes (unité d'assetsign). */
   public static function gramsToKg(?float $grams): ?float {
       return $grams === null ? null : round($grams / 1000, 2);
   }
}
