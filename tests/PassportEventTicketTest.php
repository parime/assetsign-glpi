<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\PassportEvent;

/**
 * Couvre la fusion des tickets lies au materiel dans la frise du Passeport
 * materiel (cf. ROADMAP.md, tableau V1) : lecture seule, jamais stockee dans
 * glpi_plugin_remise_events, et toujours filtree par les droits REELS du
 * lecteur courant sur chaque ticket (jamais un simple droit generique).
 */
class PassportEventTicketTest extends RemiseTestCase
{
    private function createTestTicket(int $entitiesId, string $name, string $date, int $status = 1): int
    {
        global $DB;
        static $nextId = null;
        if ($nextId === null) {
            $nextId = random_int(900000, 989999);
        }
        $id = $nextId++;
        $DB->insert('glpi_tickets', [
            'id'          => $id,
            'name'        => $name,
            'entities_id' => $entitiesId,
            'date'        => $date,
            'status'      => $status,
        ]);
        return $id;
    }

    private function linkTicketToItem(int $ticketId, string $itemtype, int $items_id): void
    {
        global $DB;
        $DB->insert('glpi_items_tickets', [
            'tickets_id' => $ticketId,
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
        ]);
    }

    public function testShowForItemMergesLinkedTicketIntoTimeline(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Ticket Merge');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'show_linked_tickets' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Ticket Merge');
        $ticketId = $this->createTestTicket($entityId, 'PHPUnit Ecran casse', '2024-05-10 10:00:00');
        $this->linkTicketToItem($ticketId, 'Computer', $computer->getID());

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Ticket', 'remise'), $html);
        $this->assertStringContainsString('PHPUnit Ecran casse', $html);
        $this->assertStringContainsString('id=' . $ticketId, $html);
        $this->assertNoStrayNumericTextNode($html, 'La frise avec ticket lié doit se rendre sans fuite Twig.');
    }

    public function testShowForItemHidesLinkedTicketsWhenDisabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Ticket Disabled');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'show_linked_tickets' => 0]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Ticket Disabled');
        $ticketId = $this->createTestTicket($entityId, 'PHPUnit Ticket Masqué', '2024-05-10 10:00:00');
        $this->linkTicketToItem($ticketId, 'Computer', $computer->getID());

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('PHPUnit Ticket Masqué', $html);
    }

    public function testShowForItemIgnoresUnrelatedTickets(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Ticket Unrelated');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'show_linked_tickets' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Ticket Unrelated');
        $otherComputer = $this->createTestComputer($entityId, 'PHPUnit PC Ticket Autre');
        $ticketId = $this->createTestTicket($entityId, 'PHPUnit Ticket Autre Materiel', '2024-05-10 10:00:00');
        $this->linkTicketToItem($ticketId, 'Computer', $otherComputer->getID());

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('PHPUnit Ticket Autre Materiel', $html);
    }

    public function testShowForItemHidesTicketOutsideCurrentEntityAccess(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Ticket No Access');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4], 'show_linked_tickets' => 1]);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Ticket No Access');
        // Entite volontairement JAMAIS enregistree via createTestEntity() (donc
        // absente de $_SESSION['glpiactiveentities']) : simule un ticket sur
        // lequel le lecteur courant n'a reellement aucun acces, malgre le droit
        // generique 'ticket'=READ verifie en premier dans getLinkedTicketPseudoEvents().
        $ticketId = $this->createTestTicket(999999, 'PHPUnit Ticket Hors Acces', '2024-05-10 10:00:00');
        $this->linkTicketToItem($ticketId, 'Computer', $computer->getID());

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('PHPUnit Ticket Hors Acces', $html);
    }
}
