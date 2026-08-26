<?php

namespace GlpiPlugin\Assetsign;

/**
 * Génération de QR codes en data URI PNG, à partir d'une URL absolue quelconque.
 * Extrait de Pdf\PdfRenderingHelpers (methode privee getQrCodeDataUri(), qui ne
 * servait alors que le PDF) au moment d'ajouter l'étiquette QR code imprimable du
 * Passeport matériel (cf. ROADMAP.md V3, issue #82) : front/qrlabel.php a besoin
 * exactement de la même génération, jamais une deuxième implémentation qui
 * divergerait silencieusement de celle du PDF - un seul point d'appel a
 * BaconQrCode pour tout le plugin.
 *
 * Renderer GD (pas SVG : plus fiable avec Dompdf, cf. usage PDF) et Writer,
 * fournis par le CŒUR GLPI lui-meme (deja utilises pour les QR codes de double
 * authentification, cf. vendor/bacon/bacon-qr-code) — volontairement PAS
 * ajoute au composer.json du plugin : cette dependance est deja chargee par
 * l'autoloader de GLPI a l'execution d'un plugin, l'ajouter en double romprait
 * juste la consistance des versions. class_exists() + try/catch : si une
 * future version de GLPI retire ou renomme cette bibliotheque interne, l'appelant
 * continue de fonctionner sans QR code plutot que de planter entierement.
 */
final class QrCode
{
   public static function toDataUri(string $url): ?string {
      if (!class_exists(\BaconQrCode\Renderer\GDLibRenderer::class)) {
          return null;
      }

      try {
          $renderer = new \BaconQrCode\Renderer\GDLibRenderer(160, 4, 'png');
          $writer = new \BaconQrCode\Writer($renderer);
          $png = $writer->writeString($url);
      } catch (\Throwable) {
          return null;
      }

       return 'data:image/png;base64,' . base64_encode($png);
   }
}
