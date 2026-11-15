<?php

namespace GlpiPlugin\Assetsign;

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #330 : organisation des sous-pages de l'unique entrée Outils > AssetSign (Menu).
 *
 * Quatre rubriques, chacune avec ses pages : Attributions (attributions, mouvements, départs,
 * campagnes d'attestation), Attestations d'effacement, Fiches de maintenance (droit propre,
 * Profile::RIGHT_MAINTENANCE) et Pilotage du parc (tableau de bord RSE, renouvellement). Chaque
 * page déclare elle-même son entrée et son droit (getMenuContent()) : une page, et une rubrique
 * sans page visible, n'apparaissent pas pour qui n'a pas le droit.
 */
final class MenuSection
{
    /**
     * @return array<string, array{label: string, icon: string, pages: list<class-string>}>
     */
   public static function groups(): array {
       return [
           'attributions' => [
               'label' => Assetsign::getTypeName(2),
               'icon'  => Assetsign::getIcon(),
               'pages' => [Assetsign::class, Movement::class, Departure::class, Campaign::class],
           ],
           'wipe' => [
               'label' => WipeAttestation::getTypeName(2),
               'icon'  => WipeAttestation::getIcon(),
               'pages' => [WipeAttestation::class],
           ],
           'maintenance' => [
               'label' => Maintenance::getTypeName(2),
               'icon'  => Maintenance::getIcon(),
               'pages' => [Maintenance::class],
           ],
           'fleet' => [
               'label' => __('Pilotage du parc', 'assetsign'),
               'icon'  => 'ti ti-chart-pie',
               'pages' => [CsrDashboard::class, Renewal::class],
           ],
       ];
   }

    /**
     * Entrée de menu d'une page, telle qu'elle la déclare (droits compris), ou null.
     *
     * @return array<string, mixed>|null
     */
   private static function menuOf(string $class): ?array {
       $menu = $class::getMenuContent();

       return is_array($menu) && $menu !== [] ? $menu : null;
   }

    /**
     * Pages que l'utilisateur connecté peut ouvrir, dans l'ordre des rubriques.
     *
     * @return array<class-string, array<string, mixed>>
     */
   public static function visiblePages(): array {
       $pages = [];
      foreach (self::groups() as $group) {
         foreach ($group['pages'] as $class) {
             $menu = self::menuOf($class);
            if ($menu !== null) {
                $pages[$class] = $menu;
            }
         }
      }

       return $pages;
   }

    /**
     * Barres d'onglets en tête d'une sous-page : les rubriques, puis les pages de la rubrique
     * affichée quand elle en compte plusieurs. $active est la page affichée.
     */
   public static function show(string $active): void {
       global $CFG_GLPI;

       $groups  = [];
       $current = [];
      foreach (self::groups() as $key => $group) {
          $pages = [];
         foreach ($group['pages'] as $class) {
             $menu = self::menuOf($class);
            if ($menu !== null) {
                $pages[] = [
                    'label'  => (string) $menu['title'],
                    'icon'   => (string) ($menu['icon'] ?? ''),
                    'url'    => $CFG_GLPI['root_doc'] . $menu['page'],
                    'active' => $class === $active,
                ];
            }
         }
         if ($pages === []) {
             continue;
         }
          $isActive = in_array($active, $group['pages'], true);
          $groups[] = [
              'key'    => $key,
              'label'  => $group['label'],
              'icon'   => $group['icon'],
              'url'    => $pages[0]['url'],
              'active' => $isActive,
          ];
          if ($isActive && count($pages) > 1) {
             $current = $pages;
          }
      }
      if ($groups === []) {
          return;
      }
       TemplateRenderer::getInstance()->display('@assetsign/_section_nav.html.twig', [
           'groups' => $groups,
           'pages'  => $current,
       ]);
   }
}
