<?php

namespace GlpiPlugin\Assetsign;

use Glpi\Application\View\TemplateRenderer;

/**
 * Menu Outils : trois rubriques au lieu de sept entrées, comme les rubriques du plugin GRC Manager.
 *
 * Une rubrique est une seule entrée de menu (le parent) ; les autres écrans du même travail sont
 * ses sous-pages (options GLPI : fil d'Ariane « Rubrique > Page ») et partagent une barre
 * d'onglets en tête de page (showSection()). Fiches de maintenance reste une rubrique à part :
 * elle a son propre droit (Profile::RIGHT_MAINTENANCE).
 */
final class MenuSection
{
    /**
     * @return array<class-string, list<class-string>>
     */
   public static function sections(): array {
       return [
           // Remises, mouvements, départs et campagnes d'attestation : le cycle de vie du matériel.
           Assetsign::class    => [Movement::class, Departure::class, Campaign::class],
           // Tableau de bord RSE et plan de renouvellement : le pilotage du parc.
           CsrDashboard::class => [Renewal::class],
       ];
   }

    /**
     * Entrée de menu d'un écran, telle qu'il la déclare (droits compris), ou null.
     *
     * @return array<string, mixed>|null
     */
   private static function menuOf(string $class): ?array {
       $menu = $class::getMenuContent();

       return is_array($menu) && $menu !== [] ? $menu : null;
   }

    /**
     * Ajoute les sous-pages de la rubrique $parent à son entrée de menu.
     *
     * @param array<string, mixed>|false $menu
     * @return array<string, mixed>|false
     */
   public static function withSubPages(string $parent, $menu) {
      if (!is_array($menu) || $menu === []) {
          return $menu;
      }
      foreach (self::sections()[$parent] ?? [] as $child) {
          $childMenu = self::menuOf($child);
         if ($childMenu !== null) {
             $menu['options'][$child] = $childMenu;
         }
      }

       return $menu;
   }

    /**
     * Barre d'onglets de la rubrique de $parent, $active étant la page affichée. Rien si
     * l'utilisateur ne voit qu'une seule page de la rubrique.
     */
   public static function showSection(string $parent, string $active): void {
       global $CFG_GLPI;

       $parentMenu = self::menuOf($parent);
      if ($parentMenu === null) {
          return;
      }
       $pages = [$parent => $parentMenu];
      foreach (self::sections()[$parent] ?? [] as $child) {
          $childMenu = self::menuOf($child);
         if ($childMenu !== null) {
             $pages[$child] = $childMenu;
         }
      }
      if (count($pages) < 2) {
          return;
      }
       $tabs = [];
      foreach ($pages as $key => $page) {
          $tabs[] = [
              // Le parent peut porter, dans sa barre, un nom de page distinct du nom de la rubrique.
              'label'  => (string) ($key === $parent ? ($page['section_label'] ?? $page['title']) : $page['title']),
              'icon'   => (string) ($page['icon'] ?? ''),
              'url'    => $CFG_GLPI['root_doc'] . $page['page'],
              'active' => $key === $active,
          ];
      }
       TemplateRenderer::getInstance()->display('@assetsign/_section_nav.html.twig', ['tabs' => $tabs]);
   }
}
