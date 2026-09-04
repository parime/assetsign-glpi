<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\AssetsignFormController;
use GlpiPlugin\Assetsign\Api\SignController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * Couvre la delegation de signature (issue #115) : Assetsign::delegateSignatureTo()/
 * revokeDelegation()/getActualSigner(), le controleur de formulaire admin
 * (AssetsignFormController) et l'elargissement de l'autorisation de signature
 * a distance (SignController::loadAuthorizedAssetsign()).
 */
class AssetsignDelegationTest extends AssetsignTestCase
{
    private function makeEmail(int $usersId, string $email): void
    {
        $ue = new \UserEmail();
        $ue->add(['users_id' => $usersId, 'email' => $email, 'is_default' => 1]);
    }

    public function testDelegateSignatureToWritesTraceabilityFields(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation Basic');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation Basic');
        $beneficiaryId = $this->createTestUser('Ben', 'Eficiary');
        $delegateId = $this->createTestUser('Del', 'Egate', ['_entities_id' => $entityId]);
        $this->makeEmail($delegateId, 'delegate.basic@example.test');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $originalToken = $assetsign->_current_raw_token;

        $assetsign->delegateSignatureTo($delegateId, 'Congés', $beneficiaryId);
        $assetsign->getFromDB($assetsign->getID());

        $this->assertSame($beneficiaryId, (int) $assetsign->fields['users_id'], 'Le bénéficiaire d\'origine ne doit jamais être modifié.');
        $this->assertSame($delegateId, (int) $assetsign->fields['delegated_users_id']);
        $this->assertSame($beneficiaryId, (int) $assetsign->fields['delegated_by_users_id']);
        $this->assertSame('Congés', $assetsign->fields['delegation_reason']);
        $this->assertNotEmpty($assetsign->fields['delegation_date']);

        $this->assertNotEmpty($assetsign->_current_raw_token);
        $this->assertNotSame($originalToken, $assetsign->_current_raw_token, 'Le jeton doit être régénéré par la délégation.');

        $this->expectException(RuntimeException::class);
        Token::validate($originalToken);
    }

    public function testDelegateSignatureToLogsHistory(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation History');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation History');
        $beneficiaryId = $this->createTestUser('Ben', 'History');
        $delegateId = $this->createTestUser('Del', 'History', ['_entities_id' => $entityId]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $assetsign->delegateSignatureTo($delegateId, 'Motif de test', 2);

        $rows = iterator_to_array($DB->request([
            'FROM'  => 'glpi_logs',
            'WHERE' => ['itemtype' => Assetsign::class, 'items_id' => $assetsign->getID()],
        ]));
        $this->assertNotEmpty($rows, 'delegateSignatureTo() doit écrire explicitement dans l\'historique (Assetsign::$dohistory reste à false).');

        // Le message est traduit via __() (cf. Assetsign::delegateSignatureTo()) : on le
        // reconstruit de la même façon plutôt que de figer un fragment français en dur,
        // pour que le test reste valide quelle que soit la langue de l'environnement
        // d'exécution (le même principe est déjà suivi par les autres tests du plugin).
        $delegate = new \User();
        $delegate->getFromDB($delegateId);
        $expectedMessage = sprintf(
            __('Signature déléguée à %s (motif : %s)', 'assetsign'),
            formatUserName(0, $delegate->fields['name'], $delegate->fields['realname'], $delegate->fields['firstname']),
            'Motif de test'
        );
        $this->assertSame($expectedMessage, (string) end($rows)['new_value']);
    }

    public function testDelegateSignatureToRejectsSameAsOriginalBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation SelfReject');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation SelfReject');
        $beneficiaryId = $this->createTestUser('Ben', 'SelfReject');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectException(RuntimeException::class);
        $assetsign->delegateSignatureTo($beneficiaryId, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsUnknownUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation UnknownUser');
        // Sans activer le commutateur ici, le rejet interviendrait au controle
        // enable_signature_delegation (verifie avant la resolution du compte)
        // au lieu du controle "compte introuvable" que ce test vise
        // precisement - cf. testDelegateSignatureToRejectsWhenDelegationDisabledForEntity
        // pour l'autre cas.
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation UnknownUser');
        $beneficiaryId = $this->createTestUser('Ben', 'UnknownUser');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectExceptionMessage(__('Compte utilisateur délégué introuvable.', 'assetsign'));
        $assetsign->delegateSignatureTo(999999, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsWhenDelegationDisabledForEntity(): void
    {
        // Regression du finding LOW "enable_signature_delegation est seulement
        // verifie cote Twig sur le chemin technicien" (rapport de securite
        // 2.6.0) : le commutateur doit desormais etre revalide directement
        // dans delegateSignatureTo(), quel que soit l'appelant.
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation Disabled');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation Disabled');
        $beneficiaryId = $this->createTestUser('Ben', 'Disabled');
        $delegateId = $this->createTestUser('Del', 'Disabled', ['_entities_id' => $entityId]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectExceptionMessage(__('La délégation de signature est désactivée pour cette entité.', 'assetsign'));
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsDelegateWithoutAccessToEntity(): void
    {
        // Finding MEDIUM "Delegation target accepted from POST with no entity
        // or account-state validation" (rapport de securite 2.6.0) : un compte
        // sans acces a l'entite du dossier ne doit plus pouvoir etre designe.
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation NoEntityAccess');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation NoEntityAccess');
        $beneficiaryId = $this->createTestUser('Ben', 'NoEntityAccess');
        // Cree SANS _entities_id => $entityId : ce compte n'a acces qu'a la
        // racine (entite par defaut), pas a l'entite du dossier ci-dessus.
        $delegateId = $this->createTestUser('Del', 'NoEntityAccess');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectExceptionMessage(__('Le compte délégué n\'a pas accès à l\'entité de ce dossier.', 'assetsign'));
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsDisabledDelegate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation DisabledAccount');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation DisabledAccount');
        $beneficiaryId = $this->createTestUser('Ben', 'DisabledAccount');
        $delegateId = $this->createTestUser('Del', 'DisabledAccount', [
            '_entities_id' => $entityId,
            'is_active'    => 0,
        ]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectExceptionMessage(__('Le compte délégué est désactivé ou supprimé.', 'assetsign'));
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsDeletedDelegate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation DeletedAccount');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation DeletedAccount');
        $beneficiaryId = $this->createTestUser('Ben', 'DeletedAccount');
        $delegateId = $this->createTestUser('Del', 'DeletedAccount', ['_entities_id' => $entityId]);
        $delegate = new \User();
        $delegate->update(['id' => $delegateId, 'is_deleted' => 1]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectExceptionMessage(__('Le compte délégué est désactivé ou supprimé.', 'assetsign'));
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsExternalBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation External');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation External');
        $delegateId = $this->createTestUser('Del', 'External');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 0, [
            'beneficiary_type' => Assetsign::BENEFICIARY_EXTERNAL,
            'external_name'    => 'Assoc Externe',
        ]);

        $this->expectException(RuntimeException::class);
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
    }

    public function testDelegateSignatureToRejectsWhenNoLongerEditable(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation NotEditable');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation NotEditable');
        $beneficiaryId = $this->createTestUser('Ben', 'NotEditable');
        $delegateId = $this->createTestUser('Del', 'NotEditable');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $assetsign->cancelRequest();

        $this->expectException(RuntimeException::class);
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
    }

    public function testRevokeDelegationRestoresOriginalBeneficiaryAccess(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation Revoke');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation Revoke');
        $beneficiaryId = $this->createTestUser('Ben', 'Revoke');
        $delegateId = $this->createTestUser('Del', 'Revoke', ['_entities_id' => $entityId]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
        $tokenWhileDelegated = $assetsign->_current_raw_token;

        $assetsign->revokeDelegation();
        $assetsign->getFromDB($assetsign->getID());

        $this->assertSame(0, (int) $assetsign->fields['delegated_users_id']);
        $this->assertEmpty($assetsign->fields['delegation_date']);
        $this->assertNotEmpty($assetsign->_current_raw_token);
        $this->assertNotSame($tokenWhileDelegated, $assetsign->_current_raw_token);

        // Le beneficiaire d'origine peut de nouveau charger la fiche avec ce
        // nouveau jeton (assertCurrentUserIsAuthorizedSigner() ne rejette plus
        // rien pour users_id, delegated_users_id etant repasse a 0).
        $_SESSION['glpiID'] = $beneficiaryId;
        $loaded = (new SignController())->loadAuthorizedAssetsign($assetsign->_current_raw_token);
        $this->assertSame($assetsign->getID(), $loaded->getID());
    }

    public function testRevokeDelegationRejectsWhenNotDelegated(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation RevokeReject');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation RevokeReject');
        $beneficiaryId = $this->createTestUser('Ben', 'RevokeReject');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectException(RuntimeException::class);
        $assetsign->revokeDelegation();
    }

    public function testGetActualSignerReturnsDelegateOnlyWhenDelegateIsConnected(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation ActualSigner');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation ActualSigner');
        $beneficiaryId = $this->createTestUser('Ben', 'ActualSigner');
        $delegateId = $this->createTestUser('Del', 'ActualSigner', ['_entities_id' => $entityId]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);

        $_SESSION['glpiID'] = $delegateId;
        $signer = $assetsign->getActualSigner();
        $this->assertSame($delegateId, (int) $signer['id']);

        $_SESSION['glpiID'] = $beneficiaryId;
        $signer = $assetsign->getActualSigner();
        $this->assertSame($beneficiaryId, (int) $signer['id']);
    }

    public function testSignControllerAcceptsBothOriginalBeneficiaryAndDelegate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation SignController');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation SignController');
        $beneficiaryId = $this->createTestUser('Ben', 'SignController');
        $delegateId = $this->createTestUser('Del', 'SignController', ['_entities_id' => $entityId]);
        $strangerId = $this->createTestUser('Str', 'SignController');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $assetsign->delegateSignatureTo($delegateId, 'motif', 2);
        $token = $assetsign->_current_raw_token;

        $controller = new SignController();

        $_SESSION['glpiID'] = $delegateId;
        $loaded = $controller->loadAuthorizedAssetsign($token);
        $this->assertSame($assetsign->getID(), $loaded->getID());

        $_SESSION['glpiID'] = $strangerId;
        $this->expectException(RuntimeException::class);
        $controller->loadAuthorizedAssetsign($token);
    }

    public function testDelegateSelfServiceRejectsReDelegationByCurrentDelegate(): void
    {
        // Finding MEDIUM "A delegate can re-delegate the signature; the gate
        // is template-only" (rapport de securite 2.6.0) : assertCurrentUserIsAuthorizedSigner()
        // accepte par conception le beneficiaire OU le delegue courant, donc
        // sans ce garde-fou explicite le delegue pouvait re-deleguer en
        // chaine (B -> C) depuis cette meme page - seul le beneficiaire
        // d'ORIGINE (A) a vocation a s'auto-deleguer.
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation ReDelegate');
        Config::upsertForEntity($entityId, [
            'enable_signature_delegation'    => 1,
            'enable_self_service_delegation' => 1,
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation ReDelegate');
        $beneficiaryId = $this->createTestUser('Ben', 'ReDelegate');
        $firstDelegateId = $this->createTestUser('Del', 'ReDelegateFirst', ['_entities_id' => $entityId]);
        $secondDelegateId = $this->createTestUser('Del', 'ReDelegateSecond', ['_entities_id' => $entityId]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);
        $assetsign->delegateSignatureTo($firstDelegateId, 'motif initial', $beneficiaryId);
        $tokenAfterFirstDelegation = $assetsign->_current_raw_token;

        // Le premier delegue tente de transferer la signature a un second
        // compte, depuis la meme page de signature que celle du beneficiaire.
        $_SESSION['glpiID'] = $firstDelegateId;
        $this->expectExceptionMessage(__('Seul le bénéficiaire d\'origine peut déléguer la signature depuis cette page.', 'assetsign'));
        (new SignController())->delegateSelfService($tokenAfterFirstDelegation, $secondDelegateId, 'tentative de re-delegation');
    }

    public function testAssetsignFormControllerDelegateAndRevokeWrappers(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation FormController');
        Config::upsertForEntity($entityId, ['enable_signature_delegation' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation FormController');
        $beneficiaryId = $this->createTestUser('Ben', 'FormController');
        $delegateId = $this->createTestUser('Del', 'FormController', ['_entities_id' => $entityId]);

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $controller = new AssetsignFormController();
        $message = $controller->delegateSignature($assetsign, [
            'delegate_users_id'  => $delegateId,
            'delegation_reason'  => 'Motif via formulaire',
        ]);
        $this->assertNotEmpty($message);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame($delegateId, (int) $assetsign->fields['delegated_users_id']);

        $message = $controller->revokeDelegation($assetsign);
        $this->assertNotEmpty($message);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(0, (int) $assetsign->fields['delegated_users_id']);
    }

    public function testConfigUpsertForcesSelfServiceOffWhenParentDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Delegation Config');

        Config::upsertForEntity($entityId, [
            'enable_signature_delegation'    => 0,
            'enable_self_service_delegation' => 1,
        ]);
        $config = Config::getForEntity($entityId);
        $this->assertSame(0, (int) $config->fields['enable_self_service_delegation']);

        Config::upsertForEntity($entityId, [
            'enable_signature_delegation'    => 1,
            'enable_self_service_delegation' => 1,
        ]);
        $config = Config::getForEntity($entityId);
        $this->assertSame(1, (int) $config->fields['enable_signature_delegation']);
        $this->assertSame(1, (int) $config->fields['enable_self_service_delegation']);
    }
}
