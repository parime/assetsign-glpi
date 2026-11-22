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

use PHPUnit\Framework\TestCase;

/**
 * Issue #393 : la lecture des PDF des tests ne doit perdre aucune page, quels que soient les octets
 * des flux compressés (qui changent avec la date d'impression). Ancienne lecture par expression
 * régulière « stream\r?\n(.*?)\r?\nendstream » : un flux compressé finissant par \r, ou contenant
 * « \nendstream », était coupé, gzuncompress() échouait et la page disparaissait.
 */
final class PdfTextExtractionTest extends TestCase
{
    use PdfTextExtraction;

    public function testAStreamEndingWithACarriageReturnIsStillRead(): void
    {
        $data = $this->compressedEndingWith("\r");
        $pdf = $this->onePagePdf($data);

        // Ce que faisait l'ancienne lecture : le flux est coupé et ne se décompresse plus.
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $old);
        $this->assertFalse(@gzuncompress($old[1][0]), 'Reproduction of the old failure.');

        $this->assertSame(1, $this->pdfPageCount($pdf), $this->describePdf($pdf));
        $pages = $this->extractPageTexts($pdf);
        $this->assertCount(1, $pages, $this->describePdf($pdf));
        $this->assertStringContainsString('Page fictive', $pages[0]);
    }

    public function testAStreamContainingEndstreamIsStillRead(): void
    {
        $data = gzcompress("BT /F1 12 Tf [(Page fictive)] TJ ET\n");
        $this->assertNotFalse($data);
        // Le motif piège est placé dans les données binaires d'un flux qui précède celui de la page.
        $pdf = $this->onePagePdf($data, "\nendstream\n");

        $this->assertSame(1, $this->pdfPageCount($pdf));
        $this->assertCount(1, $this->extractPageTexts($pdf), $this->describePdf($pdf));
    }

    public function testPagesInsideStreamDataAreNotCounted(): void
    {
        $data = gzcompress("BT /F1 12 Tf [(Page fictive)] TJ ET\n");
        $this->assertNotFalse($data);
        $pdf = $this->onePagePdf($data, '/Type /Page /Count 7');

        $this->assertSame(1, $this->pdfPageCount($pdf));
    }

    public function testTheDiagnosticShowsTheStartOfThePdf(): void
    {
        $pdf = $this->onePagePdf((string) gzcompress('BT /F1 12 Tf [(x)] TJ ET'));
        $diagnostic = $this->describePdf($pdf);
        $this->assertStringContainsString('%PDF-1.7', $diagnostic);
        $this->assertStringContainsString((string) strlen($pdf), $diagnostic);
    }

    /**
     * Données compressées (zlib) d'un flux de page dont le dernier octet est $last.
     */
    private function compressedEndingWith(string $last): string
    {
        for ($i = 0; $i < 100000; $i++) {
            $data = gzcompress("BT /F1 12 Tf [(Page fictive $i)] TJ ET\n");
            if ($data !== false && str_ends_with($data, $last)) {
                return $data;
            }
        }
        $this->fail('No compressed stream ending with the expected byte.');
    }

    /**
     * PDF d'une page au format de Dompdf (CPDF) : flux de contenu avec /Length, arbre des pages.
     * $raw, s'il est donné, est inséré tel quel au milieu des données du flux (non décompressable
     * alors, mais sa longueur reste déclarée).
     */
    private function onePagePdf(string $data, string $raw = ''): string
    {
        $streams = [$data];
        if ($raw !== '') {
            // Second flux, binaire, contenant le motif piège : il précède le flux de la page.
            array_unshift($streams, 'x' . $raw . 'y');
        }
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Contents " . (4 + count($streams) - 1) . " 0 R >>\nendobj\n";
        foreach ($streams as $index => $stream) {
            $pdf .= (4 + $index) . " 0 obj\n<< /Filter /FlateDecode\n/Length " . strlen($stream) . " >>\nstream\n"
                . $stream . "\nendstream\nendobj\n";
        }
        return $pdf . "trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }
}
