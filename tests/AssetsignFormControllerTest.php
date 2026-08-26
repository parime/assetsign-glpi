<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\AssetsignFormController;
use GlpiPlugin\Assetsign\Assetsign;
use InvalidArgumentException;

/**
 * Couvre le dispatch de front/assetsign.form.php desormais extrait dans
 * AssetsignFormController (cf. ROADMAP.md, point "etendre la couverture de
 * tests aux front/*.php") : chaque action postee par le formulaire, testee
 * directement sans passer par le vrai front/*.php (qui appelle Html::back()/
 * exit(), incompatible avec PHPUnit — cf. TROUBLESHOOTING.md).
 */
class AssetsignFormControllerTest extends AssetsignTestCase
{
    public function testCreateManualCreatesAssetsignAndReturnsSuccessMessage(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController CreateManual');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreateManual');

        $message = (new AssetsignFormController())->createManual([
            'itemtype'         => 'Computer',
            'items_id'         => $computer->getID(),
            'type'             => Assetsign::TYPE_DON,
            'users_id'         => 2,
            'beneficiary_type' => Assetsign::BENEFICIARY_INTERNAL,
        ]);

        // __() plutot qu'un litteral en dur : la session PHPUnit n'est pas
        // forcement en francais (CI anglaise), cf. TROUBLESHOOTING.md.
        $this->assertSame(__('Fiche créée.', 'assetsign'), $message);

        $created = new Assetsign();
        $this->assertTrue(
            $created->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Assetsign::TYPE_DON]),
            'createManual() doit avoir reellement insere une Assetsign en base.'
        );
    }

    public function testCreateManualLetsExceptionBubbleUpForInvalidType(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController CreateManualInvalid');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreateManualInvalid');

        // TYPE_HANDOVER (0) n'est pas dans MANUALLY_CREATABLE_TYPES : le
        // controleur ne doit pas avaler cette exception, seulement extraire
        // les parametres et la laisser remonter (cf. front/assetsign.form.php,
        // qui la capture lui-meme dans son try/catch).
        $this->expectException(InvalidArgumentException::class);
        (new AssetsignFormController())->createManual([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'type'     => Assetsign::TYPE_HANDOVER,
            'users_id' => 2,
        ]);
    }

    public function testSendReminderDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController SendReminder');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $message = (new AssetsignFormController())->sendReminder($assetsign);

        $this->assertSame(__('Relance envoyée.', 'assetsign'), $message);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(1, (int) $assetsign->fields['reminder_count']);
    }

    public function testCancelRequestDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController CancelRequest');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CancelRequest');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        (new AssetsignFormController())->cancelRequest($assetsign);

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_CANCELLED, (int) $assetsign->fields['status']);
    }

    public function testAddAccessoryThenRemoveAccessoryDelegateToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController Accessory');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        global $DB;
        $DB->insert('glpi_plugin_assetsign_accessories', ['name' => 'PHPUnit Accessory', 'is_active' => 1]);
        $accessoryId = (int) $DB->insertId();

        $controller = new AssetsignFormController();
        $controller->addAccessory($assetsign, ['plugin_assetsign_accessories_id' => $accessoryId, 'quantity' => 2]);

        $this->assertSame(1, countElementsInTable('glpi_plugin_assetsign_assetsignaccessories', [
            'plugin_assetsign_assetsigns_id'     => $assetsign->getID(),
            'plugin_assetsign_accessories_id' => $accessoryId,
        ]));

        $controller->removeAccessory($assetsign, ['plugin_assetsign_accessories_id' => $accessoryId]);

        $this->assertSame(0, countElementsInTable('glpi_plugin_assetsign_assetsignaccessories', [
            'plugin_assetsign_assetsigns_id'     => $assetsign->getID(),
            'plugin_assetsign_accessories_id' => $accessoryId,
        ]));
    }

    public function testUpdateObservationsDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController Observations');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        (new AssetsignFormController())->updateObservations($assetsign, ['observations' => 'RAS a la remise']);

        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame('RAS a la remise', $assetsign->fields['observations']);
    }

    public function testUpdateVenteDetailsDelegatesToModelAndDefaultsSaleDate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController VenteDetails');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_VENTE, Assetsign::STATUS_SENT);

        // sale_date absent/vide : doit retomber sur la date du jour, meme
        // raison que le commentaire "'?:' et non '??'" dans le controleur.
        (new AssetsignFormController())->updateVenteDetails($assetsign, ['price' => '42.5', 'sale_date' => '']);

        $details = \GlpiPlugin\Assetsign\VenteDetails::getForAssetsign($assetsign->getID());
        $this->assertNotNull($details);
        $this->assertSame(42.5, (float) $details->fields['price']);
        $this->assertSame(date('Y-m-d'), $details->fields['sale_date']);
    }

    public function testCreateManualCreatesDestructionAssetsignWithProviderName(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController CreateManualDestruction');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreateManualDestruction');

        $message = (new AssetsignFormController())->createManual([
            'itemtype'      => 'Computer',
            'items_id'      => $computer->getID(),
            'type'          => Assetsign::TYPE_DESTRUCTION,
            'users_id'      => 2,
            'provider_name' => 'Prestataire Controller',
        ]);

        $this->assertSame(__('Fiche créée.', 'assetsign'), $message);

        $created = new Assetsign();
        $this->assertTrue($created->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Assetsign::TYPE_DESTRUCTION]));
        $details = \GlpiPlugin\Assetsign\DestructionDetails::getForAssetsign($created->getID());
        $this->assertNotNull($details);
        $this->assertSame('Prestataire Controller', $details->fields['provider_name']);
    }

    public function testUpdateDonDetailsDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController DonDetails');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_DON, Assetsign::STATUS_SENT);

        (new AssetsignFormController())->updateDonDetails($assetsign, ['organization_name' => 'Organisme Controller']);

        $details = \GlpiPlugin\Assetsign\DonDetails::getForAssetsign($assetsign->getID());
        $this->assertNotNull($details);
        $this->assertSame('Organisme Controller', $details->fields['organization_name']);
    }

    public function testUpdateDestructionDetailsDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController DestructionDetails');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_DESTRUCTION, Assetsign::STATUS_SENT);

        (new AssetsignFormController())->updateDestructionDetails($assetsign, ['provider_name' => 'Prestataire Controller Update']);

        $details = \GlpiPlugin\Assetsign\DestructionDetails::getForAssetsign($assetsign->getID());
        $this->assertNotNull($details);
        $this->assertSame('Prestataire Controller Update', $details->fields['provider_name']);
    }

    public function testUpdateChecklistDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController Checklist');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $item = new \GlpiPlugin\Assetsign\ChecklistItem();
        $id = (int) $item->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist Controller',
            'type'           => \GlpiPlugin\Assetsign\ChecklistItem::TYPE_CHECKBOX,
            'is_active'      => 1,
            'movement_types' => [Assetsign::TYPE_HANDOVER],
        ]);

        (new AssetsignFormController())->updateChecklist($assetsign, ['checklist' => [$id => '1']]);

        $this->assertCount(1, $assetsign->getChecklistResults());
    }

    public function testUpdateChecklistWithoutChecklistKeyIsANoOp(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignFormController ChecklistEmpty');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        // Aucune cle 'checklist' soumise (ex: formulaire sans point applicable) :
        // ne doit jamais lever d'erreur.
        (new AssetsignFormController())->updateChecklist($assetsign, []);

        $this->assertCount(0, $assetsign->getChecklistResults());
    }
}
