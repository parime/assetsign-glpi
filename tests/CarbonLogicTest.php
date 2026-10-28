<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\CarbonLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #228 : liaison avec le plugin Carbon (règles pures, aucune base de données).
 */
final class CarbonLogicTest extends TestCase
{
   public function testManualFootprintWinsOverCarbon(): void {
       $this->assertSame(['value' => 210.0, 'source' => CarbonLogic::SOURCE_MANUAL], CarbonLogic::footprint(210.0, 350.5));
       $this->assertSame(['value' => 350.5, 'source' => CarbonLogic::SOURCE_CARBON], CarbonLogic::footprint(null, 350.5));
       $this->assertSame(['value' => 0.0, 'source' => CarbonLogic::SOURCE_MANUAL], CarbonLogic::footprint(0.0, 350.5), 'saisie à 0 volontaire');
   }

   public function testNoFootprintIsNeverEstimated(): void {
       $this->assertNull(CarbonLogic::footprint(null, null));
       $this->assertNull(CarbonLogic::footprint(null, 0.0), 'Carbon n\'a rien calculé');
   }

   public function testEnergyDegradation(): void {
       $this->assertNull(CarbonLogic::energyDegradation(null), 'sans donnée Carbon, facteur ignoré');
       $this->assertSame(0, CarbonLogic::energyDegradation(0.0));
       $this->assertSame(8, CarbonLogic::energyDegradation(0.08), 'portable');
       $this->assertSame(50, CarbonLogic::energyDegradation(0.5));
       $this->assertSame(100, CarbonLogic::energyDegradation(3.0), 'plafonné à 100');
   }

   public function testGramsToKg(): void {
       $this->assertSame(250.53, CarbonLogic::gramsToKg(250530.0));
       $this->assertNull(CarbonLogic::gramsToKg(null));
   }
}
