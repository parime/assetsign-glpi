<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Accessory;
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
 * jamais par une suite automatisee), cf. ARCHITECTURE.md section Tests automatisés.
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

    /**
     * Faille reelle trouvee et corrigee en conditions reelles (cf.
     * TROUBLESHOOTING.md) : un utilisateur qui possede le droit generique
     * "Remise & signature" (verifie par l'appelant, front/remise.form.php,
     * jamais restreint par entite) pouvait avant ce correctif creer une
     * fiche pour N'IMPORTE QUEL materiel de N'IMPORTE QUELLE entite de
     * l'instance, y compris hors de la portee de son propre profil -
     * contournement complet de la segregation par entite, coeur du modele
     * multi-societes/multi-sites que ce plugin cible pourtant explicitement
     * (logo/config par entite...). Simule ici une entite volontairement
     * absente de $_SESSION['glpiactiveentities'] (insertion directe, sans
     * passer par createTestEntity() qui l'y enregistre automatiquement) :
     * exactement la situation d'un utilisateur n'ayant pas acces a cette
     * entite precise.
     */
    public function testCreateManualRejectsItemInEntityOutsideCurrentAccess(): void
    {
        global $DB;

        $inaccessibleEntityId = random_int(700000, 799999);
        $DB->insert('glpi_entities', [
            'id'           => $inaccessibleEntityId,
            'name'         => 'PHPUnit Entite Inaccessible',
            'completename' => 'PHPUnit Entite Inaccessible',
            'entities_id'  => 0,
            'level'        => 2,
        ]);
        // Jamais ajoutee a $_SESSION['glpiactiveentities'] : simule un
        // utilisateur qui n'a pas acces a cette entite precise.
        $computer = $this->createTestComputer($inaccessibleEntityId, 'PHPUnit PC Hors Portee');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('Matériel introuvable.', 'remise'));
        Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
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

    public function testHandleItemAssignmentSkipsWhenOtherFieldChangedButUserAndStateUntouched(): void
    {
        // Cas reel : un technicien modifie un champ sans rapport (ex: commentaire,
        // numero de serie) sur la fiche d'un materiel deja affecte, et enregistre -
        // sans jamais toucher au detenteur ni a l'Etat. GLPI ne place alors AUCUNE
        // cle 'users_id'/'states_id' dans oldvalues (seuls les champs reellement
        // soumis ET differents de la valeur en base y figurent) : aucune remise ne
        // doit etre creee, contrairement a une reaffectation reelle.
        $entityId = $this->createTestEntity(0, 'PHPUnit Assignment Untouched');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Assignment Untouched');
        $computer->fields['users_id'] = 2;

        $computer->oldvalues = ['comment' => 'Ancien commentaire'];
        $computer->fields['comment'] = 'Nouveau commentaire';

        Remise::handleItemAssignment($computer);

        $this->assertNull(
            $this->findRemiseFor($computer),
            "Modifier un autre champ sans toucher au detenteur ni a l'Etat ne doit jamais declencher de fiche de remise."
        );
    }

    public function testHandleItemAssignmentSkipsWhenSignOnAssignmentDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Assignment Disabled');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Assignment Disabled');

        // Desactive explicitement le declenchement par affectation pour cette
        // entite (upsertForEntity remet a 0/defaut tout champ absent du tableau
        // partiel, cf. TROUBLESHOOTING.md — sans consequence ici, seul
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

    public function testHandleStateBasedTriggerWarnsWhenDonationHasNoUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Donation NoUser');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Don SansUser');
        $donationStateId = $this->createTestState('PHPUnit Etat Don SansUser');

        Config::upsertForEntity($entityId, ['donation_states' => [$donationStateId]]);

        // Materiel en stock, jamais affecte a personne (cas legitime pour un
        // don/une vente, cf. commentaire de handleStateBasedTrigger()).
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Donation NoUser');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 0;
        $computer->fields['states_id'] = $donationStateId;

        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        Remise::handleItemAssignment($computer);

        $this->assertNull(
            $this->findRemiseFor($computer),
            'Aucune fiche automatique sans utilisateur : createManual() est le seul canal possible ici.'
        );
        // __('don', 'remise') plutot que le mot francais en dur : le message
        // reel est construit avec la meme cle de traduction (cf.
        // handleStateBasedTrigger()) - ce test doit rester valable quelle que
        // soit la langue de l'environnement d'execution (echec reel constate
        // en CI, qui rend en anglais).
        $messages = implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []);
        $this->assertStringContainsString(
            __('don', 'remise'),
            $messages,
            "Un message INFO doit inviter a creer la fiche de don manuellement (cf. TROUBLESHOOTING.md)."
        );
    }

    public function testHandleStateBasedTriggerWarnsWhenHandoverHasNoUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Handover NoUser');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Handover SansUser');
        $handoverStateId = $this->createTestState('PHPUnit Etat Handover SansUser');

        Config::upsertForEntity($entityId, ['handover_states' => [$handoverStateId]]);

        // Meme situation que le don sans utilisateur (cf. test precedent), mais
        // pour Remise/Restitution : contrairement au don, aucune creation
        // manuelle n'est possible pour ce type (MANUALLY_CREATABLE_TYPES exclut
        // Remise/Restitution) - le message doit donc orienter vers l'assignation
        // de l'utilisateur, pas vers une fiche manuelle qui n'existe pas.
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Handover NoUser');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 0;
        $computer->fields['states_id'] = $handoverStateId;

        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        Remise::handleItemAssignment($computer);

        $this->assertNull(
            $this->findRemiseFor($computer),
            "Aucune fiche automatique sans utilisateur : Remise::MANUALLY_CREATABLE_TYPES n'inclut pas Remise/Restitution."
        );
        // __() avec la meme chaine source que handleStateBasedTrigger() (Remise.php)
        // plutot que le texte francais en dur : ce test doit rester valable quelle
        // que soit la langue de l'environnement d'execution (meme piege que pour
        // 'don' ci-dessus, echec reel constate en CI, qui rend en anglais - deja
        // trouve une fois sur ce meme fichier, corrige ici pour de bon).
        $messages = implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []);
        $this->assertStringContainsString(
            __('Ce matériel n\'a pas d\'utilisateur assigné : ce changement d\'État ne peut donc pas générer de fiche de remise ou de restitution. Assignez un utilisateur sur la fiche du matériel si une signature est attendue.', 'remise'),
            $messages,
            "Un message INFO doit orienter vers l'assignation d'un utilisateur (pas de creation manuelle possible pour ce type)."
        );
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

    public function testUpdateObservationsPersistsAndRegeneratesPdf(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Observations');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Observations');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $documentBefore = (int) $remise->fields['document_id_unsigned'];

        $remise->updateObservations('Écran rayé constaté au moment de la remise.');

        $remise->getFromDB($remise->getID());
        $this->assertSame('Écran rayé constaté au moment de la remise.', $remise->fields['observations']);
        $this->assertNotSame($documentBefore, (int) $remise->fields['document_id_unsigned']);
    }

    public function testUpdateObservationsHasNoEffectOnceSigned(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Observations Signed');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Observations Signed');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $remise->update(['id' => $remise->getID(), 'status' => Remise::STATUS_SIGNED]);

        $remise->updateObservations('Ne devrait jamais être enregistré.');

        $remise->getFromDB($remise->getID());
        $this->assertEmpty($remise->fields['observations'], "Une fiche signee ne doit plus pouvoir etre modifiee, meme via cette methode.");
    }

    public function testUpdateBeneficiaryCommentPersists(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Beneficiary Comment');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Beneficiary Comment');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);

        $remise->updateBeneficiaryComment('Livré avec une rayure sur le côté.');

        $remise->getFromDB($remise->getID());
        $this->assertSame('Livré avec une rayure sur le côté.', $remise->fields['beneficiary_comment']);
    }

    public function testAddAndRemoveAccessory(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Accessory');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Accessory');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);

        $accessory = new Accessory();
        $accessoryId = (int) $accessory->add(['entities_id' => 0, 'name' => 'PHPUnit Chargeur', 'is_active' => 1]);

        $remise->addAccessory($accessoryId, 2, 'Chargeur 65W');
        $accessories = $remise->getAccessories();
        $this->assertCount(1, $accessories);
        $this->assertSame(2, (int) $accessories[0]['quantity']);

        $remise->removeAccessory($accessoryId);
        $this->assertCount(0, $remise->getAccessories());
    }

    public function testUpdateVenteDetailsUpsertsWhenNoneExisted(): void
    {
        // Reproduit une Vente declenchee automatiquement par changement d'Etat
        // (cf. handleStateBasedTrigger()) : aucune VenteDetails n'existe encore
        // au moment de la creation, le prix est renseigne apres coup.
        $entityId = $this->createTestEntity(0, 'PHPUnit Vente Upsert');
        $donationStateId = $this->createTestState('PHPUnit Etat Avant Vente');
        $venteStateId = $this->createTestState('PHPUnit Etat Vente');
        Config::upsertForEntity($entityId, ['vente_states' => [$venteStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Vente Upsert');
        $computer->oldvalues = ['states_id' => $donationStateId];
        $computer->fields['users_id'] = 2;
        $computer->fields['states_id'] = $venteStateId;
        Remise::handleItemAssignment($computer);

        $created = $this->findRemiseFor($computer);
        $this->assertNotNull($created);
        $this->assertNull(VenteDetails::getForRemise((int) $created['id']), 'Aucun prix connu a la creation automatique.');

        $remise = new Remise();
        $remise->getFromDB((int) $created['id']);
        $remise->updateVenteDetails(299.99, '2026-02-01');

        $details = VenteDetails::getForRemise($remise->getID());
        $this->assertNotNull($details);
        $this->assertSame('299.99', $details->fields['price']);

        // Un deuxieme appel doit METTRE A JOUR la meme ligne, pas en creer une deuxieme.
        $remise->updateVenteDetails(199.99, '2026-02-15');
        $this->assertSame('199.99', VenteDetails::getForRemise($remise->getID())->fields['price']);
    }

    public function testCancelRequestMarksRemiseCancelled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cancel Request');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cancel Request');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);

        $remise->cancelRequest();

        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_CANCELLED, (int) $remise->fields['status']);
        $this->assertFalse($remise->isStillEditable());
    }

    public function testCancelRequestThrowsWhenAlreadySigned(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cancel Signed');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cancel Signed');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $remise->update(['id' => $remise->getID(), 'status' => Remise::STATUS_SIGNED]);

        $this->expectException(RuntimeException::class);
        $remise->cancelRequest();
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
