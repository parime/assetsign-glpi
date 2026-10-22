<?php

/**
 * Donnees du test de controle d'acces de bout en bout (tests/security/access-matrix.sh), lance
 * par la CI dans une instance GLPI jetable.
 *
 * Deux entites A et B ; un profil « technicien » disposant de tous les droits du plugin mais
 * limite a l'entite A. Dans chaque entite : un ordinateur, une feuille d'attribution (avec jeton
 * de signature), une maintenance, un mouvement, un dossier de depart, une campagne et une
 * attestation, un gabarit, un kit, un accessoire, deux elements de liste de controle, un ticket.
 *
 * Usage (en www-data, depuis la racine GLPI) :
 *   php plugins/assetsign/tests/security/setup.php           cree les donnees, affiche les ids en JSON
 *   php plugins/assetsign/tests/security/setup.php snapshot  etat des objets de l'entite B que le
 *                                                            technicien ne doit jamais modifier
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Token;

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
if (!(new Auth())->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Connexion glpi/glpi impossible\n");
    exit(1);
}

global $DB;

const SECURITY_TEST_PASSWORD = 'Secrev#2026';

$entityId = static function (string $name): int {
    $entity = new Entity();
    if ($entity->getFromDBByCrit(['name' => $name, 'entities_id' => 0])) {
        return (int) $entity->getID();
    }
    return (int) $entity->add(['name' => $name, 'entities_id' => 0]);
};
$userId = static function (string $login, int $entity): int {
    $user = new User();
    if ($user->getFromDBbyName($login)) {
        return (int) $user->getID();
    }
    return (int) $user->add([
        'name' => $login, 'password' => SECURITY_TEST_PASSWORD, 'password2' => SECURITY_TEST_PASSWORD,
        'entities_id' => $entity, '_profiles_id' => 0,
    ]);
};
$insert = static function (string $table, array $row) use ($DB): int {
    $DB->insert($table, $row + ['date_creation' => date('Y-m-d H:i:s')]);
    return (int) $DB->insertId();
};

$a = $entityId('Securite A');
$b = $entityId('Securite B');

if (($argv[1] ?? '') === 'xss') {
    // Charge piegee ecrite dans chaque champ texte libre des objets de l'entite A (et dans le nom
    // de l'ordinateur et de l'utilisateur concernes) : tests/security/xss-check.sh verifie
    // qu'aucune page ne la restitue telle quelle. Usage : setup.php xss '<json du mode normal>'.
    $ids = json_decode((string) ($argv[2] ?? ''), true);
    if (!is_array($ids)) {
        fwrite(STDERR, "Usage : setup.php xss '<json du mode normal>'\n");
        exit(1);
    }
    $canary = "<svg/onload=alert('xss-canary')>";
    $set = static function (string $table, array $values, array $where) use ($DB): void {
        $DB->update('glpi_plugin_assetsign_' . $table, $values, $where);
    };
    $sheet = ['id' => $ids['assetsign_A']];
    $set('assetsigns', [
        'observations' => $canary, 'beneficiary_comment' => $canary, 'delegation_reason' => $canary,
        'external_beneficiary_name' => $canary, 'external_beneficiary_contact' => $canary,
    ], $sheet);
    $DB->insert('glpi_plugin_assetsign_assetsignitems', [
        'plugin_assetsign_assetsigns_id' => $ids['assetsign_A'], 'itemtype' => 'Monitor', 'items_id' => 0,
        'name' => $canary, 'serial' => $canary, 'otherserial' => $canary, 'model' => $canary,
        'type_label' => $canary, 'manufacturer' => $canary, 'return_comment' => $canary,
    ]);
    $DB->insert('glpi_plugin_assetsign_damagemarkers', [
        'plugin_assetsign_assetsigns_id' => $ids['assetsign_A'], 'view_index' => 0,
        'x_percent' => 10, 'y_percent' => 10, 'description' => $canary,
    ]);
    $DB->insert('glpi_plugin_assetsign_events', [
        'itemtype' => 'Computer', 'items_id' => $ids['computer_A'], 'entities_id' => $ids['a'],
        'event_type' => 1, 'users_id' => $ids['partant_A'], 'date' => date('Y-m-d H:i:s'),
        'snapshot_name' => $canary, 'snapshot_email' => $canary, 'snapshot_entity_name' => $canary,
        'comment' => $canary,
    ]);
    $set('campaigns', ['name' => $canary], ['id' => $ids['campaign_A']]);
    $set('attestations', ['comment' => $canary, 'items' => json_encode([['name' => $canary]])], ['id' => $ids['attestation_A']]);
    $set('maintenances', ['comment' => $canary], ['id' => $ids['maintenance_A']]);
    $set('movements', ['comment' => $canary], ['id' => $ids['movement_A']]);
    foreach (['templates', 'kits', 'accessories', 'checklistitems', 'maintenancechecklistitems'] as $table) {
        $set($table, ['name' => $canary, 'comment' => $canary], ['id' => $ids[$table . '_A']]);
    }
    $set('configs', [
        'company_name' => $canary, 'sender_name' => $canary, 'preview_watermark_text' => $canary,
        'currency_symbol' => $canary, 'default_provider' => $canary,
    ], ['entities_id' => $ids['a']]);
    $DB->update('glpi_computers', ['name' => $canary, 'serial' => $canary], ['id' => $ids['computer_A']]);
    $DB->update('glpi_users', ['realname' => $canary, 'firstname' => $canary], ['id' => $ids['partant_A']]);
    // Le beneficiaire doit pouvoir se connecter (page de signature, « Mon materiel ») : profil
    // natif Self-Service dans son entite.
    $selfService = new Profile();
    if ($selfService->getFromDBByCrit(['name' => 'Self-Service'])) {
        $DB->delete('glpi_profiles_users', ['users_id' => $ids['partant_A']]);
        $DB->insert('glpi_profiles_users', [
            'users_id' => $ids['partant_A'], 'profiles_id' => $selfService->getID(), 'entities_id' => $ids['a'],
            'is_recursive' => 0, 'is_dynamic' => 0, 'is_default_profile' => 1,
        ]);
        $DB->update('glpi_users', ['profiles_id' => $selfService->getID()], ['id' => $ids['partant_A']]);
    }
    echo "ok\n";
    exit(0);
}

if (($argv[1] ?? '') === 'snapshot') {
    // Objets de l'entite B : aucun ne doit changer quand le technicien de A tente d'agir dessus.
    $computerB = (int) ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_computers', 'WHERE' => ['name' => 'PC securite B'], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current()['id'] ?? 0);
    $snapshot = [
        'computer_B.users_id' => (int) ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_computers', 'WHERE' => ['id' => $computerB]])->current()['users_id'] ?? -1),
    ];
    foreach (['assetsigns', 'maintenances', 'movements', 'residualvalues', 'environmentaldatas', 'events'] as $table) {
        $snapshot[$table . '(computer_B)'] = countElementsInTable('glpi_plugin_assetsign_' . $table, ['itemtype' => 'Computer', 'items_id' => $computerB]);
    }
    foreach ($DB->request(['FROM' => 'glpi_plugin_assetsign_assetsigns', 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computerB]]) as $row) {
        $snapshot['assetsign_B.status'] = (int) $row['status'];
        $snapshot['assetsign_B.markers'] = countElementsInTable('glpi_plugin_assetsign_damagemarkers', ['plugin_assetsign_assetsigns_id' => $row['id']]);
    }
    $snapshot['config_B'] = md5(json_encode(iterator_to_array($DB->request(['FROM' => 'glpi_plugin_assetsign_configs', 'WHERE' => ['entities_id' => $b]]))));
    ksort($snapshot);
    echo json_encode($snapshot), "\n";
    exit(0);
}

$profile = new Profile();
if (!$profile->getFromDBByCrit(['name' => 'Securite - technicien entite A'])) {
    $profile->add(['name' => 'Securite - technicien entite A', 'interface' => 'central']);
}
$profileId = (int) $profile->getID();
ProfileRight::updateProfileRights($profileId, [
    'plugin_assetsign_assetsign'   => ALLSTANDARDRIGHT,
    'plugin_assetsign_maintenance' => ALLSTANDARDRIGHT,
    'plugin_assetsign_template'    => ALLSTANDARDRIGHT,
    'plugin_assetsign_config'      => ALLSTANDARDRIGHT,
    'dropdown'                     => ALLSTANDARDRIGHT,
    'computer'                     => ALLSTANDARDRIGHT,
    'ticket'                       => ALLSTANDARDRIGHT,
    'user'                         => READ,
    'config'                       => 0,
    'entity'                       => 0,
]);

$technician = $userId('securite-tech', $a);
$DB->delete('glpi_profiles_users', ['users_id' => $technician]);
$DB->insert('glpi_profiles_users', [
    'users_id' => $technician, 'profiles_id' => $profileId, 'entities_id' => $a,
    'is_recursive' => 0, 'is_dynamic' => 0, 'is_default_profile' => 1,
]);
$DB->update('glpi_users', ['profiles_id' => $profileId, 'entities_id' => $a], ['id' => $technician]);

$ids = ['a' => $a, 'b' => $b, 'technician' => $technician];
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    // Etat de configuration connu a chaque execution (le test d'ecriture du super-admin sur
    // front/config.php le modifie) : l'etiquette QR doit etre disponible dans les deux entites.
    \GlpiPlugin\Assetsign\Config::upsertForEntity($entity, ['enable_qr_label' => 1, 'enable_passport' => 1]);
    $leaving = $userId('securite-partant-' . strtolower($suffix), $entity);
    $ids['partant_' . $suffix] = $leaving;

    $computer = new Computer();
    $ids['computer_' . $suffix] = (int) $computer->add(['name' => 'PC securite ' . $suffix, 'entities_id' => $entity]);

    $sheet = new Assetsign();
    $ids['assetsign_' . $suffix] = (int) $sheet->add([
        'entities_id' => $entity, 'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix],
        'users_id' => $leaving, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_SENT,
    ]);
    $sheet->getFromDB($ids['assetsign_' . $suffix]);
    $ids['token_' . $suffix] = Token::createForAssetsign($sheet, 30);

    $ids['maintenance_' . $suffix] = $insert('glpi_plugin_assetsign_maintenances', [
        'entities_id' => $entity, 'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix],
    ]);
    $ids['movement_' . $suffix] = $insert('glpi_plugin_assetsign_movements', [
        'entities_id' => $entity, 'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix],
    ]);
    $ids['departure_' . $suffix] = $insert('glpi_plugin_assetsign_departures', [
        'entities_id' => $entity, 'users_id' => $leaving, 'status' => 1, 'trigger_type' => 1,
    ]);
    $ids['campaign_' . $suffix] = $insert('glpi_plugin_assetsign_campaigns', [
        'entities_id' => $entity, 'name' => 'Campagne securite ' . $suffix,
    ]);
    $ids['attestation_' . $suffix] = $insert('glpi_plugin_assetsign_attestations', [
        'entities_id' => $entity, 'users_id' => $leaving, 'plugin_assetsign_campaigns_id' => $ids['campaign_' . $suffix],
    ]);
    foreach (['templates', 'kits', 'accessories', 'checklistitems', 'maintenancechecklistitems'] as $table) {
        $ids[$table . '_' . $suffix] = $insert('glpi_plugin_assetsign_' . $table, [
            'entities_id' => $entity, 'is_recursive' => 0, 'name' => ucfirst($table) . ' securite ' . $suffix,
        ]);
    }
    $ticket = new Ticket();
    $ids['ticket_' . $suffix] = (int) $ticket->add([
        'name' => 'Ticket securite ' . $suffix, 'content' => 'Test de controle d acces', 'entities_id' => $entity,
    ]);
}

echo json_encode($ids), "\n";
