<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\EnvironmentalData;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Couvre le bénéfice du réemploi / "impact évité" (V3, cf. ROADMAP.md, issue #81)
 * affiché sur le Passeport matériel : désactivé par défaut, jamais une valeur
 * inventée quand l'un des trois préalables réels (durée d'amortissement
 * `Infocom::sink_time`, date de mise en service, empreinte carbone déjà saisie)
 * manque, et surtout jamais affiché tant que la durée réelle n'a pas dépassé la
 * durée d'amortissement prévue — cf. le docblock de
 * `PassportEvent::getReuseBenefit()` pour la méthodologie complète.
 */
class PassportEventReuseBenefitTest extends AssetsignTestCase
{
    private function insertInfocom(string $itemtype, int $items_id, array $fields): void
    {
        global $DB;
        $DB->insert('glpi_infocoms', array_merge([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ], $fields));
    }

    public function testDisabledByDefaultDisplaysNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reuse Disabled Default');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reuse Disabled Default');
        $this->insertInfocom('Computer', $computer->getID(), [
            'use_date'  => date('Y-m-d', strtotime('-6 years')),
            'sink_time' => 3,
        ]);
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 250.0, 'manual', 'high');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Bénéfice du réemploi', 'assetsign'), $html);
    }

    public function testEnabledWithoutSinkTimeDisplaysNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reuse No SinkTime');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
            'enable_reuse_benefit' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reuse No SinkTime');
        // use_date renseignee mais sink_time reste a 0 (valeur par defaut GLPI) :
        // aucune duree prevue connue, rien a comparer.
        $this->insertInfocom('Computer', $computer->getID(), [
            'use_date' => date('Y-m-d', strtotime('-6 years')),
        ]);
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 250.0, 'manual', 'high');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Bénéfice du réemploi', 'assetsign'), $html);
    }

    public function testEnabledWithoutEnvironmentalDataDisplaysNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reuse No Environmental');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
            'enable_reuse_benefit' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reuse No Environmental');
        $this->insertInfocom('Computer', $computer->getID(), [
            'use_date'  => date('Y-m-d', strtotime('-6 years')),
            'sink_time' => 3,
        ]);
        // Aucune empreinte carbone saisie : rien a valoriser, meme avec une duree
        // largement depassee.

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Bénéfice du réemploi', 'assetsign'), $html);
    }

    /**
     * Garde-fou le plus important de cette fonctionnalite : tant que la duree
     * reelle n'a pas depasse la duree d'amortissement prevue, il n'y a
     * litteralement rien a valoriser (le materiel est encore dans sa vie
     * "normale", pas dans une prolongation) - jamais un 0 ni une valeur
     * negative affichee.
     */
    public function testNotYetExceedingPlannedDurationDisplaysNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reuse Not Yet Exceeded');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
            'enable_reuse_benefit' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reuse Not Yet Exceeded');
        // Mis en service il y a 1 an seulement, pour une duree prevue de 3 ans.
        $this->insertInfocom('Computer', $computer->getID(), [
            'use_date'  => date('Y-m-d', strtotime('-1 year')),
            'sink_time' => 3,
        ]);
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 250.0, 'manual', 'high');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Bénéfice du réemploi', 'assetsign'), $html);
    }

    public function testExceedingPlannedDurationComputesProportionalAvoidedImpact(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reuse Exceeded');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
            'enable_reuse_benefit' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reuse Exceeded');
        // Mis en service il y a 6 ans pour une duree prevue de 3 ans : deux fois
        // la duree d'amortissement, donc ~100% de l'empreinte de fabrication
        // evitee (une prolongation d'exactement une duree d'amortissement de
        // plus = un remplacement complet evite).
        $this->insertInfocom('Computer', $computer->getID(), [
            'use_date'  => date('Y-m-d', strtotime('-6 years')),
            'sink_time' => 3,
        ]);
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 250.0, 'manual', 'high');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Bénéfice du réemploi', 'assetsign'), $html);
        $this->assertStringContainsString(__('Encore en service', 'assetsign'), $html);
        // Tolerance sur le nombre exact (annees calendaires approximees a
        // 365.25 jours dans le calcul, cf. getReuseBenefit()) : ~250 kg CO2-eq
        // attendu (100% de l'empreinte), jamais un nombre negatif ni 0.
        $this->assertMatchesRegularExpression('/2[45][0-9]\.\d{2} kg CO2-eq/', $html);
        $this->assertNoStrayNumericTextNode($html, 'Le bénéfice du réemploi doit se rendre sans fuite Twig.');
    }

    public function testDecommissionedItemUsesDecommissionDateNotToday(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reuse Decommissioned');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_environmental_passport' => 1,
            'enable_reuse_benefit' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reuse Decommissioned');
        // Mis en service il y a 10 ans, reforme il y a 4 ans (donc 6 ans de
        // service reel, pas "10 ans jusqu'a aujourd'hui") pour une duree
        // prevue de 3 ans.
        $this->insertInfocom('Computer', $computer->getID(), [
            'use_date'          => date('Y-m-d', strtotime('-10 years')),
            'sink_time'         => 3,
            'decommission_date' => date('Y-m-d', strtotime('-4 years')),
        ]);
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 250.0, 'manual', 'high');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Bénéfice du réemploi', 'assetsign'), $html);
        $this->assertStringContainsString(__('Réformé', 'assetsign'), $html);
        $this->assertStringNotContainsString(__('Encore en service', 'assetsign'), $html);
        // Duree reelle affichee doit etre ~6 ans (10 - 4), pas ~10 ans : preuve
        // que decommission_date est bien utilisee comme borne de fin, pas la
        // date du jour.
        $this->assertStringContainsString('après 6 an(s)', $html);
    }
}
