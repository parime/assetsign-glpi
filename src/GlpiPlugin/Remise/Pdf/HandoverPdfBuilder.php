<?php

namespace GlpiPlugin\Remise\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Template;
use Glpi\Application\View\TemplateRenderer;
use Document;

/**
 * Construit le PDF non signe de la fiche de remise a partir d'un gabarit Twig,
 * et l'enregistre immediatement comme Document GLPI (visible mais provisoire :
 * il sera remplace par la version signee et horodatee apres passage par
 * SignatureStamper).
 */
final class HandoverPdfBuilder
{
    public function build(Remise $remise): Document
    {
        $html = $this->renderHtml($remise);
        $binary = $this->renderPdf($html);

        return $this->storeAsDocument($remise, $binary, 'fiche-remise-' . $remise->getID() . '.pdf');
    }

    /**
     * @param array $extra Variables additionnelles (ex: signature_image, signed_at,
     *                      signer_ip) fusionnees dans le contexte Twig — utilise par
     *                      SignatureStamper pour re-rendre le meme gabarit avec la
     *                      signature incrustee, plutot que de superposer des calques
     *                      sur un PDF deja rendu (plus simple, plus robuste).
     */
    public function renderHtml(Remise $remise, array $extra = []): string
    {
        $template = $remise->getTemplate();
        $headings = Remise::getPdfHeadings((int) $remise->fields['type']);

        return TemplateRenderer::getInstance()->render('@remise/pdf/handover.html.twig', array_merge([
            'remise'           => $remise->fields,
            'user'             => $remise->getBeneficiary(),
            'item'             => $remise->getTargetItem(),
            'itemtype'         => Remise::getCanonicalItemtypeLabel($remise->fields['itemtype']),
            'accessories'      => $remise->getAccessories(),
            'contract'         => $template?->fields['content'] ?? '',
            'charter'          => $template?->fields['charter_content'] ?? '',
            'charter_url'      => Config::getForEntity((int) $remise->fields['entities_id'])->fields['charter_url'] ?: null,
            'page_title'       => $headings['page_title'],
            'material_heading' => $headings['material_heading'],
            'document_title'   => $remise->getDocumentTitle(),
            'logo_data_uri'    => $this->getLogoDataUri((int) $remise->fields['entities_id']),
        ], $extra));
    }

    /**
     * Rendu du meme gabarit qu'un vrai PDF, mais avec des donnees fictives —
     * utilise par la page de configuration pour afficher un aperçu en direct
     * (logo, gabarit legal de l'entite) sans qu'aucune remise reelle n'existe.
     * Reutilise handover.html.twig tel quel : l'aperçu est donc visuellement
     * strictement identique a ce que produira un vrai PDF.
     */
    public function renderPreview(int $entities_id): string
    {
        $template = Template::getDefaultFor(Remise::TYPE_HANDOVER, $entities_id);
        $headings = Remise::getPdfHeadings(Remise::TYPE_HANDOVER);

        return TemplateRenderer::getInstance()->render('@remise/pdf/handover.html.twig', [
            'remise'           => ['id' => 0, 'date_creation' => date('Y-m-d H:i:s')],
            'user'             => ['firstname' => 'Alex', 'realname' => 'Dupont', 'name' => 'adupont', 'email' => 'alex.dupont@exemple.fr'],
            'item'             => ['name' => 'PC-EXEMPLE-001', 'serial' => 'SN-EXEMPLE-042', 'otherserial' => 'INV-1234', 'manufacturer_name' => 'Dell', 'model_name' => 'Latitude 5440'],
            'itemtype'         => Remise::getCanonicalItemtypeLabel('Computer'),
            'accessories'      => [],
            'contract'         => $template?->fields['content'] ?? '',
            'charter'          => $template?->fields['charter_content'] ?? '',
            'charter_url'      => Config::getForEntity($entities_id)->fields['charter_url'] ?: null,
            'page_title'       => $headings['page_title'],
            'material_heading' => $headings['material_heading'],
            'document_title'   => 'Aperçu',
            'logo_data_uri'    => $this->getLogoDataUri($entities_id),
        ]);
    }

    /**
     * Le logo est configurable par entite (Config::logo_documents_id, cf.
     * l'onglet Entite / Remise & signature > Configuration). Encode en data URI
     * plutot qu'un chemin de fichier : Dompdf tourne avec isRemoteEnabled=false
     * (aucun fetch reseau autorise), un data URI evite tout probleme de chemin
     * relatif/chroot pour une image qui peut venir de n'importe quel sous-dossier
     * de GLPI_DOC_DIR.
     */
    private function getLogoDataUri(int $entities_id): ?string
    {
        $documents_id = Config::getEffectiveLogoDocumentId($entities_id);
        if ($documents_id <= 0) {
            return null;
        }

        $document = new Document();
        if (!$document->getFromDB($documents_id)) {
            return null;
        }

        $fullpath = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        if (!is_readable($fullpath)) {
            return null;
        }

        $binary = file_get_contents($fullpath);
        if ($binary === false) {
            return null;
        }

        $mime = $document->fields['mime'] ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    public function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', GLPI_DOC_DIR);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function storeAsDocument(Remise $remise, string $pdfBinary, string $filename): Document
    {
        // Document::moveDocument() (appelee par add() via l'entree "_filename")
        // lit obligatoirement le fichier source dans GLPI_TMP_DIR : c'est la
        // convention native pour attacher un fichier genere par du code, par
        // opposition a "upload_file" reserve aux uploads HTTP directs.
        $tmpPath = GLPI_TMP_DIR . '/' . uniqid('remise_', true) . '.pdf';
        file_put_contents($tmpPath, $pdfBinary);

        $document = new Document();
        $documents_id = $document->add([
            'name'          => $remise->getDocumentTitle() . ' [non signée]',
            'entities_id'   => $remise->fields['entities_id'],
            '_filename'     => [basename($tmpPath)],
            '_tag_filename' => [basename($tmpPath)],
        ]);

        $document->getFromDB($documents_id);
        return $document;
    }
}
