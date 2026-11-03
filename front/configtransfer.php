<?php

/**
 * Issue #279 : export et import de la configuration d'une entité (réglages, gabarits par défaut,
 * logo). Export : droit de lecture de la configuration ; simulation et application de l'import :
 * droit de modification. L'entité doit toujours être accessible à l'utilisateur. Toutes les
 * actions passent en POST : le jeton CSRF est validé par le pare-feu de GLPI (CheckCsrfListener).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\ConfigTransfer;

Session::checkRight(Config::$rightname, READ);

/** @var array $CFG_GLPI */
global $CFG_GLPI;

$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/configtransfer.php';

if (isset($_POST['export'])) {
    $entities_id = (int) ($_POST['entities_id'] ?? -1);
   if (!Session::haveAccessToEntity($entities_id)) {
       throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
   }
    $content = (string) json_encode(
        ConfigTransfer::export($entities_id, !empty($_POST['with_templates']), !empty($_POST['with_logo'])),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . ConfigTransfer::getExportFilename($entities_id) . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

if (isset($_POST['preview'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    $entities_id = (int) ($_POST['entities_id'] ?? -1);
   if (!Session::haveAccessToEntity($entities_id)) {
       throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
   }
   try {
       $payload = ConfigTransfer::readUpload($_FILES['config_file'] ?? []);
   } catch (\InvalidArgumentException $e) {
       Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
       Html::redirect($selfUrl . '?entities_id=' . $entities_id);
   }
    $options = ['logo' => !empty($_POST['with_logo']), 'templates' => !empty($_POST['with_templates'])];
    $plan = ConfigTransfer::plan($payload, $entities_id, $options);

    Html::header(Config::getTypeName(), $_SERVER['PHP_SELF'], 'tools', Assetsign::class);
    TemplateRenderer::getInstance()->display('@assetsign/config_transfer_preview.html.twig', [
        'plan'         => $plan,
        'payload'      => $payload,
        'options'      => $options,
        'entity_id'    => $entities_id,
        'entity_name'  => Dropdown::getDropdownName('glpi_entities', $entities_id),
        'import_token' => ConfigTransfer::storePending($payload, $entities_id, $options),
        'action'       => $selfUrl,
        'csrf_token'   => Session::getNewCSRFToken(),
    ]);
    Html::footer();
    return;
}

if (isset($_POST['apply'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    $pending = ConfigTransfer::takePending((string) ($_POST['import_token'] ?? ''));
   if ($pending === null) {
       Session::addMessageAfterRedirect(__('Simulation expirée : relancez l\'import.', 'assetsign'), false, ERROR);
       Html::redirect($selfUrl);
   }
    $entities_id = (int) $pending['entities_id'];
   if (!Session::haveAccessToEntity($entities_id)) {
       throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
   }
    $result = ConfigTransfer::apply($pending['payload'], $entities_id, $pending['options']);
    Session::addMessageAfterRedirect(sprintf(
        __('Import appliqué : %1$d réglage(s) et %2$d gabarit(s) modifiés%3$s.', 'assetsign'),
        $result['settings'],
        $result['templates'],
        $result['logo'] ? __(', logo remplacé', 'assetsign') : ''
    ));
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetsign/front/config.php?entities_id=' . $entities_id);
}

$entities_id = (int) ($_GET['entities_id'] ?? Session::getActiveEntity());
if (!Session::haveAccessToEntity($entities_id)) {
    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
}

Html::header(Config::getTypeName(), $_SERVER['PHP_SELF'], 'tools', Assetsign::class);
TemplateRenderer::getInstance()->display('@assetsign/config_transfer.html.twig', [
    'entities'       => ConfigTransfer::getEntityChoices(),
    'entity_id'      => $entities_id,
    'can_import'     => Session::haveRight(Config::$rightname, UPDATE),
    'action'         => $selfUrl,
    'csrf_token'     => Session::getNewCSRFToken(),
    'max_file_mb'    => ConfigTransfer::MAX_FILE_BYTES / 1024 / 1024,
    'secret_fields'  => ConfigTransfer::SECRET_FIELDS,
]);
Html::footer();
