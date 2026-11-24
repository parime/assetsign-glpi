<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\CustodyCaseFormController;
use GlpiPlugin\Assetsign\CustodyCase;
use GlpiPlugin\Assetsign\CustodyEvent;
use GlpiPlugin\Assetsign\Profile;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #326 : chaîne de possession d'un matériel saisi (étapes signées par les deux personnes,
 * PDF versionné avec l'empreinte des versions précédentes, champs signés immuables, droit dédié,
 * liens ticket et matériel, fonctionnement sans le plugin GRC). Données fictives ; les assertions
 * portent sur les données saisies et les empreintes, jamais sur des libellés traduits.
 */
class CustodyCaseTest extends AssetsignTestCase
{
    use PdfTextExtraction;

    private const SEAL = 'SCELLE-FICTIF-17';
    private const SERIAL = 'SN-FICTIF-PC-0042';

    private int $savedRight = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedRight = (int) ($_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] ?? 0);
        $_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] = ALLSTANDARDRIGHT;
    }

    protected function tearDown(): void
    {
        $_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] = $this->savedRight;
        parent::tearDown();
    }

    /** @return array{0: \Computer, 1: int, 2: int, 3: int} matériel, entité, utilisateur, technicien */
    private function seizedComputer(string $name): array
    {
        $entity = $this->createTestEntity(0, 'PHPUnit Saisie ' . $name);
        $computer = $this->createTestComputer($entity, $name);
        $user = $this->createTestUser('Camille', 'Fictif');
        $tech = $this->createTestUser('Dominique', 'Technicien');
        $computer->update(['id' => $computer->getID(), 'serial' => self::SERIAL, 'users_id' => $user]);
        $computer->getFromDB($computer->getID());
        return [$computer, $entity, $user, $tech];
    }

    /** @return array<string, string|int> */
    private static function openInput(int $from, int $to, array $extra = []): array
    {
        return $extra + [
            'event_date'     => date('Y-m-d H:i:s', time() - 3600),
            'reason'         => 'Poste fictif soupçonné compromis',
            'condition_note' => 'Allumé, écran verrouillé',
            'seal_number'    => self::SEAL,
            'users_id_from'  => $from,
            'users_id_to'    => $to,
            'locations_id'   => 0,
            'storage'        => 'Armoire forte fictive',
        ];
    }

    private function openCase(\Computer $computer, int $from, int $to, array $extra = [], array $photos = []): CustodyCase
    {
        return CustodyCase::open($computer, self::openInput($from, $to, $extra), self::signature(), self::signature(2), $photos);
    }

    private static function documentBinary(int $documentsId): string
    {
        $document = new \Document();
        self::assertTrue($document->getFromDB($documentsId));
        return (string) file_get_contents(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
    }

    private function pdfText(int $documentsId): string
    {
        // Sans espaces : une empreinte longue est coupée sur plusieurs lignes dans le PDF.
        return (string) preg_replace('/\s+/', '', implode(' ', $this->extractPageTexts(self::documentBinary($documentsId))));
    }

    public function testOpeningRecordsTheSignedSeizureAndTheFirstPdfVersion(): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-1');

        $case = $this->openCase($computer, $user, $tech);

        $this->assertSame(CustodyCase::STATUS_OPEN, (int) $case->fields['status']);
        $this->assertSame($tech, (int) $case->fields['users_id_holder']);
        $events = CustodyEvent::getForCase($case->getID());
        $this->assertCount(1, $events);
        $this->assertSame(CustodyEvent::TYPE_SEIZURE, (int) $events[0]['event_type']);
        $this->assertSame($user, (int) $events[0]['users_id_from']);
        $this->assertSame($tech, (int) $events[0]['users_id_to']);
        // Les deux signatures sont enregistrées, avec l'empreinte de leur fichier.
        foreach (['from', 'to'] as $side) {
            $binary = self::documentBinary((int) $events[0]['sig_' . $side . '_documents_id']);
            $this->assertSame(hash('sha256', $binary), $events[0]['sig_' . $side . '_hash']);
        }
        $this->assertNotSame($events[0]['sig_from_hash'], $events[0]['sig_to_hash']);

        // Version 1 du PDF : empreinte du fichier enregistré, sur la fiche et sur l'étape.
        $this->assertSame(1, (int) $case->fields['pdf_version']);
        $this->assertSame(hash('sha256', self::documentBinary((int) $case->fields['documents_id'])), $case->fields['pdf_hash']);
        $this->assertSame((int) $case->fields['documents_id'], (int) $events[0]['documents_id']);
        $this->assertSame($case->fields['pdf_hash'], $events[0]['pdf_hash']);

        $text = $this->pdfText((int) $case->fields['documents_id']);
        $this->assertStringContainsString(self::SEAL, $text);
        $this->assertStringContainsString(self::SERIAL, $text);
        $this->assertStringContainsString($events[0]['sig_from_hash'], $text);

        // PDF lié au matériel (onglet Documents) et à la fiche.
        foreach ([['Computer', $computer->getID()], [CustodyCase::class, $case->getID()]] as [$itemtype, $itemsId]) {
            $this->assertSame(1, countElementsInTable('glpi_documents_items', [
                'documents_id' => (int) $case->fields['documents_id'], 'itemtype' => $itemtype, 'items_id' => $itemsId,
            ]), $itemtype);
        }
    }

    public function testEachStepAddsAVersionThatQuotesThePreviousHashes(): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-2');
        $investigator = $this->createTestUser('Alex', 'Enqueteur');
        $case = $this->openCase($computer, $user, $tech);
        $firstDocument = (int) $case->fields['documents_id'];
        $firstHash = (string) $case->fields['pdf_hash'];

        $case->transfer([
            'event_date' => date('Y-m-d H:i:s', time() - 1800), 'users_id_to' => $investigator,
            'locations_id' => 0, 'storage' => 'Coffre fictif', 'comment' => 'Remise pour analyse',
        ], self::signature(3), self::signature(4));

        $this->assertSame(2, (int) $case->fields['pdf_version']);
        $this->assertSame($investigator, (int) $case->fields['users_id_holder']);
        $this->assertSame('Coffre fictif', $case->fields['storage']);
        $this->assertNotSame($firstDocument, (int) $case->fields['documents_id']);
        // La version 1 reste intacte ; la version 2 cite son empreinte.
        $this->assertSame($firstHash, hash('sha256', self::documentBinary($firstDocument)));
        $this->assertStringContainsString($firstHash, $this->pdfText((int) $case->fields['documents_id']));
        $secondHash = (string) $case->fields['pdf_hash'];

        // Clôture par restitution à l'utilisateur, signée par le détenteur et par lui.
        $case->close([
            'closure' => CustodyCase::CLOSURE_RETURNED, 'event_date' => date('Y-m-d H:i:s', time() - 60),
            'users_id_to' => $user, 'locations_id' => 0,
        ], self::signature(5), self::signature(6));

        $this->assertSame(CustodyCase::STATUS_CLOSED, (int) $case->fields['status']);
        $this->assertSame(CustodyCase::CLOSURE_RETURNED, $case->fields['closure']);
        $this->assertSame(3, (int) $case->fields['pdf_version']);
        $events = CustodyEvent::getForCase($case->getID());
        $this->assertSame([CustodyEvent::TYPE_SEIZURE, CustodyEvent::TYPE_TRANSFER, CustodyEvent::TYPE_CLOSURE], array_map('intval', array_column($events, 'event_type')));
        $this->assertSame($investigator, (int) $events[2]['users_id_from']);
        $text = $this->pdfText((int) $case->fields['documents_id']);
        $this->assertStringContainsString($firstHash, $text);
        $this->assertStringContainsString($secondHash, $text);

        // Une saisie clôturée ne change plus.
        $this->expectException(\RuntimeException::class);
        $case->transfer(['users_id_to' => $tech], self::signature(7), self::signature(8));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: bool}> */
    public static function invalidOpenings(): iterable
    {
        yield 'motif manquant' => [['reason' => '  '], false];
        yield 'date future' => [['event_date' => date('Y-m-d H:i:s', time() + 86400)], false];
        yield 'signature vide' => [[], true];
        yield 'même personne des deux côtés' => [['same_person' => true], false];
        yield 'personne absente' => [['users_id_to' => 0, 'name_to' => ''], false];
    }

    #[DataProvider('invalidOpenings')]
    public function testInvalidOpeningIsRejectedBeforeAnyWrite(array $override, bool $blankSignature): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-3');
        if (isset($override['same_person'])) {
            $override = ['users_id_to' => $user];
        }
        try {
            CustodyCase::open($computer, $override + self::openInput($user, $tech), $blankSignature ? '' : self::signature(), self::signature(2));
            $this->fail('La saisie aurait dû être refusée.');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame(0, countElementsInTable(CustodyCase::getTable(), ['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
    }

    public function testSignedFieldsAreNeverRewrittenAndTheCaseCannotBeDeleted(): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-4');
        $case = $this->openCase($computer, $user, $tech);

        $case->update(['id' => $case->getID(), 'reason' => 'Motif réécrit', 'seal_number' => 'AUTRE', 'items_id' => 1]);
        $case->getFromDB($case->getID());
        $this->assertSame(self::SEAL, $case->fields['seal_number']);
        $this->assertSame($computer->getID(), (int) $case->fields['items_id']);
        $this->assertNotSame('Motif réécrit', $case->fields['reason']);

        $this->assertFalse($case->delete(['id' => $case->getID()], true));
        $this->assertTrue($case->getFromDB($case->getID()));
        $this->assertFalse(CustodyCase::canPurge());
    }

    public function testTicketContextListsItsItemsAndReceivesThePdf(): void
    {
        [$computer, $entity, $user, $tech] = $this->seizedComputer('PC-SAISIE-5');
        $ticket = new \Ticket();
        $ticketId = (int) $ticket->add(['name' => 'Incident fictif', 'content' => 'Poste fictif compromis', 'entities_id' => $entity]);
        $this->assertGreaterThan(0, $ticketId);
        (new \Item_Ticket())->add(['tickets_id' => $ticketId, 'itemtype' => 'Computer', 'items_id' => $computer->getID()]);
        $ticket->getFromDB($ticketId);

        $this->assertSame(['Computer:' . $computer->getID()], array_column(CustodyCase::linkedItems($ticket), 'value'));

        $case = (new CustodyCaseFormController())->open([
            'item' => 'Computer:' . $computer->getID(), 'signature_from' => self::signature(), 'signature_to' => self::signature(2),
        ] + array_map('strval', self::openInput($user, $tech, ['tickets_id' => $ticketId])));

        $this->assertSame($ticketId, (int) $case->fields['tickets_id']);
        $this->assertSame(1, countElementsInTable('glpi_documents_items', [
            'documents_id' => (int) $case->fields['documents_id'], 'itemtype' => 'Ticket', 'items_id' => $ticketId,
        ]));
        $this->assertNotSame('', (new CustodyCase())->getTabNameForItem($ticket));

        ob_start();
        CustodyCase::showForItem($ticket);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(self::SEAL, $html);
        $this->assertStringContainsString('value="Computer:' . $computer->getID() . '"', $html);
    }

    public function testWorksWithoutTheGrcPlugin(): void
    {
        if (CustodyCase::isGrcAvailable()) {
            $this->markTestSkipped('Le plugin GRC est actif sur cette instance.');
        }
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-6');
        // Un identifiant d'incident sans le plugin est refusé, jamais enregistré.
        try {
            $this->openCase($computer, $user, $tech, ['incident_id' => 42]);
            $this->fail('Un incident inconnu aurait dû être refusé.');
        } catch (\RuntimeException) {
        }
        $case = $this->openCase($computer, $user, $tech);
        $this->assertNotContains(CustodyCase::GRC_INCIDENT_CLASS, array_column($case->getDocumentTargets(), 0));

        ob_start();
        CustodyCase::showForItem($computer);
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('name="incident_id"', $html);
        $this->assertStringContainsString('data-assetsign-pads', $html);
    }

    public function testTheDedicatedRightIsRequired(): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-7');
        $this->openCase($computer, $user, $tech);
        $this->assertNotSame('', (new CustodyCase())->getTabNameForItem($computer));

        // Le droit des remises ne suffit pas.
        $_SESSION['glpiactiveprofile'][Profile::RIGHT_ASSETSIGN] = ALLSTANDARDRIGHT;
        $_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] = 0;
        $this->assertSame('', (new CustodyCase())->getTabNameForItem($computer));
        $this->assertFalse(CustodyCase::displayTabContentForItem($computer));
        $this->assertFalse(CustodyCase::canView());
        $this->assertFalse(CustodyCase::canCreate());
    }

    public function testPhotosAreStoredWithTheirHashAndQuotedInThePdf(): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-8');
        $path = (string) tempnam(sys_get_temp_dir(), 'custody');
        $image = imagecreatetruecolor(120, 80);
        imagefilledrectangle($image, 0, 0, 120, 80, (int) imagecolorallocate($image, 200, 30, 30));
        imagejpeg($image, $path);

        $case = $this->openCase($computer, $user, $tech, [], [['path' => $path]]);
        unlink($path);

        $photos = $case->getPhotos();
        $this->assertCount(1, $photos);
        $this->assertSame(hash('sha256', self::documentBinary($photos[0]['documents_id'])), $photos[0]['sha256']);
        $this->assertStringContainsString($photos[0]['sha256'], $this->pdfText((int) $case->fields['documents_id']));
    }

    public function testFormPageRendersWithoutErrors(): void
    {
        [$computer, , $user, $tech] = $this->seizedComputer('PC-SAISIE-9');
        $case = $this->openCase($computer, $user, $tech);

        ob_start();
        $case->showForm($case->getID());
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(self::SEAL, $html);
        $this->assertStringContainsString((string) $case->fields['pdf_hash'], $html);
        $this->assertStringContainsString('name="transfer"', $html);
        $this->assertStringContainsString('name="close"', $html);
        $this->assertSame(2, substr_count($html, 'data-assetsign-pads-required'));
    }

    /** Tracé fictif, différent selon $variant (deux signatures distinctes). */
    private static function signature(int $variant = 1): string
    {
        $image = imagecreatetruecolor(300, 100);
        imagefilledrectangle($image, 0, 0, 300, 100, (int) imagecolorallocate($image, 255, 255, 255));
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imageline($image, 10, 10, 290, 90, $black);
        imageline($image, 10, 90, 290, 10, $black);
        imageline($image, 10 + $variant * 20, 10, 10 + $variant * 20, 90, $black);
        ob_start();
        imagepng($image);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }
}
