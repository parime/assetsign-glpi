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
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Delegation UnknownUser');
        $beneficiaryId = $this->createTestUser('Ben', 'UnknownUser');

        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, $beneficiaryId);

        $this->expectException(RuntimeException::class);
        $assetsign->delegateSignatureTo(999999, 'motif', 2);
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
