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
               'pages' => [CsrDashboard::class, Renewal::class, IntegrityCheck::class],
           ],
       ];
   }

    /**
     * Issue #336 : encadré d'aide « À quoi sert cette page ? » de chaque sous-page (charte
     * commune, section 8) : identifiant court (choix replié mémorisé), but de la page, ce qui est
     * attendu de l'utilisateur.
     *
     * @return array<class-string, array{page: string, purpose: string, expected: string}>
     */
   public static function helpTexts(): array {
       return [
           Assetsign::class => [
               'page'     => 'attributions',
               'purpose'  => __('Toutes les fiches d\'attribution du parc (remise, restitution, don, vente, destruction) et leur état de signature. Le plugin crée la fiche quand un matériel est affecté ou repris dans GLPI.', 'assetsign'),
               'expected' => __('Suivez les fiches en attente de signature : copiez un nouveau lien, relancez ou annulez au besoin. « Nouvelle attribution » crée une fiche à la main pour un matériel.', 'assetsign'),
           ],
           Movement::class => [
               'page'     => 'movements',
               'purpose'  => __('Les déplacements physiques de matériel sans bénéficiaire à faire signer : transfert entre sites, retour au stock, envoi en réparation.', 'assetsign'),
               'expected' => __('Enregistrez chaque déplacement avec son lieu de départ, sa destination et sa date ; il apparaît ensuite dans le passeport du matériel.', 'assetsign'),
           ],
           Departure::class => [
               'page'     => 'departures',
               'purpose'  => __('Les dossiers de départ des salariés : tout le matériel à restituer par une même personne, signé en une seule fois.', 'assetsign'),
               'expected' => __('Suivez l\'avancement des signatures et relancez si besoin. Un départ se prépare depuis l\'onglet AssetSign du compte de la personne.', 'assetsign'),
           ],
           Campaign::class => [
               'page'     => 'campaigns',
               'purpose'  => __('Les campagnes d\'attestation de détention : chaque personne confirme le matériel qu\'elle détient, ou signale un écart.', 'assetsign'),
               'expected' => __('Lancez une campagne avec « Nouvelle campagne », puis suivez le taux de réponse et les écarts, et relancez les personnes sans réponse avant la date limite.', 'assetsign'),
           ],
           WipeAttestation::class => [
               'page'     => 'wipe',
               'purpose'  => __('Les preuves d\'effacement des données d\'un matériel avant un don, une vente ou une destruction.', 'assetsign'),
               'expected' => __('Enregistrez une attestation pour chaque matériel qui quitte le parc. La liste du haut montre les matériels déjà sortis sans attestation, à rattraper.', 'assetsign'),
           ],
           Maintenance::class => [
               'page'     => 'maintenance',
               'purpose'  => __('Les fiches internes de maintenance ou de préparation d\'un matériel : points de contrôle, commentaire et PDF.', 'assetsign'),
               'expected' => __('Créez une fiche en choisissant le matériel, puis remplissez les points de contrôle. Une fiche créée n\'est plus modifiée.', 'assetsign'),
           ],
           CsrDashboard::class => [
               'page'     => 'csr',
               'purpose'  => __('Les indicateurs environnementaux du parc des entités actives : empreinte de fabrication, réemploi, âge et filières de fin de vie.', 'assetsign'),
               'expected' => __('Rien à saisir ici : les chiffres viennent des données renseignées sur les matériels. Complétez-les pour améliorer le taux de couverture.', 'assetsign'),
           ],
           Renewal::class => [
               'page'     => 'renewal',
               'purpose'  => __('Les matériels qui arrivent en fin d\'amortissement sur la période choisie, avec le budget de remplacement par année.', 'assetsign'),
               'expected' => __('Choisissez la période, puis exportez la liste en CSV ou créez un ticket de changement groupé pour planifier le remplacement.', 'assetsign'),
           ],
           IntegrityCheck::class => [
               'page'     => 'integrity',
               'purpose'  => __('Vérifie que chaque fiche signée garde sa preuve : le PDF signé (ou le scan papier) existe toujours, son empreinte SHA-256 n\'a pas changé et il reste rattaché au matériel.', 'assetsign'),
               'expected' => __('Le contrôle tourne chaque semaine ; « Lancer le contrôle » le relance aussitôt. Ouvrez chaque fiche en anomalie pour comprendre ce qui a changé : rien n\'est corrigé automatiquement. L\'export CSV sert de preuve de contrôle.', 'assetsign'),
           ],
       ];
   }

    /**
     * Issue #336 : encadré d'aide de « Mon matériel et mes documents » (front/mysignatures.php),
     * page ouverte à tout utilisateur connecté, hors des sous-pages de Outils > AssetSign.
     *
     * @return array{page: string, purpose: string, expected: string}
     */
   public static function myEquipmentHelp(): array {
       return [
           'page'     => 'my-equipment',
           'purpose'  => __('Le matériel qui vous est confié et les documents qui attendent votre signature.', 'assetsign'),
           'expected' => __('Signez les documents en attente. En cas de problème avec un matériel, signalez-le depuis cette page : un ticket est créé pour le service informatique.', 'assetsign'),
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
     * En tête d'une sous-page : l'encadré d'aide de la page (issue #336), puis les barres
     * d'onglets : les rubriques, puis les pages de la rubrique affichée quand elle en compte
     * plusieurs. $active est la page affichée. L'encadré n'est affiché que si l'utilisateur a le
     * droit d'ouvrir cette page (son entrée de menu est visible), en plus du contrôle de droit
     * déjà fait par la page elle-même.
     */
   public static function show(string $active): void {
       global $CFG_GLPI;

       $groups  = [];
       $current = [];
       $activeVisible = false;
      foreach (self::groups() as $key => $group) {
          $pages = [];
         foreach ($group['pages'] as $class) {
             $menu = self::menuOf($class);
            if ($menu !== null) {
               if ($class === $active) {
                   $activeVisible = true;
               }
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
       $help = self::helpTexts()[$active] ?? null;
      if ($activeVisible && $help !== null) {
          TemplateRenderer::getInstance()->display('@assetsign/_page_help.html.twig', $help);
      }
       TemplateRenderer::getInstance()->display('@assetsign/_section_nav.html.twig', [
           'groups' => $groups,
           'pages'  => $current,
       ]);
   }
}
