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

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\ConfigTransfer;
use GlpiPlugin\Assetsign\Pdf\DocumentControlHeader;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\Template;

/**
 * Issue #274 : en-tête de maîtrise documentaire (cartouche ISO) sur chaque page des PDF.
 * Assertions indépendantes de la langue : codes, révisions et numéros de page, jamais les libellés.
 */
class DocumentControlHeaderTest extends AssetsignTestCase
{
    private const CODE = 'FOR-TEST-274';

    public function testDisabledByDefault(): void
    {
        global $DB;

        // Valeur de la colonne pour toute ligne existante ou nouvelle (la configuration racine de
        // l'instance de test peut l'avoir activé : elle n'est pas lue ici).
        $this->assertSame('0', (string) $DB->listFields(Config::getTable())['doc_header_enabled']['Default']);

        $entityId = $this->createTestEntity(0, 'PHPUnit Cartouche defaut');
        Config::upsertForEntity($entityId, ['sender_name' => 'Parc fictif']);
        $this->assertSame(0, (int) Config::getForEntity($entityId)->fields['doc_header_enabled']);
        $this->assertNull(DocumentControlHeader::forEntity($entityId));

        $html = (new HandoverPdfBuilder())->renderHtml($this->createHandover($entityId));
        $this->assertStringNotContainsString('doc-header', $html);
        $this->assertStringNotContainsString(DocumentControlHeader::PAGE_COUNT_TOKEN, $html);
    }

    public function testBuildUsesTemplateAndFallsBackToEntityName(): void
    {
        $header = DocumentControlHeader::build(
            ['doc_header_enabled' => 1, 'doc_header_doc_type' => '  Enregistrement  ', 'company_name' => ''],
            'Entite fictive',
            ['doc_code' => self::CODE, 'doc_revision' => 'C', 'doc_revision_date' => '2026-03-05', 'date_creation' => '2025-01-20 10:00:00']
        );

        $this->assertNotNull($header);
        $this->assertSame('Enregistrement', $header['doc_type']);
        $this->assertSame('Entite fictive', $header['organization']);
        $this->assertSame(self::CODE, $header['code']);
        $this->assertSame('C', $header['revision']);
        $this->assertSame('05/03/2026', $header['revision_date']);
        $this->assertSame('20/01/2025', $header['created_date']);
        $this->assertFalse($header['preview']);

        $withCompany = DocumentControlHeader::build(['doc_header_enabled' => 1, 'company_name' => 'Societe Fictive'], 'Entite fictive');
        $this->assertSame('Societe Fictive', $withCompany['organization'] ?? null);
        $this->assertSame('', $withCompany['code'] ?? null);
        $this->assertSame('', $withCompany['revision_date'] ?? null);
    }

    public function testTemplateInputIsNormalized(): void
    {
        $input = Template::normalizeDocControlInput([
            'doc_code'          => '  ' . str_repeat('X', 150) . ' ',
            'doc_revision'      => ' B ',
            'doc_revision_date' => '2026-02-30',
        ]);
        $this->assertSame(DocumentControlHeader::MAX_LENGTH, mb_strlen($input['doc_code']));
        $this->assertSame('B', $input['doc_revision']);
        $this->assertSame('NULL', $input['doc_revision_date'], 'An impossible date must be stored as no date.');

        $this->assertSame('2026-02-28', Template::normalizeDocControlInput(['doc_revision_date' => '2026-02-28'])['doc_revision_date']);
        $this->assertSame(['name' => 'x'], Template::normalizeDocControlInput(['name' => 'x']), 'Absent fields must stay absent.');
    }

    public function testConfigRowKeepsTheSettings(): void
    {
        $row = Config::buildRow(['doc_header_enabled' => '1', 'doc_header_doc_type' => ' Enregistrement ']);
        $this->assertSame(1, $row['doc_header_enabled']);
        $this->assertSame('Enregistrement', $row['doc_header_doc_type']);

        $row = Config::buildRow([]);
        $this->assertSame(0, $row['doc_header_enabled']);
    }

    public function testEveryPageCarriesTheHeaderWithPageXOfY(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cartouche long');
        Config::upsertForEntity($entityId, ['doc_header_enabled' => 1, 'doc_header_doc_type' => 'Enregistrement', 'company_name' => 'Societe Fictive']);
        $templateId = $this->createTemplate($entityId, str_repeat('<p>' . str_repeat('Clause contractuelle fictive de remplissage. ', 12) . '</p>', 100), self::CODE, true);

        $builder = new HandoverPdfBuilder();
        $html = $builder->renderHtml($this->createHandover($entityId, $templateId));
        $this->assertStringContainsString('doc-header', $html);
        $this->assertStringContainsString(self::CODE, $html);
        $this->assertStringContainsString(DocumentControlHeader::PAGE_COUNT_TOKEN, $html);

        $pdf = $builder->renderPdf($html);
        // Nombre réel de pages lu dans l'arbre des pages du PDF : l'extraction texte, volontairement
        // minimale, peut perdre un flux de page (test instable avant ce correctif, « 1 sur 18 »
        // imprimé pour 17 flux extraits).
        $pageCount = $this->pdfPageCount($pdf);
        $this->assertGreaterThanOrEqual(10, $pageCount, 'The test document must span at least 10 pages ("Page X of Y" with two digits).');
        $pages = $this->extractPageTexts($pdf);
        $this->assertGreaterThanOrEqual($pageCount - 2, count($pages), 'Almost every page must be readable by the test extraction.');

        $numbers = [];
        foreach ($pages as $index => $text) {
            $label = 'extracted page ' . ($index + 1);
            $this->assertStringContainsString(self::CODE, $text, "The form code must be printed on $label.");
            $this->assertStringContainsString('Societe Fictive', $text, "The organization must be printed on $label.");
            // Type de document en capitales (text-transform du cartouche).
            $this->assertStringContainsStringIgnoringCase('Enregistrement', $text, "The document type must be printed on $label.");
            $this->assertMatchesRegularExpression(
                '/\D(\d{1,3})\D{1,15}' . $pageCount . '(\D|$)/u',
                $text,
                "Each page must show its number followed by the real total ($pageCount) on $label."
            );
            preg_match('/\D(\d{1,3})\D{1,15}' . $pageCount . '(\D|$)/u', $text, $match);
            $numbers[] = (int) $match[1];
            $this->assertStringNotContainsString(DocumentControlHeader::PAGE_COUNT_TOKEN, $text);
        }
        $this->assertContains(1, $numbers, 'The first page must show "1" followed by the total.');
        $this->assertSame($numbers, array_values(array_unique($numbers)), 'Page numbers must not repeat.');
        $this->assertLessThanOrEqual($pageCount, max($numbers), 'No page number may exceed the total.');
    }

    public function testPreviewShowsTheHeaderInlineWithUnsavedValues(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cartouche apercu');

        $html = (new HandoverPdfBuilder())->renderPreview($entityId, Assetsign::TYPE_HANDOVER, [
            'doc_header_enabled'  => true,
            'doc_header_doc_type' => 'Type fictif',
            'doc_code'            => 'FOR-APERCU-1',
            'doc_revision'        => 'D',
            'doc_revision_date'   => '2026-04-01',
        ]);

        $this->assertStringContainsString('doc-header-preview', $html);
        $this->assertStringContainsString('Type fictif', $html);
        $this->assertStringContainsString('FOR-APERCU-1', $html);
        $this->assertStringContainsString('01/04/2026', $html);
        $this->assertStringNotContainsString(DocumentControlHeader::PAGE_COUNT_TOKEN, $html, 'The preview is shown by the browser: no Dompdf token.');
        $this->assertStringNotContainsString('position: fixed; top: -28mm', $html);

        $disabled = (new HandoverPdfBuilder())->renderPreview($entityId, Assetsign::TYPE_HANDOVER, ['doc_header_enabled' => false]);
        $this->assertStringNotContainsString('doc-header', $disabled);
    }

    public function testHeaderValuesAreEscaped(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cartouche XSS');
        Config::upsertForEntity($entityId, ['doc_header_enabled' => 1, 'doc_header_doc_type' => '<b>x</b>']);
        $templateId = $this->createTemplate($entityId, '<p>Texte fictif</p>', '<script>alert(1)</script>');

        $html = (new HandoverPdfBuilder())->renderHtml($this->createHandover($entityId, $templateId));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function testTemplateDocControlFieldsAreExportedAndImported(): void
    {
        $source = $this->createTestEntity(0, 'PHPUnit Cartouche export');
        $this->createTemplate($source, '<p>Texte fictif</p>');
        Config::upsertForEntity($source, ['doc_header_enabled' => 1, 'doc_header_doc_type' => 'Enregistrement']);

        $export = ConfigTransfer::export($source, true, false);
        $this->assertSame(1, $export['config']['doc_header_enabled']);
        $this->assertSame('Enregistrement', $export['config']['doc_header_doc_type']);
        $handover = array_values(array_filter($export['templates'], static fn ($t) => $t['type'] === Assetsign::TYPE_HANDOVER))[0];
        $this->assertSame(self::CODE, $handover['doc_code']);
        $this->assertSame('B', $handover['doc_revision']);
        $this->assertSame('2026-03-05', $handover['doc_revision_date']);

        $target = $this->createTestEntity(0, 'PHPUnit Cartouche import');
        $payload = ConfigTransfer::parse((string) json_encode($export));
        ConfigTransfer::apply($payload, $target, ['logo' => false, 'templates' => true]);

        $this->assertSame(1, (int) Config::getForEntity($target)->fields['doc_header_enabled']);
        $template = Template::getDefaultFor(Assetsign::TYPE_HANDOVER, $target);
        $this->assertSame($target, (int) $template?->fields['entities_id']);
        $this->assertSame(self::CODE, $template?->fields['doc_code']);
        $this->assertSame('2026-03-05', $template?->fields['doc_revision_date']);
    }

    public function testCampaignReportCarriesTheHeader(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Cartouche campagne');
        Config::upsertForEntity($entityId, ['doc_header_enabled' => 1, 'company_name' => 'Societe Fictive']);
        $usersId = $this->createTestUser('Dominique', 'Fictif', ['entities_id' => $entityId]);
        $DB->insert('glpi_profiles_users', ['users_id' => $usersId, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 0]);
        $computer = $this->createTestComputer($entityId, 'PC-FICTIF-CAMPAGNE');
        $DB->update('glpi_computers', ['users_id' => $usersId], ['id' => $computer->getID()]);

        $campaign = \GlpiPlugin\Assetsign\Campaign::launch([
            'name'          => 'Campagne fictive',
            'entities_id'   => $entityId,
            'is_recursive'  => false,
            'groups_id'     => 0,
            'profiles_id'   => 0,
            'date_deadline' => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->assertNotNull($campaign);

        $pages = $this->extractPageTexts($campaign->renderReportPdf());
        $this->assertNotEmpty($pages);
        foreach ($pages as $index => $text) {
            $this->assertStringContainsString('Societe Fictive', $text, 'Page ' . ($index + 1) . ' must carry the header.');
            $this->assertMatchesRegularExpression('/\D' . ($index + 1) . '\D{1,15}' . count($pages) . '(\D|$)/u', $text);
        }
    }

    public function testImportRejectsAnInvalidRevisionDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigTransfer::parse((string) json_encode([
            'format_version' => 1,
            'plugin'         => 'assetsign',
            'templates'      => [['type' => Assetsign::TYPE_HANDOVER, 'name' => 'Gabarit fictif', 'doc_revision_date' => 'demain']],
        ]));
    }

    private function createTemplate(int $entityId, string $content, string $code = self::CODE, bool $contentAsCharterToo = false): int
    {
        $template = new Template();
        $id = (int) $template->add([
            'entities_id'       => $entityId,
            'name'              => 'Gabarit fictif #274',
            'type'              => Assetsign::TYPE_HANDOVER,
            'content'           => $content,
            'include_content'   => 1,
            'charter_content'   => $contentAsCharterToo ? $content : '',
            'include_charter'   => (int) $contentAsCharterToo,
            'is_default'        => 1,
            'is_active'         => 1,
            'doc_code'          => $code,
            'doc_revision'      => 'B',
            'doc_revision_date' => '2026-03-05',
        ]);
        $this->assertGreaterThan(0, $id);
        return $id;
    }

    private function createHandover(int $entityId, int $templateId = 0): Assetsign
    {
        $computer = $this->createTestComputer($entityId, 'PC-FICTIF-274');
        $assetsign = new Assetsign();
        $id = (int) $assetsign->add([
            'entities_id' => $entityId,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => 2,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => Assetsign::STATUS_SIGNED,
            'plugin_assetsign_templates_id' => $templateId,
        ]);
        $assetsign->getFromDB($id);
        return $assetsign;
    }

    /**
     * Nombre réel de pages, lu dans l'arbre des pages du PDF (/Count n). Plus sûr que le nombre de
     * flux extraits par extractPageTexts() : un flux de page dont le contenu binaire contient une fin
     * de ligne suivie de « endstream » est coupé par l'expression régulière et peut manquer.
     */
    private function pdfPageCount(string $pdf): int
    {
        preg_match_all('/\/Count\s+(\d+)/', $pdf, $counts);
        return $counts[1] === [] ? 0 : max(array_map('intval', $counts[1]));
    }

    /**
     * Texte de chaque page d'un PDF produit par Dompdf (moteur CPDF, police DejaVu : chaînes en
     * UTF-16BE dans des flux compressés). Suffisant pour ces assertions, pas un lecteur PDF général.
     *
     * @return list<string>
     */
    private function extractPageTexts(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $pages = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false || !str_contains($content, ' Tf ')) {
                continue;
            }
            preg_match_all('/\[\((.*?)\)\]\s*TJ/s', $content, $strings);
            $text = '';
            foreach ($strings[1] as $raw) {
                $bytes = preg_replace_callback(
                    '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
                    static fn (array $m): string => match ($m[1]) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                        '(', ')', '\\' => $m[1],
                        default => chr(octdec($m[1])),
                    },
                    $raw
                );
                $text .= mb_convert_encoding((string) $bytes, 'UTF-8', 'UTF-16BE') . ' ';
            }
            $pages[] = $text;
        }
        return $pages;
    }
}
