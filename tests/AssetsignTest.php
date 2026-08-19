<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Accessory;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Template;
use GlpiPlugin\Assetsign\VenteDetails;
use InvalidArgumentException;
use RuntimeException;

/**
 * Couvre le cycle de vie central de Assetsign : creation manuelle (Don/Vente) et
 * ses garde-fous, et surtout les deux mecanismes de declenchement automatique
 * (affectation d'utilisateur / changement d'Etat) — la partie la moins
 * couverte jusqu'ici (verifiee uniquement via des scripts Docker manuels,
 * jamais par une suite automatisee), cf. ARCHITECTURE.md section Tests automatisés.
 */
class AssetsignTest extends AssetsignTestCase
{
    public function testCreateManualRejectsAutomaticallyTriggeredTypes(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual Guard');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Guard');

        $this->expectException(InvalidArgumentException::class);
        // TYPE_HANDOVER n'est PAS dans MANUALLY_CREATABLE_TYPES : seuls Don et
        // Vente peuvent etre crees par ce canal (cf. Assetsign.php).
        Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_HANDOVER, 2);
    }

    public function testCreateManualThrowsWhenItemNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        Assetsign::createManual('Computer', 999999999, Assetsign::TYPE_DON, 2);
    }

    /**
     * Faille reelle trouvee et corrigee en conditions reelles (cf.
     * TROUBLESHOOTING.md) : un utilisateur qui possede le droit generique
     * "Assetsign & signature" (verifie par l'appelant, front/assetsign.form.php,
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
        $this->expectExceptionMessage(__('Matériel introuvable.', 'assetsign'));
        Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
    }

    public function testCreateManualLaunchesWorkflowAndMarksSent(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual Don');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Don');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $this->assertSame(Assetsign::TYPE_DON, (int) $assetsign->fields['type']);
        $this->assertSame(
            Assetsign::STATUS_SENT,
            (int) $assetsign->fields['status'],
            'launchWorkflow() doit faire passer la fiche de PENDING a SENT une fois le PDF genere et la demande de signature lancee.'
        );
        $this->assertGreaterThan(0, (int) $assetsign->fields['document_id_unsigned'], 'Le PDF non signe doit avoir ete genere et attache.');
        $this->assertTrue($assetsign->isStillEditable());
    }

    public function testCreateManualForVenteStoresPriceAndSaleDate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual Vente');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Vente');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_VENTE, 2, [
            'price'     => 150.5,
            'sale_date' => '2026-01-15',
        ]);

        $details = VenteDetails::getForAssetsign($assetsign->getID());
        $this->assertNotNull($details, 'Une Vente creee manuellement avec un prix doit avoir sa ligne VenteDetails.');
        $this->assertSame('150.50', $details->fields['price']);
        $this->assertSame('2026-01-15', $details->fields['sale_date']);
    }

    /**
     * Coherence bidirectionnelle Etat <-> Don/Vente, sens "creation manuelle ->
     * Etat du materiel" (cf. ROADMAP.md et Assetsign::syncItemStateAfterManualCreation()) :
     * sans ca, l'inventaire GLPI pouvait afficher une autre realite que la
     * fiche qu'on vient de creer.
     */
    public function testCreateManualSyncsItemStateToConfiguredDonationState(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual State Sync');
        $donationStateId = $this->createTestState('PHPUnit Etat Donne Sync');
        Config::upsertForEntity($entityId, ['donation_states' => [$donationStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Sync Don');
        $this->assertNotSame($donationStateId, (int) $computer->fields['states_id']);

        Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $computer->getFromDB($computer->getID());
        $this->assertSame(
            $donationStateId,
            (int) $computer->fields['states_id'],
            "Creer manuellement une fiche de don doit mettre a jour l'Etat du materiel vers l'Etat declencheur configure."
        );

        // Garde-fou contre une regression du type "l'update de l'Etat redeclenche
        // le hook item_update -> handleStateBasedTrigger() -> createAssetsign() une
        // deuxieme fois pour ce meme materiel" (cf. commentaire de
        // syncItemStateAfterManualCreation() : mise a jour en SQL direct plutot
        // que via $item->update() pour eviter exactement ca).
        $count = countElementsInTable(Assetsign::getTable(), ['itemtype' => 'Computer', 'items_id' => $computer->getID()]);
        $this->assertSame(1, $count, 'Une seule fiche doit exister : la synchronisation de l\'Etat ne doit jamais en recreer une seconde.');
    }

    public function testCreateManualDoesNotTouchStateWhenNoneConfigured(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreateManual State NoConfig');
        // Explicite plutot que de compter sur l'absence de config propre a
        // l'entite (qui heriterait sinon du reglage racine — non garanti vide
        // sur une instance deja configuree manuellement, cf. TROUBLESHOOTING.md).
        Config::upsertForEntity($entityId, ['donation_states' => []]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State NoConfig');
        $originalState = (int) $computer->fields['states_id'];

        Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $computer->getFromDB($computer->getID());
        $this->assertSame(
            $originalState,
            (int) $computer->fields['states_id'],
            "Sans Etat declencheur configure pour ce type, l'Etat du materiel ne doit pas etre modifie."
        );
    }

    public function testIsStillEditableReflectsStatus(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit IsStillEditable');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Editable');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        // SENT (juste apres createManual) : encore editable.
        $this->assertTrue($assetsign->isStillEditable());

        $assetsign->update(['id' => $assetsign->getID(), 'status' => Assetsign::STATUS_SIGNED]);
        $this->assertFalse($assetsign->isStillEditable(), 'Une fiche signee ne doit plus etre modifiable.');
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

        Assetsign::handleItemAssignment($computer);

        $created = $this->findAssetsignFor($computer);
        $this->assertNotNull($created, 'Une remise aurait du etre creee automatiquement lors de cette affectation.');
        $this->assertSame(Assetsign::TYPE_HANDOVER, (int) $created['type']);
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

        Assetsign::handleItemAssignment($computer);

        $this->assertNull(
            $this->findAssetsignFor($computer),
            "Modifier un autre champ sans toucher au detenteur ni a l'Etat ne doit jamais declencher de fiche de assetsign."
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

        Assetsign::handleItemAssignment($computer);

        $this->assertNull($this->findAssetsignFor($computer), 'Aucune remise ne doit etre creee quand sign_on_assignment est desactive.');
    }

    public function testHandleItemAssignmentCreatesReturnWhenUserCleared(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Assignment Return');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Assignment Return');

        Config::upsertForEntity($entityId, ['sign_on_return' => 1]);

        // Le materiel etait affecte a l'utilisateur 2, vient d'etre libere (0).
        $computer->oldvalues = ['users_id' => 2];
        $computer->fields['users_id'] = 0;

        Assetsign::handleItemAssignment($computer);

        $created = $this->findAssetsignFor($computer);
        $this->assertNotNull($created, 'Une restitution aurait du etre creee automatiquement.');
        $this->assertSame(Assetsign::TYPE_RETURN, (int) $created['type']);
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

        Assetsign::handleItemAssignment($computer);

        $created = $this->findAssetsignFor($computer);
        $this->assertNotNull($created, "Un don aurait du etre declenche par le passage a l'Etat configure.");
        $this->assertSame(Assetsign::TYPE_DON, (int) $created['type']);
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

        Assetsign::handleItemAssignment($computer);

        $this->assertNull($this->findAssetsignFor($computer), "Un changement d'Etat non configure ne doit rien declencher.");
    }

    /**
     * Date de reforme automatique sur changement d'Etat (cf. ROADMAP.md, issue
     * #78) : effet de bord pur sur Infocom::decommission_date (champ natif
     * GLPI), aucune fiche Assetsign creee - contrairement aux 4 autres branches
     * de handleStateBasedTrigger() ci-dessus.
     */
    public function testHandleStateBasedTriggerWritesDecommissionDateOnConfiguredReformeState(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Reforme');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Reforme');
        $reformeStateId = $this->createTestState('PHPUnit Etat Reforme');

        Config::upsertForEntity($entityId, ['reforme_states' => [$reformeStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Reforme');
        // Aucune ligne Infocom existante pour ce materiel (jamais consultee via
        // l'onglet Infocom) : le mecanisme doit en creer une plutot que de
        // renoncer silencieusement.
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 0; // La reforme ne depend d'aucun beneficiaire.
        $computer->fields['states_id'] = $reformeStateId;

        Assetsign::handleItemAssignment($computer);

        $infocom = new \Infocom();
        $this->assertTrue($infocom->getFromDBforDevice('Computer', $computer->getID()), 'Une ligne Infocom doit avoir ete creee.');
        $this->assertSame(date('Y-m-d'), substr((string) $infocom->fields['decommission_date'], 0, 10), 'La date de reforme doit etre celle du jour.');
        $this->assertNull(
            $this->findAssetsignFor($computer),
            'Le declenchement par Etat de reforme ne doit JAMAIS creer de fiche Assetsign : effet de bord pur sur Infocom (cf. issue #78).'
        );
    }

    /**
     * Garde-fou explicite demande dans issue #78 : une date de reforme deja
     * renseignee (saisie manuelle, ou declenchement automatique precedent)
     * n'est jamais ecrasee.
     */
    public function testHandleStateBasedTriggerDoesNotOverwriteExistingDecommissionDate(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit State Reforme NoClobber');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Reforme NoClobber');
        $reformeStateId = $this->createTestState('PHPUnit Etat Reforme NoClobber');

        Config::upsertForEntity($entityId, ['reforme_states' => [$reformeStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Reforme NoClobber');
        // Date de reforme deja saisie manuellement par un administrateur, AVANT
        // que le declenchement automatique n'ait jamais eu lieu.
        $DB->insert('glpi_infocoms', [
            'itemtype'          => 'Computer',
            'items_id'          => $computer->getID(),
            'decommission_date' => '2020-01-15',
        ]);

        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 0;
        $computer->fields['states_id'] = $reformeStateId;

        Assetsign::handleItemAssignment($computer);

        $infocom = new \Infocom();
        $infocom->getFromDBforDevice('Computer', $computer->getID());
        $this->assertSame(
            '2020-01-15',
            substr((string) $infocom->fields['decommission_date'], 0, 10),
            'Une date de reforme deja renseignee ne doit jamais etre ecrasee par le declenchement automatique.'
        );
    }

    public function testHandleStateBasedTriggerIgnoresReformeWhenUnconfigured(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Reforme Unconfigured');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Reforme Unconfigured');
        $otherStateId = $this->createTestState('PHPUnit Etat Sans Effet Reforme');

        // reforme_states vide (par defaut) : aucune ecriture Infocom ne doit avoir lieu.
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Reforme Unconfigured');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 2;
        $computer->fields['states_id'] = $otherStateId;

        Assetsign::handleItemAssignment($computer);

        $infocom = new \Infocom();
        $this->assertFalse(
            $infocom->getFromDBforDevice('Computer', $computer->getID()),
            'Sans Etat de reforme configure, aucune ligne Infocom ne doit etre creee.'
        );
    }

    /**
     * Un meme Etat peut etre configure a la fois comme declencheur de Vente ET
     * de Reforme (cas legitime, ex: materiel vendu pour pieces detachees tout
     * en etant sorti d'inventaire) : les deux mecanismes sont independants,
     * cf. commentaire de handleStateBasedTrigger().
     */
    public function testHandleStateBasedTriggerCombinesReformeWithAnotherTrigger(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Reforme Combined');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Reforme Combined');
        $sharedStateId = $this->createTestState('PHPUnit Etat Vente Et Reforme');

        Config::upsertForEntity($entityId, ['vente_states' => [$sharedStateId], 'reforme_states' => [$sharedStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Reforme Combined');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 2;
        $computer->fields['states_id'] = $sharedStateId;

        Assetsign::handleItemAssignment($computer);

        $created = $this->findAssetsignFor($computer);
        $this->assertNotNull($created, 'La Vente doit toujours se declencher normalement.');
        $this->assertSame(Assetsign::TYPE_VENTE, (int) $created['type']);

        $infocom = new \Infocom();
        $this->assertTrue(
            $infocom->getFromDBforDevice('Computer', $computer->getID()),
            "La reforme doit AUSSI avoir ete enregistree pour ce meme changement d'Etat."
        );
        $this->assertSame(date('Y-m-d'), substr((string) $infocom->fields['decommission_date'], 0, 10));
    }

    public function testHandleStateBasedTriggerSkipsReformeWhenItemtypeCannotApplyInfocom(): void
    {
        global $CFG_GLPI;

        $entityId = $this->createTestEntity(0, 'PHPUnit State Reforme Not Applicable');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Reforme NA');
        $reformeStateId = $this->createTestState('PHPUnit Etat Reforme NA');

        Config::upsertForEntity($entityId, ['reforme_states' => [$reformeStateId]]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Reforme NA');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 0;
        $computer->fields['states_id'] = $reformeStateId;

        // Simule un reglage coeur GLPI ou Computer a ete retire de la liste des
        // itemtypes qui supportent l'Infocom (meme motif que
        // PassportEventInfocomTest::testShowForItemSkipsInfocomWhenItemtypeCannotApply()).
        $previousInfocomTypes = $CFG_GLPI['infocom_types'];
        $CFG_GLPI['infocom_types'] = array_values(array_diff($CFG_GLPI['infocom_types'], ['Computer']));

        try {
            Assetsign::handleItemAssignment($computer);
        } finally {
            $CFG_GLPI['infocom_types'] = $previousInfocomTypes;
        }

        $infocom = new \Infocom();
        $this->assertFalse(
            $infocom->getFromDBforDevice('Computer', $computer->getID()),
            "Infocom::canApplyOn() doit etre respecte : aucune ecriture pour un itemtype retire des types compatibles Infocom."
        );
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
        Assetsign::handleItemAssignment($computer);

        $this->assertNull(
            $this->findAssetsignFor($computer),
            'Aucune fiche automatique sans utilisateur : createManual() est le seul canal possible ici.'
        );
        // __('don', 'assetsign') plutot que le mot francais en dur : le message
        // reel est construit avec la meme cle de traduction (cf.
        // handleStateBasedTrigger()) - ce test doit rester valable quelle que
        // soit la langue de l'environnement d'execution (echec reel constate
        // en CI, qui rend en anglais).
        $messages = implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []);
        $this->assertStringContainsString(
            __('don', 'assetsign'),
            $messages,
            "Un message INFO doit inviter a creer la fiche de don manuellement (cf. TROUBLESHOOTING.md)."
        );
        // Coherence bidirectionnelle Etat <-> Don/Vente (cf. ROADMAP.md) : le
        // message doit pointer DIRECTEMENT vers le formulaire de creation
        // (forcetab, convention standard GLPI pour deep-linker un onglet), avec
        // le bon type pre-rempli (assetsign_prefill_type), plutot que de se
        // contenter d'un texte informatif que le technicien doit interpreter
        // lui-meme (cf. Assetsign::syncItemStateAfterManualCreation() pour le sens
        // inverse, teste dans AssetsignCreateManualSyncsStateTest ci-dessous).
        $this->assertStringContainsString('forcetab=', $messages, 'Le lien doit deep-linker directement sur l\'onglet Assetsigns.');
        $this->assertStringContainsString(
            'tab_params[assetsign_prefill_type]=' . Assetsign::TYPE_DON,
            $messages,
            'Le lien doit transmettre le type Don via tab_params (seul mecanisme du coeur GLPI qui survit au chargement ajax de l\'onglet, cf. showForItem()/assetsign_tab.html.twig).'
        );
    }

    public function testHandleStateBasedTriggerWarnsWhenHandoverHasNoUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit State Handover NoUser');
        $oldStateId = $this->createTestState('PHPUnit Etat Avant Handover SansUser');
        $handoverStateId = $this->createTestState('PHPUnit Etat Handover SansUser');

        Config::upsertForEntity($entityId, ['handover_states' => [$handoverStateId]]);

        // Meme situation que le don sans utilisateur (cf. test precedent), mais
        // pour Assetsign/Restitution : contrairement au don, aucune creation
        // manuelle n'est possible pour ce type (MANUALLY_CREATABLE_TYPES exclut
        // Assetsign/Restitution) - le message doit donc orienter vers l'assignation
        // de l'utilisateur, pas vers une fiche manuelle qui n'existe pas.
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC State Handover NoUser');
        $computer->oldvalues = ['states_id' => $oldStateId];
        $computer->fields['users_id'] = 0;
        $computer->fields['states_id'] = $handoverStateId;

        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        Assetsign::handleItemAssignment($computer);

        $this->assertNull(
            $this->findAssetsignFor($computer),
            "Aucune fiche automatique sans utilisateur : Assetsign::MANUALLY_CREATABLE_TYPES n'inclut pas Assetsign/Restitution."
        );
        // __() avec la meme chaine source que handleStateBasedTrigger() (Assetsign.php)
        // plutot que le texte francais en dur : ce test doit rester valable quelle
        // que soit la langue de l'environnement d'execution (meme piege que pour
        // 'don' ci-dessus, echec reel constate en CI, qui rend en anglais - deja
        // trouve une fois sur ce meme fichier, corrige ici pour de bon).
        $messages = implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []);
        $this->assertStringContainsString(
            __('Ce matériel n\'a pas d\'utilisateur assigné : ce changement d\'État ne peut donc pas générer de fiche de remise ou de restitution. Assignez un utilisateur sur la fiche du matériel si une signature est attendue.', 'assetsign'),
            $messages,
            "Un message INFO doit orienter vers l'assignation d'un utilisateur (pas de creation manuelle possible pour ce type)."
        );
    }

    public function testCancelPendingAssetsignsForCancelsPreviousAssetsignOnReassignment(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Reassignment');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reassignment');

        // Premiere affectation : utilisateur 0 -> 2.
        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = 2;
        Assetsign::handleItemAssignment($computer);
        $firstAssetsignId = (int) $this->findAssetsignFor($computer)['id'];

        // Reaffectation avant signature : utilisateur 2 -> 3, doit annuler la
        // premiere assetsign encore en attente (cf. cancelPendingAssetsignsFor()).
        $computer->oldvalues = ['users_id' => 2];
        $computer->fields['users_id'] = 3;
        Assetsign::handleItemAssignment($computer);

        $firstAssetsign = new Assetsign();
        $firstAssetsign->getFromDB($firstAssetsignId);
        $this->assertSame(
            Assetsign::STATUS_CANCELLED,
            (int) $firstAssetsign->fields['status'],
            'La premiere assetsign encore en attente doit etre annulee automatiquement lors de la reaffectation.'
        );

        $secondAssetsign = $this->findAssetsignFor($computer, $firstAssetsignId);
        $this->assertNotNull($secondAssetsign);
        $this->assertSame(3, (int) $secondAssetsign['users_id']);
    }

    public function testUpdateObservationsPersistsAndRegeneratesPdf(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Observations');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Observations');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $documentBefore = (int) $assetsign->fields['document_id_unsigned'];

        $assetsign->updateObservations('Écran rayé constaté au moment de la assetsign.');

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame('Écran rayé constaté au moment de la assetsign.', $assetsign->fields['observations']);
        $this->assertNotSame($documentBefore, (int) $assetsign->fields['document_id_unsigned']);
    }

    public function testUpdateObservationsHasNoEffectOnceSigned(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Observations Signed');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Observations Signed');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $assetsign->update(['id' => $assetsign->getID(), 'status' => Assetsign::STATUS_SIGNED]);

        $assetsign->updateObservations('Ne devrait jamais être enregistré.');

        $assetsign->getFromDB($assetsign->getID());
        $this->assertEmpty($assetsign->fields['observations'], "Une fiche signee ne doit plus pouvoir etre modifiee, meme via cette methode.");
    }

    public function testUpdateBeneficiaryCommentPersists(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Beneficiary Comment');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Beneficiary Comment');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $assetsign->updateBeneficiaryComment('Livré avec une rayure sur le côté.');

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame('Livré avec une rayure sur le côté.', $assetsign->fields['beneficiary_comment']);
    }

    public function testAddAndRemoveAccessory(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Accessory');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Accessory');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $accessory = new Accessory();
        $accessoryId = (int) $accessory->add(['entities_id' => 0, 'name' => 'PHPUnit Chargeur', 'is_active' => 1]);

        $assetsign->addAccessory($accessoryId, 2, 'Chargeur 65W');
        $accessories = $assetsign->getAccessories();
        $this->assertCount(1, $accessories);
        $this->assertSame(2, (int) $accessories[0]['quantity']);

        $assetsign->removeAccessory($accessoryId);
        $this->assertCount(0, $assetsign->getAccessories());
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
        Assetsign::handleItemAssignment($computer);

        $created = $this->findAssetsignFor($computer);
        $this->assertNotNull($created);
        $this->assertNull(VenteDetails::getForAssetsign((int) $created['id']), 'Aucun prix connu a la creation automatique.');

        $assetsign = new Assetsign();
        $assetsign->getFromDB((int) $created['id']);
        $assetsign->updateVenteDetails(299.99, '2026-02-01');

        $details = VenteDetails::getForAssetsign($assetsign->getID());
        $this->assertNotNull($details);
        $this->assertSame('299.99', $details->fields['price']);

        // Un deuxieme appel doit METTRE A JOUR la meme ligne, pas en creer une deuxieme.
        $assetsign->updateVenteDetails(199.99, '2026-02-15');
        $this->assertSame('199.99', VenteDetails::getForAssetsign($assetsign->getID())->fields['price']);
    }

    public function testCancelRequestMarksAssetsignCancelled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cancel Request');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cancel Request');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        $assetsign->cancelRequest();

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_CANCELLED, (int) $assetsign->fields['status']);
        $this->assertFalse($assetsign->isStillEditable());
    }

    public function testCancelRequestThrowsWhenAlreadySigned(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cancel Signed');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cancel Signed');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $assetsign->update(['id' => $assetsign->getID(), 'status' => Assetsign::STATUS_SIGNED]);

        $this->expectException(RuntimeException::class);
        $assetsign->cancelRequest();
    }

    public function testGetTabNameForItemCountsByUsersIdOnUserItemtype(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Tab User Count');

        $user = new \User();
        $user->getFromDB(2);

        $assetsign = new Assetsign();
        // Compte AVANT/APRES (plutot qu'une valeur absolue) : la base de test
        // partagee de ce conteneur Docker contient deja de nombreuses assetsigns
        // laissees par d'anciennes sessions manuelles pour l'utilisateur #2
        // (glpi), une assertion sur un decompte exact serait fragile.
        $countBefore = self::extractBadgeCount($assetsign->getTabNameForItem($user));

        $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED, 2);

        // Pas de chaine traduite ici (echec reel constate en CI, qui rend en
        // anglais - "Assetsigns" devient "Handovers") : seule la presence du
        // badge de decompte est verifiee, structure HTML stable quelle que
        // soit la langue de l'environnement d'execution.
        $tabName = $assetsign->getTabNameForItem($user);
        $this->assertNotSame('', $tabName, "L'onglet doit etre enregistre pour l'itemtype User.");
        $this->assertSame($countBefore + 1, self::extractBadgeCount($tabName));
    }

    public function testRenderHtmlSubstitutesPlaceholdersInContractAndCharter(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Placeholders');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Placeholders');

        $template = new Template();
        $templateId = (int) $template->add([
            'entities_id'     => $entityId,
            'type'            => Assetsign::TYPE_HANDOVER,
            'name'            => 'PHPUnit Template Placeholders',
            'content'         => 'Materiel : {materiel} - Date : {date} - Entite : {entite}.',
            'charter_content' => 'Beneficiaire : {beneficiaire} - Technicien : {technicien}.',
            'include_content' => 1,
            'include_charter' => 1,
        ]);
        $this->assertGreaterThan(0, $templateId);

        $assetsign = new Assetsign();
        $assetsignId = (int) $assetsign->add([
            'entities_id'                => $entityId,
            'itemtype'                   => 'Computer',
            'items_id'                   => $computer->getID(),
            'users_id'                   => 2,
            'users_id_tech'              => 2,
            'type'                       => Assetsign::TYPE_HANDOVER,
            'status'                     => Assetsign::STATUS_SIGNED,
            'plugin_assetsign_templates_id' => $templateId,
        ]);
        $assetsign->getFromDB($assetsignId);

        $html = (new HandoverPdfBuilder())->renderHtml($assetsign);

        // Aucun jeton de substitution ne doit survivre, quel que soit le
        // contenu de remplacement (y compris une chaine vide, ex: un
        // beneficiaire de test sans prenom/nom renseigne).
        foreach (['{materiel}', '{date}', '{entite}', '{beneficiaire}', '{technicien}'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $html, "Le placeholder $placeholder doit avoir ete remplace.");
        }
        $this->assertStringContainsString('Materiel : PHPUnit PC Placeholders', $html);
        $this->assertStringContainsString('Entite : PHPUnit Placeholders', $html);
    }

    public function testRenderPreviewSubstitutesPlaceholdersWithFictionalData(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Preview Placeholders');

        $html = (new HandoverPdfBuilder())->renderPreview($entityId, Assetsign::TYPE_HANDOVER, [
            'content' => 'Remis a {beneficiaire} (technicien {technicien}), materiel {materiel}, entite {entite}.',
        ]);

        $this->assertStringContainsString(
            'Remis a Alex Dupont (technicien Sophie Martin), materiel PC-EXEMPLE-001, entite PHPUnit Preview Placeholders.',
            $html
        );
    }

    public function testShowForUserFiltersByUsersIdAndShowsMaterialAndDownloadLink(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ShowForUser');
        // Pas createBareAssetsign() ici : elle fixe items_id a 1 en dur (sans
        // rapport avec le materiel reellement teste), alors que ce test
        // verifie precisement la resolution du libelle du BON materiel.
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ShowForUser');
        $assetsign = new Assetsign();
        $assetsignId = (int) $assetsign->add([
            'entities_id'        => $entityId,
            'itemtype'           => 'Computer',
            'items_id'           => $computer->getID(),
            'users_id'           => 2,
            'type'               => Assetsign::TYPE_HANDOVER,
            'status'             => Assetsign::STATUS_SIGNED,
            'document_id_signed' => 999,
        ]);
        $this->assertGreaterThan(0, $assetsignId);

        // Une remise pour un AUTRE utilisateur ne doit jamais apparaitre ici.
        $otherComputer = $this->createTestComputer($entityId, 'PHPUnit PC ShowForUser Other');
        (new Assetsign())->add([
            'entities_id' => $entityId,
            'itemtype'    => 'Computer',
            'items_id'    => $otherComputer->getID(),
            'users_id'    => 3,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => Assetsign::STATUS_SIGNED,
        ]);

        ob_start();
        Assetsign::showForUser(2);
        $html = ob_get_clean();

        $this->assertStringContainsString('PHPUnit PC ShowForUser', $html, "Le materiel concerne doit etre affiche par son nom sur l'onglet Attributions d'un utilisateur.");
        $this->assertStringContainsString('docid=999', $html, 'Le lien de telechargement du PDF signe doit pointer vers le bon document.');
    }

    /** Derniere assetsign (par id) pour ce materiel, ou null. $excludeId ignore un id precis (ex: l'ancienne assetsign annulee). */
    /**
     * Extrait le nombre affiche dans le badge de decompte d'un libelle
     * d'onglet (cf. CommonGLPI::createTabEntry()) — 0 si absent (echec reel
     * constate en CI, sur une base fraiche sans aucune remise prealable pour
     * l'utilisateur : createTabEntry() n'affiche alors aucun badge du tout,
     * pas un badge a "0").
     */
    private static function extractBadgeCount(string $tabName): int
    {
        preg_match('/data-testid="tab-count-badge">(\d+)</', $tabName, $matches);
        return isset($matches[1]) ? (int) $matches[1] : 0;
    }

    private function findAssetsignFor(\Computer $computer, ?int $excludeId = null): ?array
    {
        global $DB;

        $where = ['itemtype' => 'Computer', 'items_id' => $computer->getID()];
        if ($excludeId !== null) {
            $where[] = ['id' => ['!=', $excludeId]];
        }

        $rows = iterator_to_array($DB->request([
            'FROM'  => Assetsign::getTable(),
            'WHERE' => $where,
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]));

        return $rows === [] ? null : reset($rows);
    }
}
