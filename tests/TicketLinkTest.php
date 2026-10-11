<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #183 : remise de materiel depuis un ticket (demande de materiel) et suivi du ticket a la
 * signature.
 */
final class TicketLinkTest extends AssetsignTestCase
{
    private int $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requester = $this->createTestUser('Demandeur', 'Ticket');
        $config = Config::getForEntity(0);
        $config->update([
            'id' => $config->getID(), 'sign_on_assignment' => 1, 'sign_on_reassignment' => 1,
            'resolve_ticket_on_signature' => 1, 'assign_handed_items' => 0,
            'managed_itemtypes' => json_encode(['Computer', 'Monitor', 'Peripheral', 'Phone']),
        ]);
    }

    private function ticket(): \Ticket
    {
        $ticket = new \Ticket();
        $ticket->getFromDB((int) $ticket->add([
            'name' => 'PHPUnit demande de poste', 'content' => 'Besoin d\'un poste.', 'entities_id' => 0,
            '_users_id_requester' => $this->requester,
        ]));
        return $ticket;
    }

    public function testAHandoverFromATicketIsADraftLinkedToTheTicket(): void
    {
        $ticket = $this->ticket();
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');

        $sheet = Assetsign::createFromTicket($ticket, 'Computer', $computer->getID(), $this->requester);

        $this->assertSame(Assetsign::STATUS_DRAFT, (int) $sheet->fields['status']);
        $this->assertSame($ticket->getID(), (int) $sheet->fields['tickets_id']);
        $computer->getFromDB($computer->getID());
        $this->assertSame($this->requester, (int) $computer->fields['users_id']);
        $this->assertCount(1, (new \Item_Ticket())->find(['tickets_id' => $ticket->getID(), 'itemtype' => 'Computer', 'items_id' => $computer->getID()]));
    }

    public function testTheBeneficiaryMustBeARequester(): void
    {
        $this->expectException(\RuntimeException::class);
        Assetsign::createFromTicket($this->ticket(), 'Computer', $this->createTestComputer(0, 'PHPUNIT-PC')->getID(), $this->createTestUser('Pas', 'Demandeur'));
    }

    public function testAnItemAlreadyAssignedToTheRequesterIsRefused(): void
    {
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        global $DB;
        $DB->update(\Computer::getTable(), ['users_id' => $this->requester], ['id' => $computer->getID()]);

        $this->expectException(\RuntimeException::class);
        Assetsign::createFromTicket($this->ticket(), 'Computer', $computer->getID(), $this->requester);
    }

    public function testSigningAddsAFollowupWithTheDocumentAndSolvesTheTicket(): void
    {
        $ticket = $this->ticket();
        $sheet = Assetsign::createFromTicket($ticket, 'Computer', $this->createTestComputer(0, 'PHPUNIT-PC')->getID(), $this->requester);
        $sheet->sendDraft();
        $sheet->getFromDB($sheet->getID());
        $unsigned = new \Document();
        $unsigned->getFromDB((int) $sheet->fields['document_id_unsigned']);
        $tmp = 'phpunit-signe-' . $sheet->getID() . '.pdf';
        copy(GLPI_DOC_DIR . '/' . $unsigned->fields['filepath'], GLPI_TMP_DIR . '/' . $tmp);

        $sheet->markSigned($tmp, ['ip' => '127.0.0.1', 'user_agent' => 'phpunit'], false);

        $followups = (new \ITILFollowup())->find(['itemtype' => 'Ticket', 'items_id' => $ticket->getID()]);
        $this->assertNotEmpty($followups);
        $this->assertCount(1, (new \Document_Item())->find(['itemtype' => 'Ticket', 'items_id' => $ticket->getID()]), 'document signe joint');
        $ticket->getFromDB($ticket->getID());
        $this->assertSame(\CommonITILObject::SOLVED, (int) $ticket->fields['status']);
    }

    public function testASheetCanBeLinkedOnlyToATicketOfItsBeneficiary(): void
    {
        $ticket = $this->ticket();
        $sheet = new Assetsign();
        $sheet->getFromDB((int) $sheet->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $this->createTestComputer(0, 'PHPUNIT-PC')->getID(),
            'users_id' => $this->requester, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
        ]));

        $this->assertArrayHasKey($ticket->getID(), $sheet->getBeneficiaryOpenTickets());
        $sheet->linkTicket($ticket->getID());
        $sheet->getFromDB($sheet->getID());
        $this->assertSame($ticket->getID(), (int) $sheet->fields['tickets_id']);

        $other = new Assetsign();
        $other->getFromDB((int) $other->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $this->createTestComputer(0, 'PHPUNIT-PC-2')->getID(),
            'users_id' => 2, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
        ]));
        $this->expectException(\RuntimeException::class);
        $other->linkTicket($ticket->getID());
    }
}
