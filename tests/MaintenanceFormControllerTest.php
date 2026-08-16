<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\MaintenanceFormController;
use GlpiPlugin\Assetsign\Maintenance;
use InvalidArgumentException;

/**
 * Couvre le dispatch de l'action "create" de front/maintenance.form.php
 * desormais extrait dans MaintenanceFormController — meme motivation que
 * AssetsignFormControllerTest (cf. ROADMAP.md, point tests des front/*.php).
 * Couvre en particulier la validation d'itemtype/de materiel cible, seule
 * vraie logique inline qui existait auparavant dans le front (le reste
 * delegue directement a Maintenance::createWithChecklist(), deja teste par
 * MaintenanceTest.php).
 */
class MaintenanceFormControllerTest extends AssetsignTestCase
{
    public function testCreateWithChecklistCreatesMaintenanceRecord(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MaintenanceFormController Create');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC MaintenanceFormController');

        $id = (new MaintenanceFormController())->createWithChecklist([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'comment'  => 'Commentaire PHPUnit',
        ]);

        $this->assertGreaterThan(0, $id);
        $maintenance = new Maintenance();
        $this->assertTrue($maintenance->getFromDB($id));
        $this->assertSame('Commentaire PHPUnit', $maintenance->fields['comment']);
    }

    public function testCreateWithChecklistThrowsForInvalidItemtype(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceFormController())->createWithChecklist([
            'itemtype' => 'NotARealClass',
            'items_id' => 1,
        ]);
    }

    public function testCreateWithChecklistThrowsWhenItemNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceFormController())->createWithChecklist([
            'itemtype' => 'Computer',
            'items_id' => 999999999,
        ]);
    }

    public function testCreateWithChecklistRejectsItemInEntityOutsideCurrentAccess(): void
    {
        global $DB;

        $inaccessibleEntityId = random_int(700000, 799999);
        $DB->insert('glpi_entities', [
            'id'           => $inaccessibleEntityId,
            'name'         => 'PHPUnit Maintenance Entite Inaccessible',
            'completename' => 'PHPUnit Maintenance Entite Inaccessible',
            'entities_id'  => 0,
            'level'        => 2,
        ]);
        // Jamais ajoutee a $_SESSION['glpiactiveentities'] : meme technique
        // que AssetsignTest::testCreateManualRejectsItemInEntityOutsideCurrentAccess(),
        // simule un utilisateur qui n'a pas acces a cette entite precise.
        $computer = $this->createTestComputer($inaccessibleEntityId, 'PHPUnit PC Maintenance Hors Portee');

        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceFormController())->createWithChecklist([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
        ]);
    }

    public function testCreateWithChecklistDecodesDamageMarkersDefensively(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MaintenanceFormController DamageMarkers');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC DamageMarkers');

        // JSON invalide : ne doit jamais empecher la creation de la fiche,
        // seulement etre traite comme "aucun marqueur" (cf. commentaire du
        // controleur, meme comportement que l'ancien front inline).
        $id = (new MaintenanceFormController())->createWithChecklist([
            'itemtype'       => 'Computer',
            'items_id'       => $computer->getID(),
            'damage_markers' => 'ceci-n-est-pas-du-json',
        ]);

        $this->assertGreaterThan(0, $id);
    }
}
