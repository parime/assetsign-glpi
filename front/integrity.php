<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\IntegrityCheck;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI;

// Issue #371 : controle d'integrite des preuves signees.
Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);

$selfUrl  = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/integrity.php';
$entities = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
$canRun   = (bool) Session::haveRight(Profile::RIGHT_ASSETSIGN, UPDATE);

if (isset($_POST['run_check'])) {
   Session::checkRight(Profile::RIGHT_ASSETSIGN, UPDATE);
   $result = IntegrityCheck::run($entities, (int) Session::getLoginUserID());
   Session::addMessageAfterRedirect(
       htmlescape(sprintf(__('%d anomalie(s) sur %d fiche(s) signée(s) contrôlée(s)', 'assetsign'), $result['anomalies'], $result['checked'])),
       false,
       $result['anomalies'] > 0 ? WARNING : INFO
   );
   Html::redirect($selfUrl);
}

$runs = IntegrityCheck::getRuns($entities);
$last = $runs[0] ?? null;
$rows = $last !== null ? IntegrityCheck::describe($last['anomalies']) : [];

if (($_GET['export'] ?? '') === 'csv') {
   header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="controle-integrite.csv"');
   echo IntegrityCheck::toCsv($rows);
   return;
}

$history = [];
foreach ($runs as $run) {
   $history[] = [
       'date_run'  => $run['date_run'],
       'by'        => $run['users_id'] > 0 ? getUserName($run['users_id']) : __('Tâche planifiée', 'assetsign'),
       'checked'   => $run['checked'],
       'anomalies' => count($run['anomalies']),
   ];
}

Html::header(IntegrityCheck::getTypeName(), $_SERVER['PHP_SELF'], 'tools', \GlpiPlugin\Assetsign\Menu::class, IntegrityCheck::class);
\GlpiPlugin\Assetsign\MenuSection::show(IntegrityCheck::class);

TemplateRenderer::getInstance()->display('@assetsign/integrity.html.twig', [
   'last'     => $last !== null ? $history[0] : null,
   'rows'     => $rows,
   'history'  => $history,
   'can_run'  => $canRun,
   'self_url' => $selfUrl,
   'cron_url' => $CFG_GLPI['root_doc'] . '/front/crontask.php',
]);

Html::footer();
