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
        // "kg CO2-eq" reste legitimement present dans le LABEL du formulaire de
        // saisie (toujours affiche pour un utilisateur habilite, cf. can_backfill) -
        // seule l'absence du bouton "Effacer" (n'existe que quand une valeur est
        // deja enregistree) prouve qu'aucun chiffre n'est affiche.
        $this->assertStringContainsString(__('Empreinte de fabrication non renseignée.', 'assetsign'), $html);
        $this->assertStringNotContainsString(__('Effacer', 'assetsign'), $html);
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

    /**
     * Regression : upsertForItem(..., null, null, null) conserve la ligne
     * (cf. son docblock) - les 3 champs sont remis a null mais la ligne
     * existe toujours. L'affichage doit se baser sur la VALEUR
     * (carbon_footprint_manufacturing), jamais sur la simple presence de la
     * ligne, sous peine d'afficher un bloc "rempli" vide (ex: "kg CO2-eq"
     * sans aucun chiffre devant) apres un effacement.
     */
    public function testClearedDataDisplaysExplicitAbsenceAgain(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Environmental Cleared');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Environmental Cleared');
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 156.75, EnvironmentalData::SOURCE_MANUFACTURER, EnvironmentalData::CONFIDENCE_HIGH);
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), null, null, null);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Empreinte de fabrication non renseignée.', 'assetsign'), $html);
        $this->assertStringNotContainsString('156.75', $html);
        // "kg CO2-eq" reste legitimement present dans le LABEL du champ de
        // saisie (cf. templates/passport_tab.html.twig) - seule la ligne
        // d'AFFICHAGE ("Empreinte de fabrication : X kg CO2-eq") ne doit plus
        // apparaitre, verifiee via l'absence du bouton "Effacer" ci-dessous
        // (n'existe que quand `environmental` est non nul, meme condition
        // Twig que le bloc d'affichage).
        $this->assertStringNotContainsString(__('Effacer', 'assetsign'), $html);
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
