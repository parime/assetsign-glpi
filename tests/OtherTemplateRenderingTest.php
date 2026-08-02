<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\Maintenance;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Template;
use Session;

/**
 * Complete TemplateRenderingTest (remise_tab/remise_form) : rendu reel des
 * AUTRES gabarits d'administration du plugin, capture via ob_start()/
 * ob_get_clean() autour de la vraie methode d'affichage (display() echo
 * directement, ces classes n'exposent pas de variante render()) — exercice le
 * chemin de code exact utilise en production, pas une reconstruction manuelle
 * du contexte qui pourrait diverger silencieusement de la vraie methode.
 *
 * Meme garde-fou que TemplateRenderingTest (aucun entier isole de 5+ chiffres
 * entre deux balises) : aucun de ces gabarits n'utilise {{ call(...) }}
 * aujourd'hui (verifie par grep avant d'ecrire ce test), mais si l'un d'eux
 * en gagnait un plus tard sans passer par {% do call(...) %}, ce test
 * l'attraperait sans modification.
 */
class OtherTemplateRenderingTest extends RemiseTestCase
{
    private function assertNoStrayNumericTextNode(string $html, string $message): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*\d{5,}\s*</',
            $html,
            $message . " (recherche d'un entier de 5+ chiffres isole entre deux balises, signature d'une valeur de retour de call() imprimee par erreur)"
        );
    }

    /**
     * showForm() -> initForm() -> check() exige a la fois un droit
     * (CREATE/READ selon le cas, couvert par Session::callAsSystem()) ET un
     * acces a l'entite de l'item (checkEntity() -> Session::
     * haveAccessToEntity(), qui lit directement $_SESSION['glpiactiveentities']
     * SANS consulter le bypass de callAsSystem()) — sans ce deuxieme volet,
     * check() echoue quand meme avec "no access to entity" malgre le droit
     * accorde. Restaure l'etat de session precedent apres coup : ces tests
     * partagent le meme process PHPUnit que les autres, une session laissee
     * modifiee polluerait les tests suivants.
     */
    private function withEntityAccessAndBypassedRights(int $entitiesId, callable $fn): string
    {
        $previousActiveEntities = $_SESSION['glpiactiveentities'] ?? null;
        $_SESSION['glpiactiveentities'] = [$entitiesId];

        try {
            return Session::callAsSystem($fn);
        } finally {
            if ($previousActiveEntities === null) {
                unset($_SESSION['glpiactiveentities']);
            } else {
                $_SESSION['glpiactiveentities'] = $previousActiveEntities;
            }
        }
    }

    public function testConfigFormRenders(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit OtherTemplates Config');

        ob_start();
        Config::showConfigForm($entityId);
        $html = ob_get_clean();

        $this->assertStringContainsString('Nom de l', $html, "Le formulaire de configuration doit s'afficher.");
        $this->assertNoStrayNumericTextNode($html, 'config_form.html.twig');
    }

    public function testTemplateFormRendersForNewTemplate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit OtherTemplates Template');

        $html = $this->withEntityAccessAndBypassedRights($entityId, static function () use ($entityId): string {
            $template = new Template();
            ob_start();
            $template->showForm(-1, ['entities_id' => $entityId]);
            return ob_get_clean();
        });

        $this->assertNoStrayNumericTextNode($html, 'template_form.html.twig (nouveau gabarit)');
    }

    public function testMaintenanceTabRendersForItem(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit OtherTemplates MaintenanceTab');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC OtherTemplates MaintTab');

        ob_start();
        Maintenance::showForItem($computer);
        $html = ob_get_clean();

        $this->assertNoStrayNumericTextNode($html, 'maintenance_tab.html.twig');
    }

    public function testMaintenanceFormRendersForExistingRecord(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit OtherTemplates MaintenanceForm');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC OtherTemplates MaintForm');

        $maintenanceId = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], 'Commentaire de test PHPUnit.');

        $html = $this->withEntityAccessAndBypassedRights($entityId, static function () use ($maintenanceId): string {
            $maintenance = new Maintenance();
            $maintenance->getFromDB($maintenanceId);
            ob_start();
            $maintenance->showForm($maintenanceId);
            return ob_get_clean();
        });

        $this->assertNoStrayNumericTextNode($html, 'maintenance_form.html.twig');
    }

    public function testMaintenanceCreateFormRendersWhenAuthorized(): void
    {
        // showCreateForm() retourne sans rien afficher si l'appelant n'a pas
        // le droit Maintenance::CREATE (cf. Maintenance.php) : contourne par
        // Session::callAsSystem() plutot que de construire un vrai profil
        // actif complet, hors de portee d'un test unitaire cible.
        $html = Session::callAsSystem(static function (): string {
            ob_start();
            Maintenance::showCreateForm();
            return ob_get_clean();
        });

        $this->assertNoStrayNumericTextNode($html, 'maintenance_create.html.twig');
    }
}
