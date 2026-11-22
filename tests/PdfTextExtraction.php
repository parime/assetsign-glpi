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

/**
 * Lecture minimale des PDF produits par Dompdf (moteur CPDF, police DejaVu) pour les assertions
 * des tests : texte de chaque page et dictionnaire d'informations. Pas un lecteur PDF général.
 */
trait PdfTextExtraction
{
    /**
     * Texte de chaque flux de contenu de page (chaînes UTF-16BE dans des flux compressés).
     *
     * Les flux sont découpés selon leur longueur déclarée (/Length), pas par une expression
     * régulière « stream ... endstream » : les données compressées sont binaires et changent avec le
     * contenu (date d'impression...). Quand leur dernier octet valait \r, ou qu'elles contenaient
     * « \nendstream », l'ancienne lecture coupait le flux, gzuncompress() échouait et la page
     * disparaissait du résultat (issue #393).
     *
     * @return list<string>
     */
    protected function extractPageTexts(string $pdf): array
    {
        $pages = [];
        foreach ($this->extractPdfStreams($pdf) as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false || !str_contains($content, ' Tf ')) {
                continue;
            }
            preg_match_all('/\[\((.*?)\)\]\s*TJ/s', $content, $strings);
            $text = '';
            foreach ($strings[1] as $raw) {
                $text .= $this->decodePdfString($raw) . ' ';
            }
            $pages[] = $text;
        }
        return $pages;
    }

    /**
     * Nombre réel de pages : objets « /Type /Page » situés hors des données des flux, recoupés avec
     * le /Count de l'arbre des pages. -1 si les deux ne concordent pas (PDF incohérent).
     */
    protected function pdfPageCount(string $pdf): int
    {
        $outside = '';
        $offset = 0;
        $this->extractPdfStreams($pdf, $ranges);
        foreach ($ranges as [$start, $length]) {
            $outside .= substr($pdf, $offset, $start - $offset);
            $offset = $start + $length;
        }
        $outside .= substr($pdf, $offset);
        $objects = (int) preg_match_all('/\/Type\s*\/Page(?![A-Za-z])/', $outside);
        preg_match_all('/\/Count\s+(\d+)/', $outside, $counts);
        $declared = $counts[1] === [] ? 0 : max(array_map('intval', $counts[1]));
        return $objects === $declared ? $objects : -1;
    }

    /**
     * Diagnostic pour un message d'échec : taille, pages, flux et leur décompression, début du PDF
     * (octets non imprimables remplacés par des points).
     */
    protected function describePdf(string $pdf): string
    {
        $streams = $this->extractPdfStreams($pdf);
        $decoded = 0;
        $withText = 0;
        foreach ($streams as $stream) {
            $content = @gzuncompress($stream);
            if ($content !== false) {
                $decoded++;
                $withText += str_contains($content, ' Tf ') ? 1 : 0;
            }
        }
        return sprintf(
            "\nPDF de %d octets ; pages selon l'arbre des pages : %d ; flux : %d (décompressés : %d, avec du texte : %d).\nDébut du PDF :\n%s",
            strlen($pdf),
            $this->pdfPageCount($pdf),
            count($streams),
            $decoded,
            $withText,
            (string) preg_replace('/[^\x20-\x7E\n]/', '.', substr($pdf, 0, 600))
        );
    }

    /**
     * Données brutes de chaque flux, découpées selon la longueur /Length de leur dictionnaire.
     *
     * @param list<array{int, int}>|null $ranges reçoit la position et la longueur de chaque flux
     * @return list<string>
     */
    private function extractPdfStreams(string $pdf, ?array &$ranges = null): array
    {
        $streams = [];
        $ranges = [];
        $offset = 0;
        $pattern = '/\d+\s+\d+\s+obj\s*(<<(?:(?!endobj).)*?>>)\s*stream\r?\n/s';
        while (preg_match($pattern, $pdf, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            if (preg_match('/\/Length\s+(\d+)\b(?!\s+\d+\s+R)/', $match[1][0], $length) !== 1) {
                $offset = $start;
                continue;
            }
            $streams[] = substr($pdf, $start, (int) $length[1]);
            $ranges[] = [$start, (int) $length[1]];
            $offset = $start + (int) $length[1];
        }
        return $streams;
    }

    /**
     * Valeur d'une entrée du dictionnaire d'informations (/Title, /Author...), ou null.
     */
    protected function extractPdfInfo(string $pdf, string $key): ?string
    {
        if (!preg_match('/\/' . preg_quote($key, '/') . ' \((.*?)(?<!\\\\)\)\s*(\r?\n|\/|>>)/s', $pdf, $match)) {
            return null;
        }
        return $this->decodePdfString($match[1]);
    }

    private function decodePdfString(string $raw): string
    {
        $bytes = (string) preg_replace_callback(
            '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                '(', ')', '\\' => $m[1],
                default => chr(octdec($m[1])),
            },
            $raw
        );
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (strlen($bytes) >= 2 && $bytes[0] === "\x00") {
            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }
        return $bytes;
    }
}
