<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\CsrDashboard;
use GlpiPlugin\Assetsign\MenuSection;
use GlpiPlugin\Assetsign\Movement;
use GlpiPlugin\Assetsign\Renewal;

/**
 * Menu Outils en trois rubriques : les sous-pages sont dans l'entrée de leur rubrique (fil
 * d'Ariane) et dans sa barre d'onglets.
 */
final class MenuSectionTest extends AssetsignTestCase
{
   public function testSubPagesAreOptionsOfTheirSection(): void {
       $attributions = Assetsign::getMenuContent();
      foreach (MenuSection::sections()[Assetsign::class] as $child) {
          $this->assertArrayHasKey($child, $attributions['options']);
      }
       // Les sous-pages déjà présentes (gabarits, accessoires, points de contrôle) restent.
       $this->assertArrayHasKey(\GlpiPlugin\Assetsign\Template::class, $attributions['options']);

       $pilotage = CsrDashboard::getMenuContent();
       $this->assertSame(__('Pilotage du parc', 'assetsign'), $pilotage['title']);
       $this->assertArrayHasKey(Renewal::class, $pilotage['options']);
   }

   public function testSectionNavMarksTheDisplayedPage(): void {
       ob_start();
       MenuSection::showSection(Assetsign::class, Campaign::class);
       $html = (string) ob_get_clean();

       $this->assertSame(4, substr_count($html, 'class="nav-link'));
       $this->assertSame(1, substr_count($html, 'nav-link active'));
       $this->assertMatchesRegularExpression('#nav-link active"[^>]*href="[^"]*front/campaign\.php"#', $html);
       $this->assertStringContainsString(Movement::getTypeName(2), $html);
   }

   public function testNoNavWithoutTheRightToSeeTheSection(): void {
       $saved = $_SESSION['glpiactiveprofile'][\GlpiPlugin\Assetsign\Profile::RIGHT_ASSETSIGN] ?? 0;
       $_SESSION['glpiactiveprofile'][\GlpiPlugin\Assetsign\Profile::RIGHT_ASSETSIGN] = 0;
      try {
          ob_start();
          MenuSection::showSection(CsrDashboard::class, Renewal::class);
          $this->assertSame('', (string) ob_get_clean());
      } finally {
          $_SESSION['glpiactiveprofile'][\GlpiPlugin\Assetsign\Profile::RIGHT_ASSETSIGN] = $saved;
      }
   }
}
