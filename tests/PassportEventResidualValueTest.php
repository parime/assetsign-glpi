<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\ResidualValue;

/**
 * Couvre la valeur résiduelle (V2, cf. ROADMAP.md — "Valeur résiduelle
 * (linéaire / durée personnalisable / saisie manuelle)", issue #77) affichée
 * en tête du Passeport matériel : calcul linéaire à partir d'Infocom, durée
 * utile réglable par entité, saisie manuelle toujours prioritaire, aucune
 * valeur inventée quand le prix d'achat est inconnu.
 */
class PassportEventResidualValueTest extends AssetsignTestCase
{
    private function insertInfocom(string $itemtype, int $items_id, array $fields): void
    {
        global $DB;
        $DB->insert('glpi_infocoms', array_merge([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ], $fields));
    }

    public function testLinearFormulaComputesProportionalValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Residual Linear');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_residual_value' => 1,
            'residual_value_duration_months' => 60,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Residual Linear');
        // Achat il y a 30 mois (la moitie de la duree de vie utile de 60 mois
        // configuree ci-dessus) : valeur residuelle attendue proche de la moitie
        // du prix d'achat (1000 -> ~500).
        $this->insertInfocom('Computer', $computer->getID(), [
            'buy_date' => date('Y-m-d', strtotime('-913 days')), // 30 * 30.44 jours
            'value'    => 1000,
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Valeur résiduelle (estimée)', 'assetsign'), $html);
        // Tolerance sur le montant exact (le "temps ecoule depuis" glisse d'une
        // fraction de jour entre la preparation du fixture et l'affichage) :
        // on verifie l'ordre de grandeur (moitie du prix d'achat) plutot qu'un
        // nombre pile, en cherchant le prefixe "49" ou "50" (49x,xx a 50x,xx €).
        $this->assertMatchesRegularExpression('/4[5-9][0-9],\d{2}\s?€|50[0-9],\d{2}\s?€/', $html);
        $this->assertNoStrayNumericTextNode($html, 'La valeur résiduelle doit se rendre sans fuite Twig.');
    }

    public function testValueNeverGoesNegativeForMaterialOlderThanUsefulLife(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Residual Floor');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_residual_value' => 1,
            'residual_value_duration_months' => 12,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Residual Floor');
        // Achat il y a 10 ans, largement au-dela des 12 mois de duree de vie utile.
        $this->insertInfocom('Computer', $computer->getID(), [
            'buy_date' => date('Y-m-d', strtotime('-10 years')),
            'value'    => 1000,
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        // Extrait uniquement la petite portion de HTML entre le libelle et le
        // symbole monetaire (pas tout le document, qui contient forcement des
        // tirets ailleurs - classes CSS, hrefs...) pour verifier l'ABSENCE d'un
        // signe moins juste devant le montant affiche.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(__('Valeur résiduelle (estimée)', 'assetsign'), '/') . '[^€]*?0,00\s?€/',
            $html,
            'Un matériel plus vieux que sa durée de vie utile doit afficher 0,00 €, jamais une valeur négative.'
        );
        $this->assertNoStrayNumericTextNode($html, 'Un matériel plus vieux que sa durée de vie utile ne doit jamais afficher une valeur négative.');
    }

    public function testNothingDisplayedWithoutKnownPurchaseValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Residual No Infocom');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_residual_value' => 1,
        ]);
        // Aucun Infocom du tout : pas de prix d'achat connu, jamais de valeur inventee.
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Residual No Infocom');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Valeur résiduelle (estimée)', 'assetsign'), $html);
        $this->assertStringNotContainsString(__('Valeur résiduelle (saisie manuelle)', 'assetsign'), $html);
    }

    public function testManualOverrideTakesPrecedenceOverComputedValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Residual Manual');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_residual_value' => 1,
            'residual_value_duration_months' => 60,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Residual Manual');
        // Prix d'achat connu (calcul automatique possible) ET une saisie manuelle :
        // la saisie manuelle doit gagner, jamais un simple repli en cas d'echec.
        $this->insertInfocom('Computer', $computer->getID(), [
            'buy_date' => date('Y-m-d', strtotime('-30 days')),
            'value'    => 1000,
        ]);
        ResidualValue::upsertForItem('Computer', $computer->getID(), 42.50);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Valeur résiduelle (saisie manuelle)', 'assetsign'), $html);
        $this->assertStringNotContainsString(__('Valeur résiduelle (estimée)', 'assetsign'), $html);
        $this->assertStringContainsString('42,50', $html);
    }

    public function testZeroDurationIsGuardedAgainstDivisionByZero(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Residual Zero Duration');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_residual_value' => 1,
        ]);
        // upsertForEntity() applique un plancher de 1 (cf. Config::upsertForEntity()) :
        // une duree de 0 ne peut donc survenir qu'a partir d'une ligne deja en base
        // (import direct, tres vieille installation) - ecrite directement ici pour
        // couvrir ce cas malgre tout, le calcul doit rester defensif independamment
        // de la sanitization du formulaire.
        $DB->update(Config::getTable(), ['residual_value_duration_months' => 0], ['entities_id' => $entityId]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Residual Zero Duration');
        $this->insertInfocom('Computer', $computer->getID(), [
            'buy_date' => date('Y-m-d', strtotime('-30 days')),
            'value'    => 1000,
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Valeur résiduelle (estimée)', 'assetsign'), $html);
        $this->assertNoStrayNumericTextNode($html, 'Une durée de vie utile à zéro ne doit jamais provoquer de division par zéro ni de plantage.');
    }

    public function testDisabledFeatureDisplaysNothingEvenWithKnownPurchaseValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Residual Disabled');
        Config::upsertForEntity($entityId, [
            'enable_passport' => 1,
            'passport_visible_types' => [0, 1, 2, 3, 4],
            'enable_residual_value' => 0,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Residual Disabled');
        $this->insertInfocom('Computer', $computer->getID(), [
            'buy_date' => date('Y-m-d', strtotime('-30 days')),
            'value'    => 1000,
        ]);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Valeur résiduelle (estimée)', 'assetsign'), $html);
        // Fonctionnalite desactivee : le formulaire de saisie manuelle ne doit pas
        // non plus apparaitre (cf. residual_value_enabled dans passport_tab.html.twig).
        $this->assertStringNotContainsString(__('Valeur résiduelle manuelle', 'assetsign'), $html);
    }
}
