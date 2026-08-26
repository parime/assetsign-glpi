<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Movement;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\Signature;
use RuntimeException;

/**
 * Couvre le mouvement structuré (issue #75, cf. docs/design/ADR-passeport-v1.md
 * section 3.2) : création (avec/sans signature), transitions de statut, et
 * l'événement Passeport correspondant (nouveau producteur, jamais une
 * réécriture des producteurs existants).
 */
class MovementTest extends AssetsignTestCase
{
    /** Un pixel PNG transparent minimal : trop petit pour passer SignatureImageValidator (MIN_WIDTH/MIN_HEIGHT). */
    private const TRIVIAL_PNG_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function testCreatePersistsLocationsAndDates(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Create');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement');
        $fromId = $this->createTestLocation('PHPUnit Site A');
        $toId = $this->createTestLocation('PHPUnit Site B');

        $id = Movement::create('Computer', $computer->getID(), $entityId, [
            'locations_id_from' => $fromId,
            'locations_id_to'   => $toId,
            'date_from'         => '2026-01-10 09:00:00',
            'date_to'           => '2026-01-12 17:00:00',
            'status'            => Movement::STATUS_IN_TRANSIT,
            'comment'           => 'Transfert PHPUnit',
        ]);

        $this->assertGreaterThan(0, $id);

        $movement = new Movement();
        $movement->getFromDB($id);

        $this->assertSame($fromId, (int) $movement->fields['locations_id_from']);
        $this->assertSame($toId, (int) $movement->fields['locations_id_to']);
        $this->assertSame(Movement::STATUS_IN_TRANSIT, (int) $movement->fields['status']);
        $this->assertSame('Transfert PHPUnit', $movement->fields['comment']);
        $this->assertSame(0, (int) $movement->fields['is_signed'], "Sans image de signature fournie, is_signed reste a 0.");
    }

    public function testCreateRecordsPassportEventWhenEnabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Passport Enabled');
        Config::upsertForEntity($entityId, ['enable_movements' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement Passport');

        $id = Movement::create('Computer', $computer->getID(), $entityId, []);

        global $DB;
        $events = iterator_to_array($DB->request([
            'FROM'  => PassportEvent::getTable(),
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'event_type' => PassportEvent::TYPE_MOVEMENT],
        ]));

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(Movement::class, $event['source_itemtype']);
        $this->assertSame($id, (int) $event['source_items_id']);
        $this->assertSame(0, (int) $event['users_id'], "Un mouvement n'a pas de beneficiaire : jamais rattache a un compte precis sur la frise.");
    }

    public function testCreateDoesNotRecordPassportEventWhenDisabled(): void
    {
        // Config::upsertForEntity() non appele : enable_movements reste a sa
        // valeur par defaut (0, fonctionnalite opt-in) pour cette entite.
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Passport Disabled');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement Passport Disabled');

        Movement::create('Computer', $computer->getID(), $entityId, []);

        global $DB;
        $events = iterator_to_array($DB->request([
            'FROM'  => PassportEvent::getTable(),
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'event_type' => PassportEvent::TYPE_MOVEMENT],
        ]));

        $this->assertCount(0, $events, "enable_movements desactive (defaut) : aucun evenement Passeport ne doit etre enregistre.");
    }

    public function testCreateWithTrivialSignatureImageThrows(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Trivial Signature');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement Trivial Signature');

        $this->expectException(RuntimeException::class);

        Movement::create('Computer', $computer->getID(), $entityId, [], self::TRIVIAL_PNG_DATA_URI);
    }

    public function testMarkInTransitThenCompletedUpdatesStatus(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Transitions');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement Transitions');

        $id = Movement::create('Computer', $computer->getID(), $entityId, []);
        $movement = new Movement();
        $movement->getFromDB($id);

        $this->assertSame(Movement::STATUS_PLANNED, (int) $movement->fields['status']);

        $movement->markInTransit();
        $movement->getFromDB($id);
        $this->assertSame(Movement::STATUS_IN_TRANSIT, (int) $movement->fields['status']);

        $movement->markCompleted('2026-02-01 08:00:00');
        $movement->getFromDB($id);
        $this->assertSame(Movement::STATUS_COMPLETED, (int) $movement->fields['status']);
        $this->assertSame('2026-02-01 08:00:00', $movement->fields['date_to']);
    }

    public function testCancelOnCompletedMovementIsANoop(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Cancel Noop');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement Cancel Noop');

        $id = Movement::create('Computer', $computer->getID(), $entityId, ['status' => Movement::STATUS_COMPLETED]);
        $movement = new Movement();
        $movement->getFromDB($id);

        $movement->cancel();
        $movement->getFromDB($id);

        $this->assertSame(
            Movement::STATUS_COMPLETED,
            (int) $movement->fields['status'],
            "Un mouvement deja termine (statut final) ne doit jamais repasser a Annule : cancel() est un no-op hors des statuts encore modifiables."
        );
    }

    public function testGetStatusColorReturnsAKnownColorForEachStatus(): void
    {
        foreach (array_keys(Movement::getStatuses()) as $status) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', Movement::getStatusColor($status));
        }
    }

    public function testRecordProofForMovementIsRetrievableViaGetForMovement(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Movement Signature Proof');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Movement Signature Proof');

        $id = Movement::create('Computer', $computer->getID(), $entityId, []);
        $movement = new Movement();
        $movement->getFromDB($id);

        Signature::recordProofForMovement($movement, [
            'signer_name'   => 'Jean Dupont',
            'signer_email'  => 'jean.dupont@example.com',
            'document_hash' => str_repeat('a', 64),
        ]);

        $proof = Signature::getForMovement($id);
        $this->assertNotNull($proof);
        $this->assertSame('Jean Dupont', $proof['signer_name']);
    }
}
