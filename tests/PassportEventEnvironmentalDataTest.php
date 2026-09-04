<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\EnvironmentalData;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Couvre le passeport environnemental (V3, cf. ROADMAP.md, issue #80) affiché
 * sur le Passeport matériel : désactivé par défaut, jamais de valeur affichée
 * tant que rien n'a été saisi manuellement (aucun calcul automatique dans
 * cette version, cf. le docblock d'EnvironmentalData pour la décision
 * documentée), saisie manuelle affichée avec sa source/son niveau de
 * confiance/sa date une fois renseignée.
 */
class PassportEventEnvironmentalDataTest extends AssetsignTestCase
{
    public function testDisabledByDefaultDisplaysNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Environmental Disabled Default');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Environmental Disabled Default');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Empreinte environnementale (fabrication)', 'assetsign'), $html);
    }

    public function testEnabledWithoutAnySavedDataShowsExplicitAbsence(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Environmental Enabled Empty');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Environmental Enabled Empty');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Empreinte environnementale (fabrication)', 'assetsign'), $html);
        // Jamais une valeur inventee : le message d'absence explicite doit
        // apparaitre, jamais un chiffre (0, ou une estimation quelconque).
        $this->assertStringContainsString(__('Empreinte de fabrication non renseignée.', 'assetsign'), $html);
        $this->assertStringNotContainsString('kg CO2-eq', $html);
    }

    public function testManualValueDisplaysWithSourceConfidenceAndDate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Environmental Enabled Filled');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Environmental Enabled Filled');
        EnvironmentalData::upsertForItem(
            'Computer',
            $computer->getID(),
            156.75,
            EnvironmentalData::SOURCE_MANUFACTURER,
            EnvironmentalData::CONFIDENCE_HIGH
        );

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('156.75', $html);
        $this->assertStringContainsString('kg CO2-eq', $html);
        $this->assertStringContainsString(EnvironmentalData::getSourceLabels()[EnvironmentalData::SOURCE_MANUFACTURER], $html);
        $this->assertStringContainsString(EnvironmentalData::getConfidenceLabels()[EnvironmentalData::CONFIDENCE_HIGH], $html);
        $this->assertStringNotContainsString(__('Empreinte de fabrication non renseignée.', 'assetsign'), $html);
    }

    public function testEditFormOnlyVisibleWithUpdateRight(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Environmental Form Visibility');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Environmental Form Visibility');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        // L'utilisateur de test PHPUnit a le droit UPDATE (cf. AssetsignTestCase) :
        // le formulaire de saisie doit donc apparaitre.
        $this->assertStringContainsString('front/environmentaldata.form.php', $html);
    }
}
