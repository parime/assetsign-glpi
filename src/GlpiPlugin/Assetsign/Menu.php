<?php

namespace GlpiPlugin\Assetsign;

use CommonGLPI;

/**
 * Issue #330 : la seule entrée du plugin dans le menu Outils (« AssetSign »).
 *
 * Règle commune aux plugins : les réglages se font depuis la page de configuration du plugin
 * (Configuration > Plugins > AssetSign) et le plugin n'ajoute qu'une entrée d'usage aux menus de
 * GLPI. Les écrans d'usage (attributions, mouvements, départs, campagnes, attestations
 * d'effacement, fiches de maintenance, pilotage du parc) sont les sous-pages de cette entrée :
 * options GLPI (fil d'Ariane « Outils > AssetSign > Page », boutons Ajouter/Rechercher de la page)
 * et barre d'onglets commune en tête de chaque page (MenuSection::show()).
 *
 * Classe sans table : seule l'ancre de menu. Chaque sous-page garde son propre droit (son
 * getMenuContent()) ; l'entrée n'apparaît que si l'utilisateur voit au moins une sous-page et
 * mène à la première d'entre elles.
 */
class Menu extends CommonGLPI
{
   public static function getTypeName($nb = 0): string {
       // Nom du produit : identique dans toutes les langues.
       return 'AssetSign';
   }

   public static function getMenuName(): string {
       return self::getTypeName();
   }

   public static function getIcon(): string {
       return 'ti ti-signature';
   }

   public static function getSectorizedDetails(): array {
       return ['tools', self::class];
   }

    /**
     * @return array<string, mixed>|false
     */
   public static function getMenuContent() {
       $pages = MenuSection::visiblePages();
      if ($pages === []) {
          return false;
      }
       $first = reset($pages);

       return [
           'title'   => self::getMenuName(),
           'page'    => $first['page'],
           'icon'    => self::getIcon(),
           'options' => $pages,
       ];
   }
}
