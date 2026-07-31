<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\VenteDetails;
use InvalidArgumentException;
use RuntimeException;

/**
 * Couvre le cycle de vie central de Remise : creation manuelle (Don/Vente) et
 * ses garde-fous, et surtout les deux mecanismes de declenchement automatique
 * (affectation d'utilisateur / changement d'Etat) — la partie la moins
 * couverte jusqu'ici (verifiee uniquement via des scripts Docker manuels,
 * jamais par une suite automatisee), cf. README section Tests.
 */
class RemiseTest extends RemiseTestCase
{
    public function testCreateManualRejectsAutomaticallyTriggeredTypes(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual Guard');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Guard');

        $this->expectException(InvalidArgumentException::class);
        // TYPE_HANDOVER n'est PAS dans MANUALLY_CREATABLE_TYPES : seuls Don et
        // Vente peuvent etre crees par ce canal (cf. Remise.php).
        Remise::createManual('Computer', $computer->getID(), Remise::TYPE_HANDOVER, 2);
    }

    public function testCreateManualThrowsWhenItemNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        Remise::createManual('Computer', 999999999, Remise::TYPE_DON, 2);
    }

    public function testCreateManualLaunchesWorkflowAndMarksSent(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual Don');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Don');

        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);

        $this->assertSame(Remise::TYPE_DON, (int) $remise->fields['type']);
        $this->assertSame(
            Remise::STATUS_SENT,
            (int) $remise->fields['status'],
            'launchWorkflow() doit faire passer la fiche de PENDING a SENT une fois le PDF genere et la demande de signature lancee.'
        );
        $this->assertGreaterThan(0, (int) $remise->fields['document_id_unsigned'], 'Le PDF non signe doit avoir ete genere et attache.');
        $this->assertTrue($remise->isStillEditable());
    }

    public function testCreateManualForVenteStoresPriceAndSaleDate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual Vente');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Vente');

        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_VENTE, 2, [
            'price'     => 150.5,
            'sale_date' => '2026-01-15',
        ]);

        $details = VenteDetails::getForRemise($remise->getID());
        $this->assertNotNull($details, 'Une Vente creee manuellement avec un prix doit avoir sa ligne VenteDetails.');
        $this->assertSame('150.50', $details->fields['price']);
        $this->assertSame('2026-01-15', $details->fields['sale_date']);
    }

    public function testIsStillEditableReflectsStatus(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit IsStillEditable');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Editable');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);

        // SENT (juste apres createManual) : encore editable.
        $this->assertTrue($remise->isStillEditable());

        $remise->update(['id' => $remise->getID(), 'status' => Remise::STATUS_SIGNED]);
        $this->assertFalse($remise->isStillEditable(), 'Une fiche signee ne doit plus etre modifiable.');
    }

    public function testHandleItemAssignmentCreatesHandoverOnNewAssignment(): void
    {
        // Entite sans config propre : herite du reglage racine sign_on_assignment=1
        // (seme a l'installation, cf. Config::install()) — aucune config explicite
        // necessaire pour ce cas "activation par defaut".
        $entityId = $this->createTestEntity(0, 'PHPUnit Assignment Handover');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Assignment');

        // Simule le hook item_update : le materiel etait sans detenteur (0),
        // vient d'etre affecte a l'utilisateur 2 (compte 'glpi' du jeu de test).
        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = 2;

        Remise::handleItemAssignment($computer);

        $created = $this->findRemiseFor($computer);
        $this->assertNotNull($created, 'Une remise aurait du etre creee automatiquement lors de cette affectation.');
        $this->assertSame(Remise::TYPE_HANDOVER, (int) $created['type']);
        $this->assertSame(2, (int) $created['users_id']);
    }

    public function testHandleItemAssignmentSkipsWhenSignOnAssignmentDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Assignment Disabled');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Assignment Disabled');

        // Desactive explicitement le declenchement par affectation pour cette
        // entite (upsertForEntity remet a 0/defaut tout champ absent du tableau
        // partiel, cf. README "notes techniques" — sans consequence ici, seul
        // sign_on_assignment nous interesse pour ce test).
        Config::upsertForEntity($entityId, ['sign_on_assignment' => 0]);

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = 2;

        Remise::handleItemAssignment($computer);

        $this->assertNull($this->findRemiseFor($computer), 'Aucune remise ne doit etre creee quand sign_on_assignment est desactive.');
    }

    public function testHandleItemAssignmentCreatesReturnWhenUserCleared(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Assignment Return');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Assignment Return');

        Config::upsertForEntity($entityId, ['sign_on_return' => 1]);

        // Le materiel etait affecte a l'utilisateur 2, vient d'etre libere (0).
        $computer->oldvalues = ['users_id' => 2];
        $computer->fields['users_id'] = 0;

        Remise::handleItemAssignment($computer);

        $created = $this->findRemiseFor($computer);
        $this->assertNotNull($created, 'Une restitution aurait du etre creee automatiquement.');
        $this->assertSame(Remise::TYPE_RETURN, (int) $created['type']);
        $this->assertSame(2, (int) $created['users_id'], "La restitution doit cibler l'ANCIEN detenteur, pas le nouveau (0).");
    }

    public function testHandleStateBasedTriggerCreatesDonationOnConfiguredState(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Donation');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant');
        $donationStateId = $this->createTestState('PHPUnit Etat Don');

        Config::upsertForEntity($entityId, ['donation_states' => [$donationStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Donation');
        // Affectation d'utilisateur inchangee (pas de cle 'users_id' dans
        // oldvalues) : seul le declenchement par Etat doit s'evaluer.
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 2;
        $computer->fields['states_id'] = $donationStateId;

        Remise::handleItemAssignment($computer);

        $created = $this->findRemiseFor($computer);
        $this->assertNotNull($created, "Un don aurait du etre declenche par le passage a l'Etat configure.");
        $this->assertSame(Remise::TYPE_DON, (int) $created['type']);
    }

    public function testHandleStateBasedTriggerIgnoresUnconfiguredState(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Unconfigured');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant 2');
        $otherStateId = $this->createTestState('PHPUnit Etat Sans Effet');

        // Aucun handover_states/return_states/donation_states/vente_states ne
        // contient $otherStateId : aucun declenchement ne doit avoir lieu.
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Unconfigured');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 2;
        $computer->fields['states_id'] = $otherStateId;

        Remise::handleItemAssignment($computer);

        $this->assertNull($this->findRemiseFor($computer), "Un changement d'Etat non configure ne doit rien declencher.");
    }

    public function testCancelPendingRemisesForCancelsPreviousRemiseOnReassignment(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reassignment');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reassignment');

        // Premiere affectation : utilisateur 0 -> 2.
        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = 2;
        Remise::handleItemAssignment($computer);
        $firstRemiseId = (int) $this->findRemiseFor($computer)['id'];

        // Reaffectation avant signature : utilisateur 2 -> 3, doit annuler la
        // premiere remise encore en attente (cf. cancelPendingRemisesFor()).
        $computer->oldvalues = ['users_id' => 2];
        $computer->fields['users_id'] = 3;
        Remise::handleItemAssignment($computer);

        $firstRemise = new Remise();
        $firstRemise->getFromDB($firstRemiseId);
        $this->assertSame(
            Remise::STATUS_CANCELLED,
            (int) $firstRemise->fields['status'],
            'La premiere remise encore en attente doit etre annulee automatiquement lors de la reaffectation.'
        );

        $secondRemise = $this->findRemiseFor($computer, $firstRemiseId);
        $this->assertNotNull($secondRemise);
        $this->assertSame(3, (int) $secondRemise['users_id']);
    }

    /** Derniere remise (par id) pour ce materiel, ou null. $excludeId ignore un id precis (ex: l'ancienne remise annulee). */
    private function findRemiseFor(\Computer $computer, ?int $excludeId = null): ?array
    {
        global $DB;

        $where = ['itemtype' => 'Computer', 'items_id' => $computer->getID()];
        if ($excludeId !== null) {
            $where[] = ['id' => ['!=', $excludeId]];
        }

        $rows = iterator_to_array($DB->request([
            'FROM'  => Remise::getTable(),
            'WHERE' => $where,
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]));

        return $rows === [] ? null : reset($rows);
    }
}
