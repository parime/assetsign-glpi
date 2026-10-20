<?php

/**
 * Donnees du test de controle d'acces de bout en bout (tests/security/access-matrix.sh), lance
 * par la CI dans une instance GLPI jetable : deux entites A et B, un profil « technicien »
 * disposant de tous les droits du plugin mais limite a l'entite A, et un dossier de depart, une
 * campagne et une attestation dans chacune des deux entites. Affiche les identifiants en JSON.
 *
 * Usage (en www-data, depuis la racine GLPI) : php plugins/assetsign/tests/security/setup.php
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

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

$a = $entityId('Securite A');
$b = $entityId('Securite B');

$profile = new Profile();
if (!$profile->getFromDBByCrit(['name' => 'Securite - technicien entite A'])) {
    $profile->add(['name' => 'Securite - technicien entite A', 'interface' => 'central']);
}
$profileId = (int) $profile->getID();
ProfileRight::updateProfileRights($profileId, [
    'plugin_assetsign_assetsign' => ALLSTANDARDRIGHT,
    'plugin_assetsign_config'    => 0,
    'user'                       => READ,
    'config'                     => 0,
    'entity'                     => 0,
]);

$technician = $userId('securite-tech', $a);
$DB->delete('glpi_profiles_users', ['users_id' => $technician]);
$DB->insert('glpi_profiles_users', [
    'users_id' => $technician, 'profiles_id' => $profileId, 'entities_id' => $a,
    'is_recursive' => 0, 'is_dynamic' => 0, 'is_default_profile' => 1,
]);
$DB->update('glpi_users', ['profiles_id' => $profileId, 'entities_id' => $a], ['id' => $technician]);

$ids = ['a' => $a, 'b' => $b];
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $leaving = $userId('securite-partant-' . strtolower($suffix), $entity);
    $ids['partant_' . $suffix] = $leaving;
    $DB->insert('glpi_plugin_assetsign_departures', [
        'entities_id' => $entity, 'users_id' => $leaving, 'status' => 1, 'trigger_type' => 1, 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    $ids['departure_' . $suffix] = (int) $DB->insertId();
    $DB->insert('glpi_plugin_assetsign_campaigns', [
        'entities_id' => $entity, 'name' => 'Campagne securite ' . $suffix, 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    $ids['campaign_' . $suffix] = (int) $DB->insertId();
    $DB->insert('glpi_plugin_assetsign_attestations', [
        'entities_id' => $entity, 'users_id' => $leaving, 'plugin_assetsign_campaigns_id' => $ids['campaign_' . $suffix], 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    $ids['attestation_' . $suffix] = (int) $DB->insertId();
}

echo json_encode($ids), "\n";
