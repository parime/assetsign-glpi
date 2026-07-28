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
}
