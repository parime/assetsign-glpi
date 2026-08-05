<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\PassportEvent;

/**
 * Couvre la fiche d'identite en tete du Passeport materiel (cf. ROADMAP.md,
 * tableau V1) : pure agregation de donnees deja natives GLPI, aucune
 * nouvelle table, aucune valeur inventee quand une information manque.
 */
class PassportEventIdentityCardTest extends RemiseTestCase
{
    private function createTestComputerModel(string $name): int
    {
        global $DB;
        static $nextId = null;
        if ($nextId === null) {
            $nextId = random_int(700000, 799999);
        }
        $id = $nextId++;
        $DB->insert('glpi_computermodels', ['id' => $id, 'name' => $name]);
        return $id;
    }

    private function createTestManufacturer(string $name): int
    {
        global $DB;
        static $nextId = null;
        if ($nextId === null) {
            $nextId = random_int(800000, 899999);
        }
        $id = $nextId++;
        $DB->insert('glpi_manufacturers', ['id' => $id, 'name' => $name]);
        return $id;
    }

    public function testShowForItemDisplaysFullIdentityCard(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Identity Full');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        $modelId = $this->createTestComputerModel('PHPUnit Modèle X1');
        $manufacturerId = $this->createTestManufacturer('PHPUnit Fabricant');
        $stateId = $this->createTestState('PHPUnit État En service');
        $userId = $this->createTestUser('Jean', 'Dupont');

        $computer = new \Computer();
        $id = (int) $computer->add([
            'name'             => 'PHPUnit PC Identity Full',
            'entities_id'      => $entityId,
            'serial'           => 'SN-IDENTITY-FULL',
            'computermodels_id' => $modelId,
            'manufacturers_id' => $manufacturerId,
            'states_id'        => $stateId,
            'users_id'         => $userId,
        ]);
        $computer->getFromDB($id);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('PHPUnit Modèle X1', $html);
        $this->assertStringContainsString('PHPUnit Fabricant', $html);
        $this->assertStringContainsString('SN-IDENTITY-FULL', $html);
        $this->assertStringContainsString('PHPUnit État En service', $html);
        // Ordre nom/prenom depend de names_format (reglage GLPI, pas de ce plugin) :
        // on verifie la presence des deux plutot qu'un ordre concatene precis.
        $this->assertStringContainsString('Dupont', $html);
        $this->assertStringContainsString('Jean', $html);
        $this->assertNoStrayNumericTextNode($html, 'La fiche d\'identité complète doit se rendre sans fuite Twig.');
    }

    public function testShowForItemHandlesMinimalIdentityGracefully(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Identity Minimal');
        Config::upsertForEntity($entityId, ['enable_passport' => 1, 'passport_visible_types' => [0,1,2,3,4]]);
        // Aucun modele, fabricant, Etat, utilisateur, serial : que le strict minimum (nom + entite).
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Identity Minimal');

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('PHPUnit Identity Minimal', $html); // nom de l'entite, toujours affiche
        $this->assertNoStrayNumericTextNode($html, 'Une fiche d\'identité quasi vide ne doit jamais planter ni afficher de valeur inventée.');
    }
}
