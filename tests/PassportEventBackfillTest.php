<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Couvre le retro-remplissage depuis glpi_logs (cf. ROADMAP.md, "Passeport
 * materiel" / "Passeport utilisateur") : rejoue la meme logique que
 * Assetsign::handleUserBasedTrigger()/handleStateBasedTrigger() sur des lignes
 * de glpi_logs inserees directement, plutot que sur de vrais changements
 * d'oldvalues (glpi_logs est ecrit par le coeur GLPI lui-meme lors d'un vrai
 * update(), hors de portee raisonnable d'un test unitaire isole).
 */
class PassportEventBackfillTest extends AssetsignTestCase
{
    private function insertLog(string $itemtype, int $items_id, int $idSearchOption, int $oldId, int $newId, string $date): void
    {
        global $DB;
        $DB->insert('glpi_logs', [
            'itemtype'         => $itemtype,
            'items_id'         => $items_id,
            'itemtype_link'    => '',
            'linked_action'    => 0,
            'user_name'        => 'test',
            'date_mod'         => $date,
            'id_search_option' => $idSearchOption,
            'old_value'        => (string) $oldId,
            'new_value'        => (string) $newId,
            'old_id'           => $oldId,
            'new_id'           => $newId,
        ]);
    }

    private function eventsFor(string $itemtype, int $items_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => PassportEvent::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => 'date ASC',
        ]));
    }

    public function testBackfillFromLogsReconstructsAttributionFromUserLog(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill Attribution');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill Attribution');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $this->insertLog('Computer', $computer->getID(), 70, 0, $userId, '2020-01-10 09:00:00');

        $count = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $this->assertSame(1, $count);
        $events = $this->eventsFor('Computer', $computer->getID());
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(PassportEvent::TYPE_ATTRIBUTION, (int) $event['event_type']);
        $this->assertSame($userId, (int) $event['users_id']);
        $this->assertSame('2020-01-10 09:00:00', $event['date']);
        $this->assertSame('Jean Dupont', $event['snapshot_name']);
    }

    public function testBackfillFromLogsReconstructsReturnFromUserLogWhenEnabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill Return');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'sign_on_return' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill Return');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $this->insertLog('Computer', $computer->getID(), 70, $userId, 0, '2020-02-01 09:00:00');

        $count = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $this->assertSame(1, $count);
        $event = reset($this->eventsFor('Computer', $computer->getID()));
        $this->assertSame(PassportEvent::TYPE_RETURN, (int) $event['event_type']);
        $this->assertSame($userId, (int) $event['users_id']);
    }

    public function testBackfillFromLogsSkipsReturnFromUserLogWhenDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill Return Disabled');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'sign_on_return' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill Return Disabled');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $this->insertLog('Computer', $computer->getID(), 70, $userId, 0, '2020-02-01 09:00:00');

        $count = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $this->assertSame(0, $count);
        $this->assertCount(0, $this->eventsFor('Computer', $computer->getID()));
    }

    public function testBackfillFromLogsReconstructsAttributionFromStateLog(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill State');
        $handoverState = $this->createTestState('PHPUnit Attribué');
        Config::upsertForEntity($entityId, [
            'enable_passport'  => 1,
            'sign_on_assignment' => 1,
            'handover_states'  => [$handoverState],
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill State');
        $userId = $this->createTestUser('Jean', 'Dupont');

        // L'utilisateur est deja connu (log anterieur), puis seul l'Etat change.
        $this->insertLog('Computer', $computer->getID(), 70, 0, $userId, '2020-03-01 09:00:00');
        $this->insertLog('Computer', $computer->getID(), 31, 0, $handoverState, '2020-03-05 09:00:00');

        $count = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $events = array_values($this->eventsFor('Computer', $computer->getID()));
        $this->assertCount(2, $events, 'Le log utilisateur ET le log Etat doivent chacun produire un evenement.');
        $this->assertSame(2, $count);
        $stateEvent = $events[1];
        $this->assertSame(PassportEvent::TYPE_ATTRIBUTION, (int) $stateEvent['event_type']);
        $this->assertSame($userId, (int) $stateEvent['users_id']);
        $this->assertSame('2020-03-05 09:00:00', $stateEvent['date']);
    }

    public function testBackfillFromLogsIgnoresStateLogWithoutKnownUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill State No User');
        $handoverState = $this->createTestState('PHPUnit Attribué Sans User');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'handover_states' => [$handoverState]]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill State No User');

        // Aucun log utilisateur avant ce changement d'Etat : personne a qui rattacher l'evenement.
        $this->insertLog('Computer', $computer->getID(), 31, 0, $handoverState, '2020-03-05 09:00:00');

        $count = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $this->assertSame(0, $count);
    }

    public function testBackfillFromLogsPrioritizesUserOverStateInSameGroup(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill Priority');
        $handoverState = $this->createTestState('PHPUnit Attribué Priorite');
        Config::upsertForEntity($entityId, [
            'enable_passport'    => 1,
            'sign_on_assignment' => 1,
            'handover_states'    => [$handoverState],
        ]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill Priority');
        $userId = $this->createTestUser('Jean', 'Dupont');

        // Meme sauvegarde (meme date_mod exacte) : users_id ET states_id changent
        // ensemble - ne doit produire qu'UN SEUL evenement (celui de l'affectation).
        $this->insertLog('Computer', $computer->getID(), 70, 0, $userId, '2020-04-01 10:00:00');
        $this->insertLog('Computer', $computer->getID(), 31, 0, $handoverState, '2020-04-01 10:00:00');

        $count = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $this->assertSame(1, $count, 'Un seul groupe (meme date_mod) ne doit jamais produire deux evenements.');
    }

    public function testBackfillFromLogsIsIdempotentWithoutForce(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill Idempotent');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill Idempotent');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $this->insertLog('Computer', $computer->getID(), 70, 0, $userId, '2020-01-10 09:00:00');
        PassportEvent::backfillFromLogs('Computer', $computer->getID());
        $this->insertLog('Computer', $computer->getID(), 70, $userId, 0, '2020-01-20 09:00:00'); // Ne devrait pas etre repris sans force.

        $countSecondRun = PassportEvent::backfillFromLogs('Computer', $computer->getID());

        $this->assertSame(0, $countSecondRun, 'Sans force, un item ayant deja au moins un evenement ne doit plus etre revisite.');
        $this->assertCount(1, $this->eventsFor('Computer', $computer->getID()));
    }

    public function testBackfillFromLogsWithForceAddsMissingEventsWithoutDuplicating(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill Force');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'sign_on_assignment' => 1, 'sign_on_return' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill Force');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $this->insertLog('Computer', $computer->getID(), 70, 0, $userId, '2020-01-10 09:00:00');
        PassportEvent::backfillFromLogs('Computer', $computer->getID());
        $this->insertLog('Computer', $computer->getID(), 70, $userId, 0, '2020-01-20 09:00:00');

        $countForced = PassportEvent::backfillFromLogs('Computer', $computer->getID(), true);

        $this->assertSame(1, $countForced, 'Le bouton "Forcer la recherche" doit trouver le nouvel evenement manquant.');
        $this->assertCount(2, $this->eventsFor('Computer', $computer->getID()));

        $countForcedAgain = PassportEvent::backfillFromLogs('Computer', $computer->getID(), true);
        $this->assertSame(0, $countForcedAgain, 'Un second forcage ne doit jamais dupliquer un evenement deja reconstitue.');
        $this->assertCount(2, $this->eventsFor('Computer', $computer->getID()));
    }

    public function testBackfillUserHistoryFromLogsFindsItemsAcrossTypes(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Backfill User History');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'sign_on_assignment' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Backfill User History');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $this->insertLog('Computer', $computer->getID(), 70, 0, $userId, '2020-01-10 09:00:00');

        $count = PassportEvent::backfillUserHistoryFromLogs($userId);

        $this->assertSame(1, $count);
        $events = $this->eventsFor('Computer', $computer->getID());
        $this->assertCount(1, $events);
        $this->assertSame($userId, (int) reset($events)['users_id']);
    }
}
