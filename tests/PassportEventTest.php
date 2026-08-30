<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\Assetsign;

/**
 * Couvre le socle du Passeport materiel (cf. ROADMAP.md, "Vision produit a long terme") :
 * enregistrement d'evenements depuis Assetsign/Maintenance (jamais duplique, jamais reecrit
 * apres coup), regroupement en "vies", et anonymisation du snapshot beneficiaire au-dela du
 * delai configure.
 */
class PassportEventTest extends AssetsignTestCase
{
    private function eventsFor(string $itemtype, int $items_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => PassportEvent::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => 'date ASC',
        ]));
    }

    public function testHandleItemAssignmentRecordsAttributionEvent(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Attribution');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Attribution');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Assetsign::handleItemAssignment($computer);

        $events = $this->eventsFor('Computer', $computer->getID());
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(PassportEvent::TYPE_ATTRIBUTION, (int) $event['event_type']);
        $this->assertSame($userId, (int) $event['users_id']);
        // formatUserName() suit le reglage GLPI names_format (par defaut
        // User::REALNAME_BEFORE, cf. DbUtils::formatUserName()) : "Dupont Jean",
        // pas la simple concatenation firstname+realname utilisee avant le
        // correctif du nom beneficiaire/technicien vide (CHANGELOG v2.0.2).
        $this->assertSame('Dupont Jean', $event['snapshot_name']);
        $this->assertSame(Assetsign::class, $event['source_itemtype']);
    }

    public function testCreateManualDonRecordsDonEventWithExternalSnapshot(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Don');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Don');

        Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 0, [
            'beneficiary_type' => Assetsign::BENEFICIARY_EXTERNAL,
            'external_name'    => 'Association Externe',
            'external_contact' => 'contact@externe.example',
        ]);

        $events = $this->eventsFor('Computer', $computer->getID());
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(PassportEvent::TYPE_DON, (int) $event['event_type']);
        $this->assertSame(1, (int) $event['snapshot_is_external']);
        $this->assertSame('Association Externe', $event['snapshot_name']);
        $this->assertSame(0, (int) $event['users_id']);
    }

    /**
     * Destruction (issue #78, "fin de vie structuree") : meme motif exact que
     * testCreateManualDonRecordsDonEventWithExternalSnapshot() ci-dessus.
     */
    public function testCreateManualDestructionRecordsDestructionEvent(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Destruction');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Destruction');

        Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DESTRUCTION, 2, [
            'provider_name' => 'Prestataire Exemple',
        ]);

        $events = $this->eventsFor('Computer', $computer->getID());
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(PassportEvent::TYPE_DESTRUCTION, (int) $event['event_type']);
        $this->assertSame(Assetsign::class, $event['source_itemtype']);
    }

    public function testCreateWithChecklistRecordsMaintenanceEventWithoutSnapshot(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Maintenance');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Maintenance');

        Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], 'Contrôle PHPUnit');

        $events = $this->eventsFor('Computer', $computer->getID());
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(PassportEvent::TYPE_MAINTENANCE, (int) $event['event_type']);
        $this->assertSame('', $event['snapshot_name']);
        $this->assertSame(Maintenance::class, $event['source_itemtype']);
    }

    /**
     * Bouton « Imprimer une étiquette QR code » (cf. ROADMAP.md V3, issue #82) :
     * affiché sur l'onglet Passeport matériel uniquement quand Config::enable_qr_label
     * est actif pour l'entité (réglage par défaut, cf. Config::DEFAULTS) — jamais un
     * réglage global, chaque entité peut le désactiver indépendamment.
     */
    public function testShowForItemDisplaysQrLabelButtonWhenEnabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport QrLabel Enabled');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport QrLabel Enabled');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Imprimer une étiquette QR code', 'assetsign'), $html);
        // '&' est echappe en '&amp;' par Twig dans un attribut href (auto-echappement,
        // comportement attendu) : verifie les deux parametres separement plutot
        // qu'une chaine de requete brute avec un '&' litteral.
        $this->assertStringContainsString('/front/qrlabel.php?itemtype=Computer', $html);
        $this->assertStringContainsString('items_id=' . $computer->getID(), $html);
        $this->assertNoStrayNumericTextNode($html, 'passport_tab.html.twig (bouton QR code actif)');
    }

    public function testShowForItemHidesQrLabelButtonWhenDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport QrLabel Disabled');
        Config::upsertForEntity($entityId, ['enable_qr_label' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport QrLabel Disabled');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(__('Imprimer une étiquette QR code', 'assetsign'), $html);
    }

    public function testGetLivesForItemGroupsConsecutiveAttributionsBySameUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Lives');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Lives');
        $firstUserId = $this->createTestUser('Jean', 'Dupont');
        $secondUserId = $this->createTestUser('Marie', 'Martin');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $firstUserId;
        Assetsign::handleItemAssignment($computer);

        // Reaffectation au MEME utilisateur : ne doit pas ouvrir une nouvelle vie.
        $computer->getFromDB($computer->getID());
        $computer->oldvalues = ['users_id' => $firstUserId];
        $computer->fields['users_id'] = $firstUserId;
        Assetsign::handleItemAssignment($computer);

        $computer->getFromDB($computer->getID());
        $computer->oldvalues = ['users_id' => $firstUserId];
        $computer->fields['users_id'] = $secondUserId;
        Assetsign::handleItemAssignment($computer);

        $lives = PassportEvent::getLivesForItem('Computer', $computer->getID());
        $this->assertCount(2, $lives, '2 beneficiaires distincts doivent produire 2 vies, pas 3.');
        $this->assertSame($firstUserId, $lives[0]['users_id']);
        $this->assertNotNull($lives[0]['end'], 'La premiere vie doit avoir ete cloturee par le debut de la seconde.');
        $this->assertSame($secondUserId, $lives[1]['users_id']);
        $this->assertNull($lives[1]['end'], 'La vie en cours ne doit pas avoir de date de fin.');
    }

    public function testAnonymizeOldSnapshotsClearsNameAndEmailButKeepsUsersId(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Anonymize');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Anonymize');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Assetsign::handleItemAssignment($computer);

        $events = $this->eventsFor('Computer', $computer->getID());
        $eventId = (int) reset($events)['id'];

        // Recule la date de l'evenement au-dela du delai par defaut (3 ans).
        $DB->update(PassportEvent::getTable(), ['date' => date('Y-m-d H:i:s', strtotime('-4 years'))], ['id' => $eventId]);

        PassportEvent::anonymizeOldSnapshots();

        $event = new PassportEvent();
        $event->getFromDB($eventId);
        $this->assertSame('', $event->fields['snapshot_name']);
        $this->assertSame('', $event->fields['snapshot_email']);
        $this->assertSame(1, (int) $event->fields['is_anonymized']);
        $this->assertSame($userId, (int) $event->fields['users_id'], 'users_id ne doit jamais etre efface par l\'anonymisation.');
    }

    public function testAnonymizeOldSnapshotsSkipsWhenRetentionDisabled(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Passport Anonymize Disabled');
        Config::upsertForEntity($entityId, ['passport_retention_years' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Passport Anonymize Disabled');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Assetsign::handleItemAssignment($computer);

        $events = $this->eventsFor('Computer', $computer->getID());
        $eventId = (int) reset($events)['id'];
        $DB->update(PassportEvent::getTable(), ['date' => date('Y-m-d H:i:s', strtotime('-20 years'))], ['id' => $eventId]);

        PassportEvent::anonymizeOldSnapshots();

        $event = new PassportEvent();
        $event->getFromDB($eventId);
        $this->assertNotSame('', $event->fields['snapshot_name'], 'Delai desactive (0) : ne doit jamais anonymiser.');
    }
}
