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

       // getActualSigner() (pas le beneficiaire d'origine systematiquement) :
       // reflete qui a REELLEMENT signe (cf. son docblock, issue #115) — sans
       // quoi la ligne "Signataire" du PDF final resterait fausse des qu'un
       // delegue signe a la place du beneficiaire d'origine.
       $signer = $assetsign->getActualSigner();

       $html = $this->builder->renderHtml($assetsign, [
           'signature_image' => $signaturePngDataUrl,
           'signed_at'       => $signedAt,
           'signer_name'     => trim(\formatUserName(0, $signer['name'] ?? '', $signer['realname'] ?? '', $signer['firstname'] ?? '')),
           'signer_email'    => $signer['email'] ?? '',
       ]);

       $protect = (bool) Config::getForEntity((int) $assetsign->fields['entities_id'])->fields['protect_pdf'];
       $binary = $this->builder->renderPdf($html, $protect);
       $hash = hash('sha256', $binary);

       $path = GLPI_TMP_DIR . '/' . uniqid('assetsign_signed_', true) . '.pdf';
       file_put_contents($path, $binary);

       return ['path' => $path, 'hash' => $hash, 'signed_at' => $signedAt];
   }

    /**
     * Rend le PDF final a DEUX signatures (issue #143), lors de la
     * contre-signature. Distincte de apply() ci-dessus : PAS de resolution
     * automatique via getActualSigner() (qui compare l'identite de la
     * session courante a delegated_users_id — inadaptee ici, la session
     * courante est TOUJOURS le responsable a cette etape, jamais le
     * beneficiaire). Les deux identites sont donc fournies explicitement par
     * l'appelant (SignController::submit()) : celle du beneficiaire,
     * reconstituee depuis ce qui a ete enregistre a l'etape precedente
     * (cosigner_pending_signature + la preuve de signature deja stockee),
     * et celle du responsable, tout juste soumise.
     *
     * @param array{signature_image:string,signed_at:string,signer_name:string,signer_email:string} $beneficiarySigner
     * @param array{signature_image:string,signed_at:string,signer_name:string,signer_email:string} $cosignerSigner
     * @return array{path:string,hash:string,signed_at:string}
     */
   public function applyCosignature(\GlpiPlugin\Assetsign\Assetsign $assetsign, array $beneficiarySigner, array $cosignerSigner): array {
       $html = $this->builder->renderHtml($assetsign, $beneficiarySigner + ['cosigner' => $cosignerSigner]);

       $protect = (bool) Config::getForEntity((int) $assetsign->fields['entities_id'])->fields['protect_pdf'];
       $binary = $this->builder->renderPdf($html, $protect);
       $hash = hash('sha256', $binary);

       $path = GLPI_TMP_DIR . '/' . uniqid('assetsign_cosigned_', true) . '.pdf';
       file_put_contents($path, $binary);

       return ['path' => $path, 'hash' => $hash, 'signed_at' => $cosignerSigner['signed_at']];
   }
}
