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

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\Assetsign;

/**
 * Couvre les indicateurs temporels (age, temps utilise, temps en stock, duree
 * par "vie") en tete du Passeport materiel (cf. ROADMAP.md, V2). Calcules a
 * l'affichage a partir de donnees deja existantes (Infocom, timeline
 * d'evenements) : aucune nouvelle table, jamais de valeur inventee.
 */
class PassportEventTemporalIndicatorsTest extends AssetsignTestCase
{
    use ForcesEnglishLanguage;

    private function insertInfocom(string $itemtype, int $items_id, array $fields): void
    {
        global $DB;
        $DB->insert('glpi_infocoms', array_merge([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ], $fields));
    }

    public function testAgeUsesInfocomBuyDateWhenAvailable(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Temporal Buy Date');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Temporal Buy Date');
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => date('Y-m-d', strtotime('-400 days'))]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        // Texte composé « durée + source » sans identifiant stable : langue forcée en
        // anglais (trait ForcesEnglishLanguage, issue #397), comparé à la chaîne littérale.
        $this->assertStringContainsString('since purchase', $html);
        $this->assertStringContainsString('1 year', $html);
    }

    public function testAgeFallsBackToDateCreationWithoutInfocom(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Temporal No Infocom');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        // Aucune ligne Infocom : repli sur date_creation (toujours disponible).
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Temporal No Infocom');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('since entering GLPI', $html);
    }

    public function testUsedAndStockDurationsAreComputedFromLives(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Temporal Used Stock');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Temporal Used Stock');
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => date('Y-m-d', strtotime('-200 days'))]);
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Assetsign::handleItemAssignment($computer);

        // Recule la date de l'attribution : une vie commencee "aujourd'hui" dure 0
        // jour, ce qui masquerait volontairement "Temps utilise" (cf. getIdentityCard()).
        $DB->update(PassportEvent::getTable(), ['date' => date('Y-m-d H:i:s', strtotime('-100 days'))], [
            'itemtype' => 'Computer', 'items_id' => $computer->getID(),
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-assetsign-identity="used-duration"', $html);
        $this->assertStringContainsString('data-assetsign-identity="stock-duration"', $html);
        $this->assertNoStrayNumericTextNode($html, 'Les indicateurs temporels doivent se rendre sans fuite Twig.');
    }

    public function testDoesNotCrashWithoutAnyDateSource(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Temporal No Date');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Temporal No Date');
        // date_creation est toujours renseignee par CommonDBTM::add() en conditions
        // reelles ; ce test verifie juste l'absence de plantage si jamais elle
        // etait absente (ex: donnee importee directement en base).
        global $DB;
        $DB->update('glpi_computers', ['date_creation' => null], ['id' => $computer->getID()]);
        $computer->getFromDB($computer->getID());

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('data-assetsign-identity="age"', $html);
        $this->assertNoStrayNumericTextNode($html, 'Sans aucune source de date, la frise ne doit jamais planter.');
    }

    public function testLifeDisplaysHumanReadableDuration(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Temporal Life Duration');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Temporal Life Duration');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Assetsign::handleItemAssignment($computer);

        $lives = PassportEvent::getLivesForItem('Computer', $computer->getID());
        $this->assertCount(1, $lives);
        $this->assertArrayHasKey('duration', $lives[0]);
        $this->assertArrayHasKey('duration_days', $lives[0]);
        $this->assertSame(0, $lives[0]['duration_days']); // attribution du jour meme : 0 jour ecoule.
    }
}
