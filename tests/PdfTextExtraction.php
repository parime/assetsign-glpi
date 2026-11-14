<?php

namespace GlpiPlugin\Assetsign\Tests;

/**
 * Lecture minimale des PDF produits par Dompdf (moteur CPDF, police DejaVu) pour les assertions
 * des tests : texte de chaque page et dictionnaire d'informations. Pas un lecteur PDF général.
 */
trait PdfTextExtraction
{
    /**
     * Texte de chaque page (chaînes UTF-16BE dans des flux compressés).
     *
     * @return list<string>
     */
    protected function extractPageTexts(string $pdf): array
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
                $text .= $this->decodePdfString($raw) . ' ';
            }
            $pages[] = $text;
        }
        return $pages;
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
