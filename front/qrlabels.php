<?php

/**
 * Issue #278 : planche d'étiquettes QR de plusieurs matériels, ouverte par l'action de masse
 * « Imprimer les étiquettes QR ». Même principe que front/qrlabel.php (page sans le chrome de GLPI,
 * accès authentifié) ; le lot vient de la session de l'utilisateur, chaque matériel est revérifié
 * (QrLabelBatch::resolve()).
 */

use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\QrLabelBatch;

Session::checkRight(PassportEvent::$rightname, READ);

$batch = QrLabelBatch::resolve((string) ($_GET['batch'] ?? ''));
if ($batch === null) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

header('Content-Type: text/html; charset=UTF-8');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetsign/qr_labels.html.twig', $batch);
