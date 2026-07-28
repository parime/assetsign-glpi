<?php

namespace GlpiPlugin\Remise\Pdf;

/**
 * Produit le PDF final signe : plutot que de superposer une image sur un PDF
 * deja fige (calque via FPDI, sensible au positionnement en points/pixels),
 * on re-rend le meme gabarit Twig en y injectant l'image de signature et les
 * metadonnees d'horodatage — le resultat est un document final, aplati,
 * strictement equivalent visuellement au PDF non signe plus la signature.
 */
final class SignatureStamper
{
    public function __construct(private readonly HandoverPdfBuilder $builder = new HandoverPdfBuilder())
    {
    }

    /**
     * @return array{path:string,hash:string} chemin du PDF final (dans GLPI_TMP_DIR) et son empreinte SHA-256
     */
    public function apply(\GlpiPlugin\Remise\Remise $remise, string $signaturePngDataUrl, array $meta = []): array
    {
        $signedAt = date('Y-m-d H:i:s');

        $html = $this->builder->renderHtml($remise, [
            'signature_image' => $signaturePngDataUrl,
            'signed_at'       => $signedAt,
            'signer_ip'       => $meta['ip'] ?? '',
        ]);

        $binary = $this->builder->renderPdf($html);
        $hash = hash('sha256', $binary);

        $path = GLPI_TMP_DIR . '/' . uniqid('remise_signed_', true) . '.pdf';
        file_put_contents($path, $binary);

        return ['path' => $path, 'hash' => $hash, 'signed_at' => $signedAt];
    }
}
