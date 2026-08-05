<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\PassportEvent;
use GlpiPlugin\Remise\Remise;

/**
 * Couvre la fusion des dates Infocom dans la frise du Passeport materiel (cf.
 * ROADMAP.md, tableau V1) : jamais copiees dans glpi_plugin_remise_events,
 * simple ajout d'affichage calcule a chaque rendu de showForItem().
 */
class PassportEventInfocomTest extends RemiseTestCase
{
    private function insertInfocom(string $itemtype, int $items_id, array $fields): void
    {
        global $DB;
        $DB->insert('glpi_infocoms', array_merge([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ], $fields));
    }

    public function testShowForItemMergesInfocomDatesIntoTimeline(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Merge');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Merge');
        $this->insertInfocom('Computer', $computer->getID(), [
            'buy_date' => '2019-06-01',
            'use_date' => '2019-07-01',
            'value'    => 999.90,
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Achat', 'remise'), $html);
        $this->assertStringContainsString(__('Mise en service', 'remise'), $html);
        $this->assertStringContainsString('999,90', $html);
        $this->assertNoStrayNumericTextNode($html, 'La frise avec dates Infocom doit se rendre sans fuite Twig.');
    }

    public function testShowForItemComputesWarrantyEndDate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Warranty');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Warranty');
        $this->insertInfocom('Computer', $computer->getID(), [
            'warranty_date'     => '2020-01-01',
            'warranty_duration' => 24,
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Début de garantie', 'remise'), $html);
        $this->assertStringContainsString(__('Fin de garantie', 'remise'), $html);
    }

    public function testShowForItemHidesInfocomDatesWhenDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Disabled');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 0, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Disabled');
        // order_date (Commande) plutot que buy_date (Achat) : ce dernier apparait
        // aussi dans la fiche d'identite, independamment de show_infocom_dates -
        // ce test verifie uniquement la frise, pas la fiche d'identite.
        $this->insertInfocom('Computer', $computer->getID(), ['order_date' => '2019-06-01']);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Commande', 'remise'), $html);
    }

    public function testInfocomPseudoEventsDoNotAffectLivesCount(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Lives');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4], 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Lives');
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => '2019-06-01', 'use_date' => '2019-07-01']);
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Remise::handleItemAssignment($computer);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        // 1 seule "vie" attendue (1 seule vraie attribution) malgre 2 dates Infocom en plus dans la frise.
        $this->assertMatchesRegularExpression('/Vies du matériel.*badge[^>]*>\s*1\s*</s', $html);
    }

    public function testShowForItemHandlesMissingInfocomGracefully(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Missing Row');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        // Aucune ligne glpi_infocoms creee pour ce materiel (jamais renseignee).
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Missing Row');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Aucun événement enregistré pour le moment.', 'remise'), $html);
        $this->assertNoStrayNumericTextNode($html, 'L\'absence totale d\'Infocom ne doit jamais faire planter la frise.');
    }

    public function testShowForItemHandlesEmptyInfocomGracefully(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Empty Row');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Empty Row');
        // Ligne glpi_infocoms existante (ex: creee automatiquement par GLPI a la
        // premiere visite de l'onglet Infocom) mais aucune date renseignee.
        $this->insertInfocom('Computer', $computer->getID(), []);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Aucun événement enregistré pour le moment.', 'remise'), $html);
    }

    public function testShowForItemHandlesPartialInfocomIndependently(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Partial');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Partial');
        // Seule la date de reforme est connue - achat/commande/livraison/mise en
        // service/garantie totalement absents (materiel dont on ne connait que la fin de vie).
        $this->insertInfocom('Computer', $computer->getID(), ['decommission_date' => '2024-01-15 00:00:00']);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Réforme', 'remise'), $html);
        $this->assertStringNotContainsString(__('Achat', 'remise'), $html);
        $this->assertStringNotContainsString(__('Commande', 'remise'), $html);
        $this->assertStringNotContainsString(__('Début de garantie', 'remise'), $html);
    }

    public function testShowForItemDoesNotCrashWhenNoEventTypeIsVisible(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Empty Visible Types');
        // Administrateur ayant decoche TOUS les types dans Configuration > Passeport
        // materiel : passport_visible_types devient [] (aucune case cochee n'est
        // soumise) - ne doit jamais faire planter la requete ("Empty IN").
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [], 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Empty Visible Types');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Remise::handleItemAssignment($computer);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Aucun événement enregistré pour le moment.', 'remise'), $html);
    }

    public function testShowForItemSkipsInfocomWhenItemtypeCannotApply(): void
    {
        global $CFG_GLPI;

        $entityId = $this->createTestEntity(0, 'PHPUnit Infocom Not Applicable');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'show_infocom_dates' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Infocom Not Applicable');
        $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => '2019-06-01']);

        // Simule un reglage coeur GLPI ou Computer a ete retire de la liste des
        // itemtypes qui supportent l'Infocom (Infocom::canApplyOn()) - meme si une
        // ligne glpi_infocoms existe deja (reglage change apres coup), elle ne
        // doit plus jamais etre lue.
        $previousInfocomTypes = $CFG_GLPI['infocom_types'];
        $CFG_GLPI['infocom_types'] = array_values(array_diff($CFG_GLPI['infocom_types'], ['Computer']));

        try {
            ob_start();
            PassportEvent::showForItem($computer);
            $html = ob_get_clean();
        } finally {
            $CFG_GLPI['infocom_types'] = $previousInfocomTypes;
        }

        $this->assertStringNotContainsString(__('Achat', 'remise'), $html);
    }
}
