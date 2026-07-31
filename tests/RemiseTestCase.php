<?php

namespace GlpiPlugin\Remise\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Classe de base pour les tests qui ecrivent en base : chaque test s'execute
 * dans une transaction annulee en tearDown, pour ne rien laisser derriere lui.
 * Les tables du plugin (et glpi_entities) sont en InnoDB, ce qui rend cela
 * possible — mais ce n'est pas un filet de securite absolu (une requete qui
 * ferait un COMMIT implicite, ex: DDL, echapperait au rollback). Ne PAS lancer
 * ces tests contre une base de production.
 */
abstract class RemiseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $DB;
        $DB->beginTransaction();
    }

    protected function tearDown(): void
    {
        global $DB;
        $DB->rollBack();

        parent::tearDown();
    }

    /**
     * Cree une entite de test directement en base (plus fiable qu'Entity::add(),
     * qui exige des champs supplementaires pour certains calculs internes non
     * pertinents ici) et renvoie son ID.
     */
    protected function createTestEntity(int $parentId, string $name): int
    {
        global $DB;

        static $nextId = null;
        if ($nextId === null) {
            // Plage tres improbable de collisionner avec des entites reelles.
            $nextId = random_int(500000, 599999);
        }
        $id = $nextId++;

        $parentLevel = 1;
        if ($parentId !== 0) {
            foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['id' => $parentId]]) as $row) {
                $parentLevel = (int) $row['level'];
            }
        }

        $DB->insert('glpi_entities', [
            'id'           => $id,
            'name'         => $name,
            'completename' => $name,
            'entities_id'  => $parentId,
            'level'        => $parentLevel + 1,
        ]);

        return $id;
    }

    /**
     * Cree un Etat GLPI (glpi_states) directement en base, meme motif et
     * meme raison que createTestEntity() : State est aussi un CommonTreeDropdown
     * (level/ancestors_cache), mais rien dans ce plugin ne parcourt la
     * hierarchie des Etats — seul un id valide a referencer dans states_id
     * est necessaire ici.
     */
    protected function createTestState(string $name): int
    {
        global $DB;

        static $nextId = null;
        if ($nextId === null) {
            $nextId = random_int(600000, 699999);
        }
        $id = $nextId++;

        $DB->insert('glpi_states', [
            'id'              => $id,
            'name'            => $name,
            'completename'    => $name,
            'entities_id'     => 0,
            'states_id'       => 0,
            'level'           => 1,
            'ancestors_cache' => '[]',
            'sons_cache'      => '[]',
        ]);

        return $id;
    }

    /** Cree un Computer minimal (nom + entite), pour les tests de declenchement. */
    protected function createTestComputer(int $entitiesId, string $name): \Computer
    {
        $computer = new \Computer();
        $id = (int) $computer->add([
            'name'        => $name,
            'entities_id' => $entitiesId,
        ]);
        $computer->getFromDB($id);
        return $computer;
    }

    /**
     * Cree une Remise minimale par insertion directe (pas via createManual()/
     * createRemise(), qui appellent launchWorkflow() et generent un vrai PDF) :
     * pour les tests dont la logique testee ne depend pas du PDF/du workflow
     * (Token, DamageMarker, Reminder...), evite le cout et les effets de bord
     * d'une generation PDF a chaque test.
     */
    protected function createBareRemise(int $entitiesId, int $type = 0, int $status = 1, int $usersId = 2): \GlpiPlugin\Remise\Remise
    {
        $remise = new \GlpiPlugin\Remise\Remise();
        $id = (int) $remise->add([
            'entities_id' => $entitiesId,
            'itemtype'    => 'Computer',
            'items_id'    => 1,
            'users_id'    => $usersId,
            'type'        => $type,
            'status'      => $status,
        ]);
        $remise->getFromDB($id);
        return $remise;
    }

    /**
     * Vide toutes les remises actuellement en attente de signature (SENT/VIEWED),
     * a l'interieur de la transaction du test en cours (donc sans effet reel,
     * annule au tearDown) : necessaire avant de tester runReminders()/
     * runExpiration()/runExpiryWarnings(), qui parcourent TOUTE la table sans
     * filtre d'entite — sans ce nettoyage, d'anciennes remises de tests
     * manuels deja presentes fausseraient le compte retourne.
     */
    protected function clearAwaitingSignatureRemises(): void
    {
        global $DB;
        $DB->update('glpi_plugin_remise_remises', ['status' => \GlpiPlugin\Remise\Remise::STATUS_CANCELLED], [
            'status' => \GlpiPlugin\Remise\Remise::STATUSES_AWAITING_SIGNATURE,
        ]);
    }
}
