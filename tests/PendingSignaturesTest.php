<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\SignController;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\PendingSignatures;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * "Mes documents a signer" et bandeau d'accueil (issue #150) : qui voit quel document, et le
 * lien emis depuis la page doit etre accepte par la page de signature (SignController) pour
 * exactement les memes personnes.
 */
class PendingSignaturesTest extends AssetsignTestCase
{
    private $originalGlpiId;
    private $originalActiveEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGlpiId = $_SESSION['glpiID'] ?? null;
        $this->originalActiveEntity = $_SESSION['glpiactive_entity'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach (['glpiID' => $this->originalGlpiId, 'glpiactive_entity' => $this->originalActiveEntity] as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /** Fiche minimale envoyee a $usersId, avec les champs supplementaires de $extra. */
    private function createSentAssetsign(int $entityId, int $usersId, array $extra = []): Assetsign
    {
        global $DB;

        $assetsign = $this->createBareAssetsign($entityId, status: Assetsign::STATUS_SENT, usersId: $usersId);
        $DB->update(Assetsign::getTable(), $extra + ['date_sent' => date('Y-m-d H:i:s')], ['id' => $assetsign->getID()]);
        $assetsign->getFromDB($assetsign->getID());

        return $assetsign;
    }

    /** @return list<int> */
    private function pendingIds(int $usersId): array
    {
        return array_map(static fn (array $p): int => (int) $p['assetsign']->getID(), PendingSignatures::forUser($usersId));
    }

    public function testBeneficiaryOnlySeesDocumentsAwaitingHisSignature(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Statuses');
        $user = $this->createTestUser('Paul', 'Sansmail');

        $sent = $this->createSentAssetsign($entityId, $user);
        $viewed = $this->createSentAssetsign($entityId, $user, ['status' => Assetsign::STATUS_VIEWED]);
        foreach ([Assetsign::STATUS_DRAFT, Assetsign::STATUS_PENDING, Assetsign::STATUS_SIGNED, Assetsign::STATUS_EXPIRED, Assetsign::STATUS_CANCELLED] as $status) {
            $this->createSentAssetsign($entityId, $user, ['status' => $status]);
        }
        $this->createSentAssetsign($entityId, $user, ['is_deleted' => 1]);

        $ids = $this->pendingIds($user);
        sort($ids);
        $this->assertSame([(int) $sent->getID(), (int) $viewed->getID()], $ids);
        $this->assertSame(2, PendingSignatures::countForUser($user));
        $this->assertSame(PendingSignatures::ROLE_SIGNER, PendingSignatures::forUser($user)[0]['role']);
    }

    public function testAnotherUserSeesNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Other');
        $beneficiary = $this->createTestUser('Anne', 'Beneficiaire');
        $other = $this->createTestUser('Olivier', 'Autre');
        $this->createSentAssetsign($entityId, $beneficiary);

        $this->assertSame([], PendingSignatures::forUser($other));
        $this->assertSame(0, PendingSignatures::countForUser($other));
        $this->assertSame(0, PendingSignatures::countForUser(0), 'Sans session, rien.');
    }

    public function testDelegatedDocumentMovesFromBeneficiaryToDelegate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Delegation');
        $beneficiary = $this->createTestUser('Bea', 'Absente');
        $delegate = $this->createTestUser('Denis', 'Delegue');
        $assetsign = $this->createSentAssetsign($entityId, $beneficiary, ['delegated_users_id' => $delegate]);

        $this->assertSame([], $this->pendingIds($beneficiary), 'Une fois deleguee, la signature n\'est plus attendue du beneficiaire.');
        $this->assertSame([(int) $assetsign->getID()], $this->pendingIds($delegate));
        $this->assertSame(1, PendingSignatures::countForUser($delegate));
    }

    public function testCosignerSeesDocumentOnlyAtTheCosignatureStep(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Cosigner');
        $beneficiary = $this->createTestUser('Carl', 'Salarie');
        $manager = $this->createTestUser('Maud', 'Responsable');

        $assetsign = $this->createSentAssetsign($entityId, $beneficiary, ['cosigner_users_id' => $manager]);
        $this->assertSame([], $this->pendingIds($manager), 'Le responsable n\'est sollicite qu\'apres la signature du beneficiaire.');

        global $DB;
        $DB->update(Assetsign::getTable(), ['status' => Assetsign::STATUS_AWAITING_COSIGNATURE], ['id' => $assetsign->getID()]);

        $this->assertSame([], $this->pendingIds($beneficiary));
        $pending = PendingSignatures::forUser($manager);
        $this->assertCount(1, $pending);
        $this->assertSame(PendingSignatures::ROLE_COSIGNER, $pending[0]['role']);
    }

    public function testSigningTokenIsAcceptedBySignPageForTheBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Token');
        $user = $this->createTestUser('Sam', 'Signataire');
        $assetsign = $this->createSentAssetsign($entityId, $user);
        // Lien recu par e-mail, quand il y en a un : il doit rester valable.
        $emailToken = Token::createForAssetsign($assetsign, 30);

        $raw = PendingSignatures::createSigningToken((int) $assetsign->getID(), $user);

        $_SESSION['glpiID'] = $user;
        $controller = new SignController();
        $this->assertSame($assetsign->getID(), $controller->loadAuthorizedAssetsign($raw)->getID());
        $this->assertSame($assetsign->getID(), $controller->loadAuthorizedAssetsign($emailToken)->getID(), 'Le lien de l\'e-mail n\'est pas invalide.');
    }

    public function testSigningTokenCarriesTheCosignerRole(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Token Cosigner');
        $beneficiary = $this->createTestUser('Cleo', 'Salariee');
        $manager = $this->createTestUser('Marc', 'Responsable');
        $assetsign = $this->createSentAssetsign($entityId, $beneficiary, [
            'cosigner_users_id' => $manager,
            'status'            => Assetsign::STATUS_AWAITING_COSIGNATURE,
        ]);

        $raw = PendingSignatures::createSigningToken((int) $assetsign->getID(), $manager);

        $this->assertSame(1, (int) Token::validate($raw)->fields['for_cosigner']);
        $_SESSION['glpiID'] = $manager;
        $this->assertSame($assetsign->getID(), (new SignController())->loadAuthorizedAssetsign($raw)->getID());
    }

    public function testSigningTokenIsRefusedToAnotherUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Token Refused');
        $beneficiary = $this->createTestUser('Rita', 'Beneficiaire');
        $other = $this->createTestUser('Ugo', 'Intrus');
        $assetsign = $this->createSentAssetsign($entityId, $beneficiary);

        $this->expectException(RuntimeException::class);
        PendingSignatures::createSigningToken((int) $assetsign->getID(), $other);
    }

    public function testSigningTokenIsRefusedOnceSigned(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Token Signed');
        $user = $this->createTestUser('Theo', 'Deja');
        $assetsign = $this->createSentAssetsign($entityId, $user, ['status' => Assetsign::STATUS_SIGNED]);

        $this->expectException(RuntimeException::class);
        PendingSignatures::createSigningToken((int) $assetsign->getID(), $user);
    }

    public function testRemainingValidityFollowsTheDocumentDeadline(): void
    {
        $assetsign = new Assetsign();

        $assetsign->fields = ['date_sent' => date('Y-m-d H:i:s', time() - 10 * DAY_TIMESTAMP - 60)];
        $this->assertSame(20, PendingSignatures::remainingValidityDays($assetsign, 30));

        $assetsign->fields = ['date_sent' => date('Y-m-d H:i:s', time() - 40 * DAY_TIMESTAMP)];
        $this->assertSame(1, PendingSignatures::remainingValidityDays($assetsign, 30), 'Delai depasse mais fiche pas encore expiree : toujours signable.');

        $assetsign->fields = ['date_sent' => null];
        $this->assertSame(30, PendingSignatures::remainingValidityDays($assetsign, 30));
    }

    public function testHomePageBannerOnlyWhenSomethingIsPending(): void
    {
        require_once dirname(__DIR__) . '/hook.php';

        global $DB;
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Banner');
        // Configuration propre a l'entite, valeurs par defaut de la colonne (bandeau actif) :
        // independante de ce qu'une entite parente a pu desactiver.
        $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId]);
        $user = $this->createTestUser('Lea', 'Terrain');
        $_SESSION['glpiactive_entity'] = $entityId;

        $_SESSION['glpiID'] = $user;
        $this->assertSame('', $this->renderBanner(), 'Rien a signer : pas de bandeau.');

        $this->createSentAssetsign($entityId, $user);
        $html = $this->renderBanner();
        $this->assertStringContainsString('<tr><td>', $html);
        $this->assertStringContainsString('/plugins/assetsign/front/mysignatures.php', $html);
        $this->assertStringContainsString('1', $html);
    }

    public function testHomePageBannerRespectsTheEntitySetting(): void
    {
        require_once dirname(__DIR__) . '/hook.php';

        global $DB;
        $entityId = $this->createTestEntity(0, 'PHPUnit Pending Banner Off');
        $DB->insert('glpi_plugin_assetsign_configs', ['entities_id' => $entityId, 'enable_pending_signatures_banner' => 0]);
        $user = $this->createTestUser('Noe', 'Desactive');
        $this->createSentAssetsign($entityId, $user);

        $_SESSION['glpiactive_entity'] = $entityId;
        $_SESSION['glpiID'] = $user;

        $this->assertSame('', $this->renderBanner());
    }

    private function renderBanner(): string
    {
        ob_start();
        plugin_assetsign_display_central();
        return (string) ob_get_clean();
    }
}
