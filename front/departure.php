<?php

/**
 * Issue #157 : tableau de suivi des départs — dossiers en cours (par défaut) ou tous, avec
 * l'avancement des signatures et les relances.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Departure;
use GlpiPlugin\Assetsign\DepartureLogic;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI, $DB;

Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);

Html::header(Departure::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Departure::class);

$showAll = !empty($_GET['all']);
$where = getEntitiesRestrictCriteria(Departure::getTable(), '', '', true);
if (!$showAll) {
   $where['status'] = DepartureLogic::STATUS_OPEN;
}

$rows = [];
foreach ($DB->request(['FROM' => Departure::getTable(), 'WHERE' => $where, 'ORDER' => 'date_creation DESC']) as $row) {
   $departure = new Departure();
   $departure->fields = $row;
   $lines = $departure->getItemLines();
   $rows[] = [
      'id'             => (int) $row['id'],
      'user'           => getUserName((int) $row['users_id']),
      'trigger'        => Departure::getTriggerLabels()[(int) $row['trigger_type']] ?? '',
      'status'         => (int) $row['status'],
      'status_label'   => Departure::getStatusLabels()[(int) $row['status']] ?? '',
      'date_departure' => $row['date_departure'],
      'date_creation'  => $row['date_creation'],
      'total'          => count($lines),
      'signed'         => count(array_filter($lines, static fn (array $l): bool => $l['status'] === \GlpiPlugin\Assetsign\Assetsign::STATUS_SIGNED)),
      'reminders'      => (int) $row['reminder_count'],
   ];
}

TemplateRenderer::getInstance()->display('@assetsign/departure_list.html.twig', [
   'rows'     => $rows,
   'show_all' => $showAll,
   'self_url' => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/departure.php',
   'form_url' => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/departure.form.php?id=',
]);

Html::footer();
