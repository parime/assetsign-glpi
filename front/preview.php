<?php

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Template;

// Utilise aussi bien depuis la page de configuration (droit Config) que depuis
// la page de gabarit (droit Template) : l'un ou l'autre suffit, ce n'est que
// de la prévisualisation en lecture seule, rien n'est jamais enregistré ici.
if (!Session::haveRight(Config::$rightname, READ) && !Session::haveRight(Template::$rightname, READ)) {
    Html::displayNotFoundError();
}

header('Content-Type: text/html; charset=UTF-8');

// Le jeton CSRF envoye par ce POST vient d'etre consomme par le pare-feu GLPI
// (a usage unique, cf. TROUBLESHOOTING.md) : sans en fournir un nouveau, le PROCHAIN appel
// de live-preview.js (a la frappe suivante) serait rejete en 403 — constate en
// conditions reelles en enchainant plusieurs appels avec le meme jeton. Le
// nouveau jeton est renvoye en en-tete pour que le JS mette a jour son
// formulaire avant le prochain envoi.
header('X-Remise-Csrf-Token: ' . \Session::getNewCSRFToken());

$entities_id = (int) ($_POST['entities_id'] ?? 0);
$type = (int) ($_POST['type'] ?? Remise::TYPE_HANDOVER);

$overrides = [];
foreach (['include_content', 'include_charter', 'enable_observations', 'enable_damage_annotation', 'show_qr_code'] as $boolField) {
   if (isset($_POST[$boolField])) {
       $overrides[$boolField] = $_POST[$boolField] === '1';
   }
}
foreach (['content', 'charter_content', 'charter_url', 'currency_symbol'] as $textField) {
   if (isset($_POST[$textField])) {
       $overrides[$textField] = (string) $_POST[$textField];
   }
}

echo (new HandoverPdfBuilder())->renderPreview($entities_id, $type, $overrides);
