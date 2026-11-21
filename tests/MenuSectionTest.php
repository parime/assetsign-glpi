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

namespace GlpiPlugin\Assetsign\Tests;

use Glpi\Plugin\Hooks;
use GlpiPlugin\Assetsign\Accessory;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\ChecklistItem;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\CsrDashboard;
use GlpiPlugin\Assetsign\Departure;
use GlpiPlugin\Assetsign\IntegrityCheck;
use GlpiPlugin\Assetsign\Kit;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;
use GlpiPlugin\Assetsign\Menu;
use GlpiPlugin\Assetsign\MenuSection;
use GlpiPlugin\Assetsign\Movement;
use GlpiPlugin\Assetsign\PhysicalInventory;
use GlpiPlugin\Assetsign\Profile;
use GlpiPlugin\Assetsign\Renewal;
use GlpiPlugin\Assetsign\Template;
use GlpiPlugin\Assetsign\WipeAttestation;

/**
 * Issue #330 : une seule entrée Outils > AssetSign ; les écrans d'usage en sont les sous-pages
 * (options GLPI et barre d'onglets), chacun visible seulement avec son droit.
 */
final class MenuSectionTest extends AssetsignTestCase
{
    /** @var array<string, int> */
   private array $savedRights = [];

   protected function setUp(): void {
       parent::setUp();
      foreach ([Profile::RIGHT_ASSETSIGN, Profile::RIGHT_MAINTENANCE] as $right) {
          $this->savedRights[$right] = $_SESSION['glpiactiveprofile'][$right] ?? 0;
      }
   }

   protected function tearDown(): void {
      foreach ($this->savedRights as $right => $value) {
          $_SESSION['glpiactiveprofile'][$right] = $value;
      }
       parent::tearDown();
   }

   private function setRights(int $assetsign, int $maintenance): void {
       $_SESSION['glpiactiveprofile'][Profile::RIGHT_ASSETSIGN]   = $assetsign;
       $_SESSION['glpiactiveprofile'][Profile::RIGHT_MAINTENANCE] = $maintenance;
   }

   public function testTheToolsMenuHasASingleEntry(): void {
       global $PLUGIN_HOOKS;

       $this->assertSame(['tools' => [Menu::class]], $PLUGIN_HOOKS[Hooks::MENU_TOADD]['assetsign']);
   }

   public function testAllSubPagesAreOptionsOfTheEntry(): void {
       $this->setRights(ALLSTANDARDRIGHT, ALLSTANDARDRIGHT);

       $menu = Menu::getMenuContent();
       $this->assertIsArray($menu);
       $this->assertSame('AssetSign', $menu['title']);
       $this->assertSame('/plugins/assetsign/front/assetsign.php', $menu['page']);
       $this->assertSame(
           [
               Assetsign::class, Movement::class, Departure::class, Campaign::class,
               WipeAttestation::class, Maintenance::class, CsrDashboard::class, Renewal::class, IntegrityCheck::class, PhysicalInventory::class,
           ],
           array_keys($menu['options'])
       );
       // Les intitulés ne sont plus rangés sous ce menu : Configuration > Intitulés.
       $this->assertArrayNotHasKey(Template::class, $menu['options']);
   }

   public function testMaintenanceOnlyProfileSeesOnlyMaintenance(): void {
       $this->setRights(0, READ);

       $menu = Menu::getMenuContent();
       $this->assertIsArray($menu);
       $this->assertSame([Maintenance::class], array_keys($menu['options']));
       // L'entrée mène à la seule page autorisée.
       $this->assertSame('/plugins/assetsign/front/maintenance.php', $menu['page']);
   }

   public function testNoEntryWithoutAnyRight(): void {
       $this->setRights(0, 0);

       $this->assertFalse(Menu::getMenuContent());
       ob_start();
       MenuSection::show(Maintenance::class);
       $this->assertSame('', trim((string) ob_get_clean()));
   }

   public function testNavShowsSectionsAndThePagesOfTheCurrentOne(): void {
       $this->setRights(ALLSTANDARDRIGHT, ALLSTANDARDRIGHT);

       ob_start();
       MenuSection::show(Campaign::class);
       $html = (string) ob_get_clean();

       // Quatre rubriques, puis les quatre pages de la rubrique Attributions.
       $this->assertSame(4, substr_count($html, 'data-assetsign-section='));
       $this->assertSame(4, substr_count($html, 'class="nav-link'));
       $this->assertSame(4, substr_count($html, 'class="btn btn-sm'));
       $this->assertSame(1, substr_count($html, 'aria-current="page"'));
       $this->assertMatchesRegularExpression('#btn-primary"[^>]*href="[^"]*front/campaign\.php"#', $html);
       $this->assertStringContainsString(Movement::getTypeName(2), $html);
   }

   public function testSingleSectionPageHasNoSecondRow(): void {
       $this->setRights(ALLSTANDARDRIGHT, ALLSTANDARDRIGHT);

       ob_start();
       MenuSection::show(Maintenance::class);
       $html = (string) ob_get_clean();

       $this->assertSame(4, substr_count($html, 'class="nav-link'));
       $this->assertStringNotContainsString('assetsign-section-pages', $html);
   }

   public function testAssetsignOnlyProfileHasNoMaintenanceTab(): void {
       $this->setRights(READ, 0);

       ob_start();
       MenuSection::show(Renewal::class);
       $html = (string) ob_get_clean();

       $this->assertSame(3, substr_count($html, 'data-assetsign-section='));
       $this->assertStringNotContainsString('front/maintenance.php', $html);
       $this->assertStringContainsString('front/renewal.php', $html);
   }

    /**
     * Issue #336 : chaque sous-page a son encadré « À quoi sert cette page ? ».
     */
   public function testEverySubPageHasAHelpBox(): void {
       $pages = [];
      foreach (MenuSection::groups() as $group) {
          $pages = array_merge($pages, $group['pages']);
      }
       $help = MenuSection::helpTexts();
       $this->assertSame($pages, array_keys($help));
      foreach ($help as $class => $texts) {
          $this->assertMatchesRegularExpression('/^[a-z-]+$/', $texts['page'], $class);
          $this->assertNotSame('', $texts['purpose'], $class);
          $this->assertNotSame('', $texts['expected'], $class);
      }
       $this->assertSame(
           count($help),
           count(array_unique(array_column($help, 'page'))),
           'identifiants de page uniques (choix replié mémorisé par page)'
       );
   }

   public function testHelpBoxIsShownAboveTheTabs(): void {
       $this->setRights(ALLSTANDARDRIGHT, ALLSTANDARDRIGHT);

       ob_start();
       MenuSection::show(Campaign::class);
       $html = (string) ob_get_clean();

       $this->assertSame(1, substr_count($html, 'assetsign-page-help'));
       $this->assertStringContainsString('id="assetsign-help-campaigns"', $html);
       $this->assertStringContainsString("'assetsign.help.campaigns'", $html);
       $this->assertStringContainsString(
           htmlescape(MenuSection::helpTexts()[Campaign::class]['purpose']),
           $html
       );
       $this->assertLessThan(strpos($html, '<nav'), strpos($html, 'assetsign-page-help'));
   }

   public function testNoHelpBoxForAPageTheUserCannotOpen(): void {
       // Droit AssetSign sans droit Maintenance : les onglets AssetSign restent, pas l'aide de
       // la page Maintenance.
       $this->setRights(READ, 0);

       ob_start();
       MenuSection::show(Maintenance::class);
       $html = (string) ob_get_clean();

       $this->assertStringContainsString('data-assetsign-section=', $html);
       $this->assertStringNotContainsString('assetsign-page-help', $html);
   }

   public function testMyEquipmentPageHasAHelpBox(): void {
       $help = MenuSection::myEquipmentHelp();
       $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
           '@assetsign/my_signatures.html.twig',
           [
               'title'         => 'x',
               'help'          => $help,
               'rows'          => [],
               'equipment'     => [],
               'problem_types' => [],
               'can_report'    => false,
               'action'        => '/front/mysignatures.php',
               'csrf_token'    => 'x',
           ]
       );

       $this->assertStringContainsString('id="assetsign-help-my-equipment"', $html);
       $this->assertStringContainsString(htmlescape($help['expected']), $html);
   }

   public function testDropdownsBelongToTheNativeDropdownsMenu(): void {
      foreach ([Template::class, Accessory::class, Kit::class, ChecklistItem::class, MaintenanceChecklistItem::class] as $class) {
          $this->assertSame(['config', \CommonDropdown::class, $class], $class::getSectorizedDetails(), $class);
      }
   }

   public function testEntityTabLeadsToThePluginSettingsPage(): void {
       $entity = new \Entity();
       $this->assertTrue($entity->getFromDB(0));

       ob_start();
       Config::displayTabContentForItem($entity);
       $html = (string) ob_get_clean();

       $this->assertStringContainsString('/plugins/assetsign/front/config.php?entities_id=0', $html);
       // Plus de formulaire de réglages dans l'onglet de l'entité.
       $this->assertStringNotContainsString('assetsign-config-form', $html);
   }
}
