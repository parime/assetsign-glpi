<?php

namespace GlpiPlugin\Assetsign\Pdf;

use GlpiPlugin\Assetsign\Config;

/**
 * Produit le PDF final signe : plutot que de superposer une image sur un PDF
 * deja fige (calque via FPDI, sensible au positionnement en points/pixels),
 * on re-rend le meme gabarit Twig en y injectant l'image de signature et les
 * metadonnees d'horodatage — le resultat est un document final, aplati,
 * strictement equivalent visuellement au PDF non signe plus la signature.
 */
final class SignatureStamper
{
   public function __construct(private readonly HandoverPdfBuilder $builder = new HandoverPdfBuilder()) {
   }

    /**
     * @return array{path:string,hash:string,signed_at:string} chemin du PDF final (dans GLPI_TMP_DIR), son empreinte SHA-256 et l'horodatage de signature
     */
   public function apply(\GlpiPlugin\Assetsign\Assetsign $assetsign, string $signaturePngDataUrl): array {
       $signedAt = date('Y-m-d H:i:s');

       $html = $this->builder->renderHtml($assetsign, [
           'signature_image' => $signaturePngDataUrl,
           'signed_at'       => $signedAt,
       ]);

       $protect = (bool) Config::getForEntity((int) $assetsign->fields['entities_id'])->fields['protect_pdf'];
       $binary = $this->builder->renderPdf($html, $protect);
       $hash = hash('sha256', $binary);

       $path = GLPI_TMP_DIR . '/' . uniqid('assetsign_signed_', true) . '.pdf';
       file_put_contents($path, $binary);

       return ['path' => $path, 'hash' => $hash, 'signed_at' => $signedAt];
   }
}
