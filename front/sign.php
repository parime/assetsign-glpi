<?php

/**
 * Page de signature — connexion GLPI obligatoire (cf. Firewall::STRATEGY_AUTHENTICATED
 * dans setup.php : sans session authentifiée, GLPI redirige vers la page de connexion
 * avant même que ce script ne s'exécute).
 * Une fois connecté, seul le bénéficiaire réel du document peut le consulter/signer
 * (cf. SignController::assertCurrentUserIsBeneficiary()) — la présence du jeton dans
 * l'URL ne suffit pas à elle seule.
 */

use GlpiPlugin\Remise\Api\SignController;
use GlpiPlugin\Remise\Remise;
use Glpi\Application\View\TemplateRenderer;

$token = $_GET['t'] ?? $_POST['t'] ?? '';
$controller = new SignController();

// --- Flux binaire du PDF non signé, consommé par PDF.js côté client -------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'pdf') {
    try {
        $remise = $controller->loadAuthorizedRemise($token);
        $document = new Document();
        $document->getFromDB((int) $remise->fields['document_id_unsigned']);
        $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];

        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } catch (\Throwable $e) {
        http_response_code(404);
    }
    exit;
}

// --- Soumission de la signature ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $meta = [
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $signatureImage = $_POST['signature'] ?? '';
        if ($signatureImage === '') {
            throw new RuntimeException('Signature manquante.');
        }
        $controller->submit($token, $signatureImage, $meta);

        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Affichage de la page de signature --------------------------------------------
try {
    $data = $controller->show($token);
    TemplateRenderer::getInstance()->display('@remise/sign_page.html.twig', [
        'token'      => $token,
        'csrf_token' => Session::getNewCSRFToken(),
        'remise'     => $data['remise']->fields,
        'user'       => $data['user'],
        'item'       => $data['item'],
        'expiry'     => $data['expiry'],
        'page_title' => Remise::getPdfHeadings((int) $data['remise']->fields['type'])['page_title'],
        'pdf_url'    => '/plugins/remise/front/sign.php?t=' . urlencode($token) . '&action=pdf',
        'error'      => null,
    ]);
} catch (\Throwable $e) {
    TemplateRenderer::getInstance()->display('@remise/sign_page.html.twig', [
        'token' => $token,
        'error' => $e->getMessage(),
    ]);
}
