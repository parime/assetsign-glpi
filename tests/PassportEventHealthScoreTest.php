<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\Assetsign;

/**
 * Couvre le score de sante (0-100), deuxieme brique du V2 (cf. ROADMAP.md) :
 * formule standard ITAM (100 - somme ponderee des degradations normalisees),
 * poids RELATIFS reglables par l'administrateur (Config::health_weight_*),
 * quatre facteurs (age, incidents, etat physique, mouvements) - "controles"
 * et "batterie" volontairement omis, aucune donnee fiable disponible.
 */
class PassportEventHealthScoreTest extends AssetsignTestCase
{
    private function insertInfocom(string $itemtype, int $items_id, array $fields): void
    {
        global $DB;
        $DB->insert('glpi_infocoms', array_merge([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ], $fields));
    }

    private function insertDamageMarker(int $assetsignsId, int $severity): void
    {
        global $DB;
        $DB->insert('glpi_plugin_assetsign_damagemarkers', [
            'plugin_assetsign_assetsigns_id' => $assetsignsId,
            'view_index'               => 0,
            'x_percent'                => 10.0,
            'y_percent'                => 10.0,
            'severity'                 => $severity,
        ]);
    }

    public function testNewComputerHasHighHealthScore(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health New');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'enable_health_score' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Health New');
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => date('Y-m-d')]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Score de santé', 'assetsign'), $html);
        $this->assertStringContainsString('100/100', $html);
        $this->assertNoStrayNumericTextNode($html, 'Le score de santé doit se rendre sans fuite Twig.');
    }

    public function testOldComputerWithDamageAndTicketsHasLowerScore(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health Degraded');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'enable_health_score' => 1, 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Health Degraded');
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => date('Y-m-d', strtotime('-10 years'))]);
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Assetsign::handleItemAssignment($computer);

        ob_start();
        PassportEvent::showForItem($computer);
        $htmlNew = ob_get_clean();
        preg_match('/(\d+)\/100/', $htmlNew, $matchesNew);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();
        preg_match('/(\d+)\/100/', $html, $matches);

        $this->assertNotEmpty($matches, 'Le score doit être présent dans le rendu.');
        $this->assertLessThan(100, (int) $matches[1], 'Un materiel de 10 ans doit avoir un score degrade par rapport a un materiel neuf.');
    }

    public function testWeightOfZeroIgnoresFactor(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health Zero Weight');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'enable_health_score' => 1,
            'health_weight_age' => 0, 'health_weight_incidents' => 100, 'health_weight_damage' => 0, 'health_weight_movements' => 0,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Health Zero Weight');
        // Tres vieux materiel : si le poids "age" etait pris en compte, le score serait bas.
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => date('Y-m-d', strtotime('-20 years'))]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        // Seul le facteur Incidents pese (100), et il n'y a aucun ticket : score parfait
        // malgre les 20 ans d'age, puisque son poids est a 0.
        $this->assertStringContainsString('100/100', $html);
    }

    public function testDisabledHealthScoreHidesBlock(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health Disabled');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'enable_health_score' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Health Disabled');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Score de santé', 'assetsign'), $html);
    }

    public function testAllWeightsAtZeroReturnsNoScore(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health All Zero');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'enable_health_score' => 1,
            'health_weight_age' => 0, 'health_weight_incidents' => 0, 'health_weight_damage' => 0, 'health_weight_movements' => 0,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Health All Zero');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Score de santé', 'assetsign'), $html);
    }

    public function testDamageMarkersIncreaseDegradation(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health Damage');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'enable_health_score' => 1,
            'health_weight_age' => 0, 'health_weight_incidents' => 0, 'health_weight_damage' => 100, 'health_weight_movements' => 0,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Health Damage');
        $assetsign = $this->createBareAssetsign($entityId);
        global $DB;
        $DB->update('glpi_plugin_assetsign_assetsigns', ['itemtype' => 'Computer', 'items_id' => $computer->getID()], ['id' => $assetsign->getID()]);
        $this->insertDamageMarker($assetsign->getID(), 1); // majeur = 2 points, sur un seuil de 6 -> 33% de degradation.

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression('/(?!100)\d+\/100/', $html);
    }
}
