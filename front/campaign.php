<?php

/**
 * Issue #158 : liste des campagnes d'attestation de détention, avec leur taux de réponse.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Campaign;
use GlpiPlugin\Assetsign\Profile;

global $CFG_GLPI, $DB;

Session::checkRight(Profile::RIGHT_ASSETSIGN, READ);

Html::header(Campaign::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Campaign::class);

$rows = [];
foreach ($DB->request([
        'FROM'  => Campaign::getTable(),
        'WHERE' => getEntitiesRestrictCriteria(Campaign::getTable(), '', '', true),
        'ORDER' => 'date_creation DESC',
    ]) as $row
) {
    $campaign = new Campaign();
    $campaign->fields = $row;
    $rows[] = [
        'id'            => (int) $row['id'],
        'name'          => (string) $row['name'],
        'is_open'       => $campaign->isOpen(),
        'is_charter'    => $campaign->isCharter(),
        'date_deadline' => $row['date_deadline'],
        'date_creation' => $row['date_creation'],
        'stats'         => $campaign->getStats(),
    ];
}

// Issue #224 : la charte de l'entite active a change depuis sa derniere diffusion.
$activeEntity = Session::getActiveEntity();
$outdated = Campaign::isCurrentCharterUndistributed($activeEntity) ? count(Campaign::outdatedSignerIds($activeEntity)) : 0;

TemplateRenderer::getInstance()->display('@assetsign/campaign_list.html.twig', [
    'rows'       => $rows,
    'outdated_signers' => $outdated,
    'versions_url'     => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/charterversions.php',
    'active_entity'    => $activeEntity,
    'form_url'   => $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/campaign.form.php',
    'can_create' => Session::haveRight(Profile::RIGHT_ASSETSIGN, UPDATE),
]);

Html::footer();
