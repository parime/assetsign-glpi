<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Api\RemiseFormController;
use GlpiPlugin\Remise\Remise;
use InvalidArgumentException;

/**
 * Couvre le dispatch de front/remise.form.php desormais extrait dans
 * RemiseFormController (cf. ROADMAP.md, point "etendre la couverture de
 * tests aux front/*.php") : chaque action postee par le formulaire, testee
 * directement sans passer par le vrai front/*.php (qui appelle Html::back()/
 * exit(), incompatible avec PHPUnit — cf. TROUBLESHOOTING.md).
 */
class RemiseFormControllerTest extends RemiseTestCase
{
    public function testCreateManualCreatesRemiseAndReturnsSuccessMessage(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController CreateManual');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreateManual');

        $message = (new RemiseFormController())->createManual([
            'itemtype'         => 'Computer',
            'items_id'         => $computer->getID(),
            'type'             => Remise::TYPE_DON,
            'users_id'         => 2,
            'beneficiary_type' => Remise::BENEFICIARY_INTERNAL,
        ]);

        // __() plutot qu'un litteral en dur : la session PHPUnit n'est pas
        // forcement en francais (CI anglaise), cf. TROUBLESHOOTING.md.
        $this->assertSame(__('Fiche créée.', 'remise'), $message);

        $created = new Remise();
        $this->assertTrue(
            $created->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Remise::TYPE_DON]),
            'createManual() doit avoir reellement insere une Remise en base.'
        );
    }

    public function testCreateManualLetsExceptionBubbleUpForInvalidType(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController CreateManualInvalid');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreateManualInvalid');

        // TYPE_HANDOVER (0) n'est pas dans MANUALLY_CREATABLE_TYPES : le
        // controleur ne doit pas avaler cette exception, seulement extraire
        // les parametres et la laisser remonter (cf. front/remise.form.php,
        // qui la capture lui-meme dans son try/catch).
        $this->expectException(InvalidArgumentException::class);
        (new RemiseFormController())->createManual([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'type'     => Remise::TYPE_HANDOVER,
            'users_id' => 2,
        ]);
    }

    public function testSendReminderDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController SendReminder');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $message = (new RemiseFormController())->sendReminder($remise);

        $this->assertSame(__('Relance envoyée.', 'remise'), $message);
        $remise->getFromDB($remise->getID());
        $this->assertSame(1, (int) $remise->fields['reminder_count']);
    }

    public function testCancelRequestDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController CancelRequest');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CancelRequest');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);

        (new RemiseFormController())->cancelRequest($remise);

        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_CANCELLED, (int) $remise->fields['status']);
    }

    public function testAddAccessoryThenRemoveAccessoryDelegateToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController Accessory');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        global $DB;
        $DB->insert('glpi_plugin_remise_accessories', ['name' => 'PHPUnit Accessory', 'is_active' => 1]);
        $accessoryId = (int) $DB->insertId();

        $controller = new RemiseFormController();
        $controller->addAccessory($remise, ['plugin_remise_accessories_id' => $accessoryId, 'quantity' => 2]);

        $this->assertSame(1, countElementsInTable('glpi_plugin_remise_remiseaccessories', [
            'plugin_remise_remises_id'     => $remise->getID(),
            'plugin_remise_accessories_id' => $accessoryId,
        ]));

        $controller->removeAccessory($remise, ['plugin_remise_accessories_id' => $accessoryId]);

        $this->assertSame(0, countElementsInTable('glpi_plugin_remise_remiseaccessories', [
            'plugin_remise_remises_id'     => $remise->getID(),
            'plugin_remise_accessories_id' => $accessoryId,
        ]));
    }

    public function testUpdateObservationsDelegatesToModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController Observations');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        (new RemiseFormController())->updateObservations($remise, ['observations' => 'RAS a la remise']);

        $remise->getFromDB($remise->getID());
        $this->assertSame('RAS a la remise', $remise->fields['observations']);
    }

    public function testUpdateVenteDetailsDelegatesToModelAndDefaultsSaleDate(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit RemiseFormController VenteDetails');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_VENTE, Remise::STATUS_SENT);

        // sale_date absent/vide : doit retomber sur la date du jour, meme
        // raison que le commentaire "'?:' et non '??'" dans le controleur.
        (new RemiseFormController())->updateVenteDetails($remise, ['price' => '42.5', 'sale_date' => '']);

        $details = \GlpiPlugin\Remise\VenteDetails::getForRemise($remise->getID());
        $this->assertNotNull($details);
        $this->assertSame(42.5, (float) $details->fields['price']);
        $this->assertSame(date('Y-m-d'), $details->fields['sale_date']);
    }
}
