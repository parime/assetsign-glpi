<?php

/**
 * "Mes documents a signer" (issue #150) : documents qui attendent la signature de l'utilisateur
 * connecte, quel que soit son profil ou ses droits sur le plugin - seuls SES documents sont
 * listes (cf. PendingSignatures). Point d'entree pour les utilisateurs sans adresse e-mail, qui
 * ne recoivent jamais le lien de signature : bandeau de la page d'accueil et menu de
 * l'interface simplifiee.
 *
 * POST "sign" : emet un lien de signature pour l'utilisateur connecte et redirige vers
 * front/sign.php, qui refait lui-meme tous les controles d'identite (SignController).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\PendingSignatures;

global $CFG_GLPI;

Session::checkLoginUser();

$usersId = (int) Session::getLoginUserID();
$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/mysignatures.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign'])) {
   try {
       $token = PendingSignatures::createSigningToken((int) ($_POST['id'] ?? 0), $usersId);
       Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetsign/front/sign.php?t=' . urlencode($token));
   } catch (\RuntimeException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
       Html::redirect($selfUrl);
   }
}

$title = __('Mes documents à signer', 'assetsign');
$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
if ($isHelpdesk) {
    Html::helpHeader($title);
} else {
    Html::header($title, $selfUrl);
}

$types = Assetsign::getTypes();
$rows = [];
foreach (PendingSignatures::forUser($usersId) as $pending) {
    $assetsign = $pending['assetsign'];
    $item = $assetsign->getTargetItem();
    $rows[] = [
        'id'          => (int) $assetsign->getID(),
        'type'        => $types[(int) $assetsign->fields['type']] ?? '',
        'item'        => (string) ($item['name'] ?? ''),
        'serial'      => (string) ($item['serial'] ?? ''),
        'date_sent'   => $assetsign->fields['date_sent'],
        'technician'  => getUserName((int) $assetsign->fields['users_id_tech']),
        'is_cosigner' => $pending['role'] === PendingSignatures::ROLE_COSIGNER,
        'is_delegate' => (int) $assetsign->fields['delegated_users_id'] === $usersId,
        'beneficiary' => getUserName((int) $assetsign->fields['users_id']),
    ];
}

// Issue #157 : un dossier de depart = une seule ligne, signee depuis la page du dossier.
foreach (PendingSignatures::openDeparturesForUser($usersId) as $departure) {
    $lines = $departure->getItemLines();
    $rows[] = [
        'id'            => 0,
        'type'          => __('Restitution de départ', 'assetsign'),
        'item'          => sprintf(_n('%d matériel', '%d matériels', count($lines), 'assetsign'), count($lines)),
        'serial'        => implode(', ', array_column($lines, 'name')),
        'date_sent'     => $departure->fields['date_creation'],
        'technician'    => getUserName((int) $departure->fields['users_id_tech']),
        'is_cosigner'   => false,
        'is_delegate'   => false,
        'beneficiary'   => '',
        'departure_url' => $departure->getSignUrl(),
    ];
}

// Issue #158 : attestations annuelles de detention en attente.
foreach (PendingSignatures::pendingAttestationsForUser($usersId) as $attestation) {
    $items = $attestation->getItems();
    $campaign = $attestation->getCampaign();
    $rows[] = [
        'id'            => 0,
        'type'          => _n('Attestation de détention', 'Attestations de détention', 1, 'assetsign'),
        'item'          => sprintf(_n('%d matériel', '%d matériels', count($items), 'assetsign'), count($items)),
        'serial'        => implode(', ', array_column($items, 'name')),
        'date_sent'     => $attestation->fields['date_creation'],
        'technician'    => $campaign !== null ? getUserName((int) $campaign->fields['users_id']) : '',
        'is_cosigner'   => false,
        'is_delegate'   => false,
        'beneficiary'   => '',
        'departure_url' => $attestation->getUrl(),
    ];
}

TemplateRenderer::getInstance()->display('@assetsign/my_signatures.html.twig', [
    'title'      => $title,
    'rows'       => $rows,
    'action'     => $selfUrl,
    'csrf_token' => Session::getNewCSRFToken(),
]);

if ($isHelpdesk) {
    Html::helpFooter();
} else {
    Html::footer();
}
