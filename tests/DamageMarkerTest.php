<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\DamageMarker;
use GlpiPlugin\Remise\Maintenance;
use GlpiPlugin\Remise\Remise;

/**
 * Couvre les reperes d'etat des lieux visuel : CRUD direct (addMarker/
 * updateMarker/deleteMarker), le controle d'appartenance a la bonne remise
 * (securite : un id devine ne doit pas permettre de toucher au repere d'une
 * AUTRE remise), le contrat POST partage par front/damagemarker.php et
 * front/sign.php (handleMutationRequest()), et l'enregistrement en bloc des
 * marqueurs d'une fiche de maintenance (createMarkersForMaintenance() -
 * chemin distinct, jamais par AJAX, cf. Maintenance.php) en verifiant au
 * passage l'isolation entre les deux types de fiche parente.
 */
class DamageMarkerTest extends RemiseTestCase
{
    public function testAddMarkerThenGetForRemiseReturnsIt(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Add');
        $remise = $this->createBareRemise($entityId);

        $id = DamageMarker::addMarker($remise->getID(), 1, 42.5, 17.25, 'Rayure sur le capot', DamageMarker::SEVERITY_MAJOR);

        $this->assertGreaterThan(0, $id);
        $markers = DamageMarker::getForRemise($remise->getID());
        $this->assertCount(1, $markers);
        $this->assertSame(1, (int) $markers[0]['view_index']);
        $this->assertSame('Rayure sur le capot', $markers[0]['description']);
        $this->assertSame(DamageMarker::SEVERITY_MAJOR, (int) $markers[0]['severity']);
    }

    public function testUpdateMarkerChangesPositionAndDescription(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Update');
        $remise = $this->createBareRemise($entityId);
        $id = DamageMarker::addMarker($remise->getID(), 0, 10.0, 10.0, 'Avant', DamageMarker::SEVERITY_MINOR);

        $success = DamageMarker::updateMarker($id, $remise->getID(), [
            'x_percent'   => 55.0,
            'y_percent'   => 60.0,
            'description' => 'Apres deplacement',
            'severity'    => DamageMarker::SEVERITY_MAJOR,
        ]);

        $this->assertTrue($success);
        $markers = DamageMarker::getForRemise($remise->getID());
        $this->assertSame('55.00', $markers[0]['x_percent']);
        $this->assertSame('Apres deplacement', $markers[0]['description']);
        $this->assertSame(DamageMarker::SEVERITY_MAJOR, (int) $markers[0]['severity']);
    }

    public function testUpdateMarkerRejectsMarkerBelongingToAnotherRemise(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker CrossOwner');
        $remiseA = $this->createBareRemise($entityId);
        $remiseB = $this->createBareRemise($entityId);
        $id = DamageMarker::addMarker($remiseA->getID(), 0, 10.0, 10.0, 'Appartient a A', DamageMarker::SEVERITY_MINOR);

        // On tente de modifier ce repere en pretendant agir pour la remise B :
        // doit echouer, sans quoi un id devine permettrait de modifier le
        // repere d'une remise qu'on ne devrait pas pouvoir toucher.
        $success = DamageMarker::updateMarker($id, $remiseB->getID(), ['description' => 'Detourne']);

        $this->assertFalse($success);
        $unchanged = DamageMarker::getForRemise($remiseA->getID());
        $this->assertSame('Appartient a A', $unchanged[0]['description'], "Le repere de la remise A ne doit pas avoir ete modifie par un appel se reclamant de B.");
    }

    public function testDeleteMarkerRejectsMarkerBelongingToAnotherRemise(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker CrossDelete');
        $remiseA = $this->createBareRemise($entityId);
        $remiseB = $this->createBareRemise($entityId);
        $id = DamageMarker::addMarker($remiseA->getID(), 0, 10.0, 10.0, 'A proteger', DamageMarker::SEVERITY_MINOR);

        $success = DamageMarker::deleteMarker($id, $remiseB->getID());

        $this->assertFalse($success);
        $this->assertCount(1, DamageMarker::getForRemise($remiseA->getID()), 'Le repere de A doit toujours exister.');
    }

    public function testDeleteMarkerRemovesItForItsOwnRemise(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Delete');
        $remise = $this->createBareRemise($entityId);
        $id = DamageMarker::addMarker($remise->getID(), 0, 10.0, 10.0, 'A supprimer', DamageMarker::SEVERITY_MINOR);

        $success = DamageMarker::deleteMarker($id, $remise->getID());

        $this->assertTrue($success);
        $this->assertCount(0, DamageMarker::getForRemise($remise->getID()));
    }

    public function testCanonicalViewLabelsAreFixedRegardlessOfLocale(): void
    {
        // getCanonicalViewLabels() (utilisee par le PDF) doit rester fixe,
        // contrairement a getViewLabels() (traduite, ecran d'annotation) —
        // un vrai bug avait fait fuiter la langue de session dans le PDF
        // archive, cf. TROUBLESHOOTING.md. Verifie juste que les deux jeux existent et
        // ont la meme forme (memes cles 0/1/2), pas leur contenu traduit.
        $canonical = DamageMarker::getCanonicalViewLabels();
        $translated = DamageMarker::getViewLabels();

        $this->assertSame(array_keys($canonical), array_keys($translated));
        $this->assertSame(['Vue arrière', 'Vue de face', 'Dessous'], array_values($canonical));
    }

    public function testHandleMutationRequestRejectsInvalidViewIndex(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker InvalidView');
        $remise = $this->createBareRemise($entityId);

        $result = DamageMarker::handleMutationRequest($remise, [
            'add'         => '1',
            'view_index'  => DamageMarker::VIEW_COUNT, // hors bornes (0..VIEW_COUNT-1)
            'x'           => 10,
            'y'           => 10,
            'description' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertCount(0, DamageMarker::getForRemise($remise->getID()));
    }

    public function testHandleMutationRequestReturnsFailureForUnknownAction(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker UnknownAction');
        $remise = $this->createBareRemise($entityId);

        $result = DamageMarker::handleMutationRequest($remise, []);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testHandleMutationRequestAddRegeneratesUnsignedPdf(): void
    {
        // Seul test de ce fichier a passer par createManual() (vrai PDF) : verifie
        // le vrai bug corrige par le passe (un repere ajoute via ce point d'entree
        // n'apparaissait jamais sur la vraie fiche PDF, cf. TROUBLESHOOTING.md) — a savoir que
        // handleMutationRequest() declenche bien refreshDamageAnnotationPdf().
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Regenerate');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC DamageMarker');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $documentBefore = (int) $remise->fields['document_id_unsigned'];

        $result = DamageMarker::handleMutationRequest($remise, [
            'add'         => '1',
            'view_index'  => 0,
            'x'           => 20,
            'y'           => 30,
            'description' => 'Test regeneration PDF',
            'severity'    => DamageMarker::SEVERITY_MINOR,
        ]);

        $this->assertTrue($result['success']);
        $remise->getFromDB($remise->getID());
        $documentAfter = (int) $remise->fields['document_id_unsigned'];

        $this->assertGreaterThan(0, $documentAfter);
        $this->assertNotSame($documentBefore, $documentAfter, "Le PDF non signe doit avoir ete regenere (nouveau Document) apres l'ajout d'un repere.");
    }

    public function testCreateMarkersForMaintenancePersistsAllValidMarkers(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Maintenance');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC DamageMarker Maintenance');
        $maintenanceId = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');

        DamageMarker::createMarkersForMaintenance($maintenanceId, [
            ['view_index' => 0, 'x' => 12.5, 'y' => 87.0, 'description' => 'Vue arrière abîmée', 'severity' => DamageMarker::SEVERITY_MAJOR],
            ['view_index' => 2, 'x' => 50.0, 'y' => 50.0, 'description' => '', 'severity' => DamageMarker::SEVERITY_MINOR],
        ]);

        $markers = DamageMarker::getForMaintenance($maintenanceId);
        $this->assertCount(2, $markers);
        $this->assertSame(0, (int) $markers[0]['view_index']);
        $this->assertSame('Vue arrière abîmée', $markers[0]['description']);
        $this->assertSame(DamageMarker::SEVERITY_MAJOR, (int) $markers[0]['severity']);
        $this->assertSame(2, (int) $markers[1]['view_index']);
    }

    public function testCreateMarkersForMaintenanceIgnoresInvalidViewIndex(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Maintenance Invalid');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC DamageMarker Maintenance Invalid');
        $maintenanceId = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');

        DamageMarker::createMarkersForMaintenance($maintenanceId, [
            ['view_index' => DamageMarker::VIEW_COUNT, 'x' => 10, 'y' => 10, 'description' => 'Hors bornes', 'severity' => 0],
            ['view_index' => -1, 'x' => 10, 'y' => 10, 'description' => 'Negatif', 'severity' => 0],
        ]);

        $this->assertCount(0, DamageMarker::getForMaintenance($maintenanceId), "Un view_index hors bornes ne doit jamais etre enregistre.");
    }

    public function testCreateMarkersForMaintenanceClampsCoordinatesAndSeverity(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Maintenance Clamp');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC DamageMarker Maintenance Clamp');
        $maintenanceId = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');

        DamageMarker::createMarkersForMaintenance($maintenanceId, [
            ['view_index' => 0, 'x' => -10, 'y' => 150, 'description' => '', 'severity' => 99],
        ]);

        $markers = DamageMarker::getForMaintenance($maintenanceId);
        $this->assertSame('0.00', $markers[0]['x_percent'], 'Une coordonnee negative doit etre ramenee a 0.');
        $this->assertSame('100.00', $markers[0]['y_percent'], 'Une coordonnee superieure a 100 doit etre ramenee a 100.');
        $this->assertSame(DamageMarker::SEVERITY_MINOR, (int) $markers[0]['severity'], 'Une gravite invalide doit se replier sur SEVERITY_MINOR.');
    }

    public function testMaintenanceAndRemiseMarkersAreIsolatedFromEachOther(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit DamageMarker Isolation');
        $remise = $this->createBareRemise($entityId);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC DamageMarker Isolation');
        $maintenanceId = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');

        DamageMarker::addMarker($remise->getID(), 0, 10, 10, 'Marqueur remise', DamageMarker::SEVERITY_MINOR);
        DamageMarker::createMarkersForMaintenance($maintenanceId, [
            ['view_index' => 0, 'x' => 20, 'y' => 20, 'description' => 'Marqueur maintenance', 'severity' => 0],
        ]);

        $remiseMarkers = DamageMarker::getForRemise($remise->getID());
        $maintenanceMarkers = DamageMarker::getForMaintenance($maintenanceId);

        $this->assertCount(1, $remiseMarkers);
        $this->assertCount(1, $maintenanceMarkers);
        $this->assertSame('Marqueur remise', $remiseMarkers[0]['description']);
        $this->assertSame('Marqueur maintenance', $maintenanceMarkers[0]['description']);
    }
}
