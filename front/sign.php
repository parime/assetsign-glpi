<?php

/**
 * Page de signature — connexion GLPI obligatoire (cf. Firewall::STRATEGY_AUTHENTICATED
 * dans setup.php : sans session authentifiée, GLPI redirige vers la page de connexion
 * avant même que ce script ne s'exécute).
 * Une fois connecté, seul le bénéficiaire réel du document peut le consulter/signer
 * (cf. SignController::assertCurrentUserIsBeneficiary()) — la présence du jeton dans
 * l'URL ne suffit pas à elle seule.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Remise\Api\SignController;
use GlpiPlugin\Remise\DamageMarker;
use GlpiPlugin\Remise\Remise;

global $CFG_GLPI;

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

// --- Reperes d'etat des lieux visuel + remarque libre, geres par le BENEFICIAIRE
// lui-meme depuis cette page (avant de signer) — meme actions que
// front/damagemarker.php (reserve au technicien via un droit GLPI), mais
// authentifiees par le jeton de signature + assertCurrentUserIsBeneficiary(),
// pas par un droit GLPI que le beneficiaire n'a pas forcement. -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['add']) || isset($_POST['update']) || isset($_POST['delete']) || isset($_POST['update_comment']))
) {
    header('Content-Type: application/json');

   try {
       $remise = $controller->loadAuthorizedRemise($token);
      if (!$remise->isStillEditable()) {
          throw new \RuntimeException(__('Cette fiche ne peut plus être modifiée.', 'remise'));
      }

      if (isset($_POST['update_comment'])) {
          $remise->updateBeneficiaryComment((string) ($_POST['comment'] ?? ''));
          $result = ['success' => true];
      } else {
          // Logique d'ajout/modification/suppression de repere partagee avec
          // front/damagemarker.php (cf. DamageMarker::handleMutationRequest()) :
          // seule l'autorisation ci-dessus (jeton de signature, pas un droit
          // GLPI) differe entre les deux.
          $result = DamageMarker::handleMutationRequest($remise, $_POST);
      }

       // Jeton CSRF a usage unique (cf. TROUBLESHOOTING.md) : sans rotation, un deuxieme clic
       // (ajouter un 2e repere, ou modifier apres avoir commente) echouerait en 403.
       header('X-Remise-Csrf-Token: ' . Session::getNewCSRFToken());
       echo json_encode($result);
   } catch (\Throwable $e) {
       http_response_code(400);
       echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
           throw new \RuntimeException(__('Signature manquante.', 'remise'));
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
    $remise = $data['remise'];
    $config = \GlpiPlugin\Remise\Config::getForEntity((int) $remise->fields['entities_id']);
    $damageEnabled = (bool) $config->fields['enable_damage_annotation'];

    TemplateRenderer::getInstance()->display('@remise/sign_page.html.twig', [
        'token'      => $token,
        'csrf_token' => Session::getNewCSRFToken(),
        'remise'     => $remise->fields,
        'user'       => $data['user'],
        'item'       => $data['item'],
        'expiry'     => $data['expiry'],
        'can_edit_damage_markers' => $remise->isStillEditable(),
        'damage_annotation_enabled' => $damageEnabled,
        'damage_views'   => $damageEnabled ? DamageMarker::getViewLabels() : [],
        'damage_images'  => $damageEnabled ? DamageMarker::getViewImageFilenames() : [],
        'damage_markers_by_view' => $damageEnabled ? Remise::groupMarkersByView(DamageMarker::getForRemise($remise->getID())) : [],
        'beneficiary_comment'    => $remise->fields['beneficiary_comment'] ?? '',
        'can_edit_comment'       => $remise->isStillEditable(),
        // Volontairement DIFFERENT de Remise::getPdfHeadings() (fixe en francais,
        // car c'est le contenu d'un PDF archive, cf. commentaire sur
        // getCanonicalTypeLabel()) : cette page-ci est une interface consultee en
        // direct par le beneficiaire, deja entierement traduite via __() ailleurs
        // dans sign_page.html.twig (cf. le titre du navigateur et le <h1>) — y
        // laisser fuiter un texte fixe y aurait ete incoherent avec le reste.
        'page_title' => match ((int) $data['remise']->fields['type']) {
            Remise::TYPE_RETURN => __('Signature de restitution de matériel', 'remise'),
            default              => __('Signature de remise de matériel', 'remise'),
        },
        // $CFG_GLPI['root_doc'] (pas un chemin fixe depuis la racine du domaine) :
        // une installation GLPI dans un sous-dossier (ex: /glpi) casserait sinon
        // silencieusement le chargement du PDF dans la page de signature.
        'pdf_url'    => $CFG_GLPI['root_doc'] . '/plugins/remise/front/sign.php?t=' . urlencode($token) . '&action=pdf',
        'error'      => null,
    ]);
} catch (\Throwable $e) {
    TemplateRenderer::getInstance()->display('@remise/sign_page.html.twig', [
        'token' => $token,
        'error' => $e->getMessage(),
    ]);
}
