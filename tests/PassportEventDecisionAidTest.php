<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Couvre le module d'aide à la décision (troisième brique du V2, cf.
 * ROADMAP.md — "Module d'aide à la décision (moteur de règles simple...)",
 * issue #79) : moteur de règles à seuils (pas de machine learning) sur les
 * deux indicateurs V2 déjà livrés (score de santé, valeur résiduelle),
 * chaque règle affichant son libellé ET sa raison explicite, jamais une
 * recommandation inventée quand l'indicateur source est lui-même
 * indisponible.
 */
class PassportEventDecisionAidTest extends AssetsignTestCase
{
   private function insertInfocom(string $itemtype, int $items_id, array $fields): void {
       global $DB;
       $DB->insert('glpi_infocoms', array_merge([
           'itemtype' => $itemtype,
           'items_id' => $items_id,
       ], $fields));
   }

    /**
     * `Réévaluer l'usage` contient une apostrophe : passport_tab.html.twig
     * l'affiche via `{{ reco.label }}`, échappée en HTML par Twig (auto-echap
     * par defaut) - l'apostrophe brute ne se retrouve donc JAMAIS telle
     * quelle dans le rendu (`&#039;` a la place). Meme transformation ici
     * (htmlspecialchars) pour comparer des chaines equivalentes, plutot que
     * de chercher une apostrophe brute qui ne peut jamais apparaitre dans le
     * HTML genere.
     */
   private function htmlLabel(string $label): string {
       return htmlspecialchars($label, ENT_QUOTES);
   }

   public function testLowHealthScoreTriggersReplacementRecommendation(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid Health Low');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 1,
           // Seuil tres eleve : n'importe quel score imparfait (materiel age)
           // passe en dessous, sans avoir a construire un scenario de degradation
           // complexe (tickets, dommages...). health_score_good_threshold releve
           // egalement : Config::upsertForEntity() plafonne toujours le seuil
           // "Vigilance" au seuil "Bon" (jamais l'inverse), un warning_threshold
           // de 99 sans releve resterait donc silencieusement ecrete a 70 (le
           // defaut de good_threshold).
           'health_score_good_threshold'    => 100,
           'health_score_warning_threshold' => 99,
           'enable_residual_value' => 0,
           'enable_decision_aid' => 1,
       ]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid Health Low');
       $this->insertInfocom('Computer', $computer->getID(), ['buy_date' => date('Y-m-d', strtotime('-10 years'))]);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringContainsString(__('Prévoir un remplacement', 'assetsign'), $html);
       // Passe par __()/sprintf() plutot qu'un litteral francais brut : la
       // suite tourne parfois avec l'anglais comme langue active (cf. CI),
       // un texte source fige echouerait alors a tort.
       $this->assertStringContainsString(sprintf(__('score de santé faible : %d/100', 'assetsign'), 70), $html);
       $this->assertStringNotContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
       $this->assertNoStrayNumericTextNode($html, 'Le module d\'aide à la décision doit se rendre sans fuite Twig.');
   }

   public function testLowResidualValueTriggersReassessRecommendation(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid Residual Low');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 0,
           'enable_residual_value' => 1,
           'residual_value_duration_months' => 12,
           'residual_value_low_threshold_percent' => 50,
           'enable_decision_aid' => 1,
       ]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid Residual Low');
       // Achat il y a 10 ans, tres largement au-dela de la duree de vie utile de
       // 12 mois configuree ci-dessus : valeur residuelle a 0, donc 0% du prix
       // d'achat, largement sous le seuil de 50% configure.
       $this->insertInfocom('Computer', $computer->getID(), [
           'buy_date' => date('Y-m-d', strtotime('-10 years')),
           'value'    => 1000,
       ]);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
       // Idem ci-dessus (testLowHealthScoreTriggersReplacementRecommendation) :
       // __()/sprintf() plutot qu'un litteral francais brut.
       $this->assertStringContainsString(
           $this->htmlLabel(sprintf(__('valeur résiduelle faible : %d %% du prix d\'achat', 'assetsign'), 0)),
           $html
       );
       $this->assertStringNotContainsString(__('Prévoir un remplacement', 'assetsign'), $html);
       $this->assertNoStrayNumericTextNode($html, 'Le module d\'aide à la décision doit se rendre sans fuite Twig.');
   }

   public function testBothRulesTriggeredShowBothRecommendations(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid Both');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 1,
           'health_score_good_threshold'    => 100,
           'health_score_warning_threshold' => 99,
           'enable_residual_value' => 1,
           'residual_value_duration_months' => 12,
           'residual_value_low_threshold_percent' => 50,
           'enable_decision_aid' => 1,
       ]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid Both');
       $this->insertInfocom('Computer', $computer->getID(), [
           'buy_date' => date('Y-m-d', strtotime('-10 years')),
           'value'    => 1000,
       ]);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       // Un vrai outil d'aide a la decision ne doit jamais cacher un facteur
       // contributif au profit d'un autre : les deux recommandations doivent
       // apparaitre simultanement.
       $this->assertStringContainsString(__('Prévoir un remplacement', 'assetsign'), $html);
       $this->assertStringContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
   }

   public function testNothingDisplayedWhenBothIndicatorsAreHealthy(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid Healthy');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 1,
           'enable_residual_value' => 1,
           'enable_decision_aid' => 1,
       ]);
       // Materiel tout neuf : score de sante a 100, achat tres recent (valeur
       // residuelle proche de 100% du prix d'achat) - aucune regle ne doit se
       // declencher.
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid Healthy');
       $this->insertInfocom('Computer', $computer->getID(), [
           'buy_date' => date('Y-m-d'),
           'value'    => 1000,
       ]);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringNotContainsString(__('Prévoir un remplacement', 'assetsign'), $html);
       $this->assertStringNotContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
       $this->assertStringNotContainsString(__('Aide à la décision', 'assetsign'), $html);
   }

   public function testMissingPurchaseValueNeverInventsResidualRecommendation(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid No Purchase');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 0,
           'enable_residual_value' => 1,
           'enable_decision_aid' => 1,
       ]);
       // Aucun Infocom du tout : ni prix d'achat ni date connus - le calcul de
       // valeur residuelle est deja `null` (cf. PassportEventResidualValueTest),
       // la regle "Reevaluer l'usage" ne doit donc jamais se declencher, meme si
       // le module est active.
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid No Purchase');

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringNotContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
       $this->assertStringNotContainsString(__('Aide à la décision', 'assetsign'), $html);
   }

   public function testManualResidualValueWithKnownPurchasePriceCanStillTriggerRecommendation(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid Manual Residual');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 0,
           'enable_residual_value' => 1,
           'residual_value_low_threshold_percent' => 50,
           'enable_decision_aid' => 1,
       ]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid Manual Residual');
       $this->insertInfocom('Computer', $computer->getID(), [
           'buy_date' => date('Y-m-d', strtotime('-30 days')),
           'value'    => 1000,
       ]);
       \GlpiPlugin\Assetsign\ResidualValue::upsertForItem('Computer', $computer->getID(), 50.0); // 5% du prix d'achat.

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
   }

   public function testDecisionAidDisabledHidesRecommendationsEvenWhenTriggered(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit DecisionAid Disabled');
       Config::upsertForEntity($entityId, [
           'enable_passport' => 1,
           'passport_visible_types' => [0, 1, 2, 3, 4],
           'enable_health_score' => 1,
           'health_score_good_threshold'    => 100,
           'health_score_warning_threshold' => 99,
           'enable_residual_value' => 1,
           'residual_value_duration_months' => 12,
           'residual_value_low_threshold_percent' => 50,
           'enable_decision_aid' => 0,
       ]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC DecisionAid Disabled');
       $this->insertInfocom('Computer', $computer->getID(), [
           'buy_date' => date('Y-m-d', strtotime('-10 years')),
           'value'    => 1000,
       ]);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringNotContainsString(__('Prévoir un remplacement', 'assetsign'), $html);
       $this->assertStringNotContainsString($this->htmlLabel(__('Réévaluer l\'usage', 'assetsign')), $html);
       $this->assertStringNotContainsString(__('Aide à la décision', 'assetsign'), $html);
   }
}
