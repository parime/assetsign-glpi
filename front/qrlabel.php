<?php

/**
 * Étiquette QR code imprimable pour un matériel (cf. ROADMAP.md V3, issue #82) :
 * page dédiée, volontairement minimale (pas de chrome GLPI), pensée pour être
 * imprimée et collée physiquement sur le matériel. Accès authentifié classique
 * (aucune dérogation de pare-feu ici, contrairement à front/sign.php) : ce
 * plugin ne rend jamais aucune page accessible par un simple lien anonyme (cf.
 * README.md/SECURITY.md) — scanner le QR code une fois imprimé redirige vers
 * l'onglet Passeport matériel, qui exige la connexion GLPI habituelle si la
 * personne qui scanne n'est pas déjà authentifiée sur son téléphone.
 */

use GlpiPlugin\Assetsign\Api\QrLabelController;
use GlpiPlugin\Assetsign\PassportEvent;

Session::checkRight(PassportEvent::$rightname, READ);

$itemtype = (string) ($_GET['itemtype'] ?? '');
$items_id = (int) ($_GET['items_id'] ?? 0);

try {
    // Logique extraite dans Api\QrLabelController (meme motivation que
    // Api\PassportBackfillController) : rendre le controle itemtype/droit/
    // reglage d'entite testable en PHPUnit, sans passer par ce script qui
    // appelle Html::displayNotFoundError()/exit().
    $data = (new QrLabelController())->resolve($itemtype, $items_id);
} catch (\InvalidArgumentException $e) {
    // displayNotFoundError() leve en realite une exception (NotFoundHttpException,
    // interceptee plus haut par le noyau GLPI) : n'importe jamais ici. `exit`
    // explicite quand meme, uniquement pour que PHPStan sache que $data est
    // forcement defini a partir d'ici (il ne peut pas le deduire par simple
    // reflexion d'une methode du coeur GLPI hors des chemins qu'il analyse).
    Html::displayNotFoundError();
    exit;
}

// Page volontairement SANS Html::header()/footer() (meme choix que front/preview.php) :
// le chrome habituel de GLPI (menu, fil d'Ariane, barre laterale) n'a aucun interet sur
// une page destinee a etre imprimee telle quelle, et devrait sinon etre masque au complet
// en @media print au lieu d'etre simplement absent - plus fragile (depend de la structure
// DOM interne de GLPI, hors du controle de ce plugin) que de ne jamais le generer.
header('Content-Type: text/html; charset=UTF-8');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetsign/qr_label.html.twig', $data);
