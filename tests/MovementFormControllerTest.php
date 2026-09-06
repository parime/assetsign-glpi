<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\MovementFormController;
use GlpiPlugin\Assetsign\Movement;
use InvalidArgumentException;

/**
 * Couvre le dispatch de l'action "create" de front/movement.form.php desormais
 * extrait dans MovementFormController — meme motivation et meme patron que
 * MaintenanceFormControllerTest/AssetsignFormControllerTest (cf. ROADMAP.md,
 * point tests des front/*.php). Ce controleur etait le seul de sa famille
 * (AssetsignFormController, MaintenanceFormController, ResidualValueFormController,
 * QrLabelController, SignController — tous testes) a ne pas avoir de test
 * dedie malgre la meme logique de garde (itemtype valide, materiel trouvable
 * et lisible par l'utilisateur courant) avant de deleguer a Movement::create().
 */
class MovementFormControllerTest extends AssetsignTestCase
{
    public function testCreateCreatesMovementRecord(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MovementFormController Create');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC MovementFormController');

        $id = (new MovementFormController())->create([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'comment'  => 'Commentaire PHPUnit',
        ]);

        $this->assertGreaterThan(0, $id);
        $movement = new Movement();
        $this->assertTrue($movement->getFromDB($id));
        $this->assertSame('Commentaire PHPUnit', $movement->fields['comment']);
        $this->assertSame('Computer', $movement->fields['itemtype']);
        $this->assertSame($computer->getID(), (int) $movement->fields['items_id']);
    }

    public function testCreateThrowsForInvalidItemtype(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MovementFormController())->create([
            'itemtype' => 'NotARealClass',
            'items_id' => 1,
        ]);
    }

    public function testCreateThrowsWhenItemNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MovementFormController())->create([
            'itemtype' => 'Computer',
            'items_id' => 999999999,
        ]);
    }

    public function testCreateRejectsItemInEntityOutsideCurrentAccess(): void
    {
        global $DB;

        $inaccessibleEntityId = random_int(700000, 799999);
        $DB->insert('glpi_entities', [
            'id'           => $inaccessibleEntityId,
            'name'         => 'PHPUnit Movement Entite Inaccessible',
            'completename' => 'PHPUnit Movement Entite Inaccessible',
            'entities_id'  => 0,
            'level'        => 2,
        ]);
        // Jamais ajoutee a $_SESSION['glpiactiveentities'] : meme technique que
        // MaintenanceFormControllerTest::testCreateWithChecklistRejectsItemInEntityOutsideCurrentAccess(),
        // simule un utilisateur qui n'a pas acces a cette entite precise.
        $computer = $this->createTestComputer($inaccessibleEntityId, 'PHPUnit PC Movement Hors Portee');

        $this->expectException(InvalidArgumentException::class);
        (new MovementFormController())->create([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
        ]);
    }

    public function testCreateNormalizesDatetimeLocalSeparator(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MovementFormController Dates');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC MovementFormController Dates');

        // Format brut d'un <input type="datetime-local"> (separateur 'T', ISO 8601) —
        // le controleur doit le normaliser en ' ' avant l'insertion, voir son propre
        // commentaire sur la compatibilite des colonnes DATETIME/TIMESTAMP.
        $id = (new MovementFormController())->create([
            'itemtype'  => 'Computer',
            'items_id'  => $computer->getID(),
            'date_from' => '2026-08-25T14:30',
        ]);

        $movement = new Movement();
        $movement->getFromDB($id);
        // GLPI's own DB layer normalizes a stored DATETIME back with seconds on read —
        // asserting on the ' ' separator (the actual point of this test) via prefix rather
        // than a full match that would depend on that unrelated round-trip detail.
        $this->assertStringStartsWith('2026-08-25 14:30', $movement->fields['date_from']);
    }
}
