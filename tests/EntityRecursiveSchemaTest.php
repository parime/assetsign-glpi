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

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\CustodyCase;
use GlpiPlugin\Assetsign\Profile;
use Migration;
use Ticket;

/**
 * Issue #410 : la 2.31.0 filtrait les chaînes de possession avec les entités parentes
 * (getEntitiesRestrictCriteria(..., true)) sur une table sans `is_recursive`. Invisible avec la
 * seule entité racine (aucun parent, donc aucune condition sur `is_recursive`) : erreur SQL à
 * l'ouverture d'un ticket ou d'un matériel dès que l'entité active est une sous-entité.
 */
class EntityRecursiveSchemaTest extends AssetsignTestCase
{
    /**
     * Tout appel du plugin qui demande les entités parentes doit viser une table qui a la colonne.
     */
    public function testRecursiveEntityCriteriaTargetTablesWithIsRecursive(): void
    {
        global $DB;

        $root = dirname(__DIR__) . '/src';
        $checked = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('/getEntitiesRestrictCriteria\(\s*([^,]+?)\s*,[^,]*,[^,]*,\s*true\s*\)/', $source, $matches)) {
                continue;
            }
            foreach ($matches[1] as $tableExpression) {
                $table = $this->resolveTable($tableExpression, $source);
                if ($table === null || !$DB->tableExists($table)) {
                    // Table d'un autre plugin absent de l'instance de test, ou expression non littérale.
                    continue;
                }
                $this->assertTrue(
                    $DB->fieldExists($table, 'is_recursive'),
                    sprintf('%s : %s filtre avec les entités parentes, mais la table %s n\'a pas de colonne is_recursive.', $file->getFilename(), $tableExpression, $table)
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(5, $checked, 'Le balayage des sources doit trouver les appels concernés.');
    }

    /** Résout `self::getTable()`, `Classe::getTable()` ou `'glpi_...'` en nom de table. */
    private function resolveTable(string $expression, string $source): ?string
    {
        if (preg_match('/^[\'"](glpi_\w+)[\'"]$/', $expression, $m)) {
            return $m[1];
        }
        if (!preg_match('/^\\\\?([\w\\\\]+)::getTable\(\)$/', $expression, $m)) {
            return null;
        }
        $name = $m[1];
        preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $ns);
        if ($name === 'self' || $name === 'static') {
            preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $source, $cls);
            $class = ($ns[1] ?? '') . '\\' . ($cls[1] ?? '');
        } elseif (preg_match('/^use\s+([\w\\\\]+\\\\' . preg_quote($name, '/') . ');/m', $source, $use)) {
            $class = $use[1];
        } elseif (str_contains($name, '\\') || class_exists($name)) {
            $class = $name;
        } else {
            $class = ($ns[1] ?? '') . '\\' . $name;
        }
        if (!class_exists($class) || !method_exists($class, 'getTable')) {
            return null;
        }
        return $class::getTable();
    }

    /** Ouvrir un ticket depuis une sous-entité : l'onglet compte les fiches sans erreur SQL. */
    public function testCustodyTabCountsFromSubEntity(): void
    {
        $saved = [
            $_SESSION['glpiactiveentities'] ?? null,
            $_SESSION['glpiactiveentities_string'] ?? null,
            $_SESSION['glpiparententities'] ?? null,
            $_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] ?? 0,
        ];
        $entity = $this->createTestEntity(0, 'PHPUnit Sous-entite 410');
        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = (string) $entity;
        // Entités parentes de l'entité active (lues par getEntitiesRestrictCriteria) : la racine.
        $_SESSION['glpiparententities'] = [0];
        $_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] = ALLSTANDARDRIGHT;
        try {
            $ticket = new Ticket();
            $ticket->fields = ['id' => 999999, 'entities_id' => $entity];
            $this->assertSame([], CustodyCase::listRows(['tickets_id' => 999999]));
            $this->assertNotSame('', (new CustodyCase())->getTabNameForItem($ticket));
        } finally {
            $_SESSION['glpiactiveentities'] = $saved[0];
            $_SESSION['glpiactiveentities_string'] = $saved[1];
            $_SESSION['glpiparententities'] = $saved[2];
            $_SESSION['glpiactiveprofile'][Profile::RIGHT_CUSTODY] = $saved[3];
        }
    }

    /** Base mise à jour en 2.31.0 (table sans la colonne) : la migration la rajoute, sans doublon. */
    public function testInstallRepairsTableCreatedBy2310(): void
    {
        global $DB;

        $table = CustodyCase::getTable();
        // Modifications de schéma interdites dans la transaction du test (validation implicite) :
        // rien n'a été écrit avant, la transaction est refermée puis rouverte pour tearDown().
        $DB->commit();
        try {
            $migration = new Migration('test410');
            $migration->dropKey($table, 'is_recursive');
            $migration->dropField($table, 'is_recursive');
            $migration->migrationOneTable($table);
            $this->assertFalse($DB->fieldExists($table, 'is_recursive', false));

            CustodyCase::install($migration);
            CustodyCase::install($migration);

            $this->assertTrue($DB->fieldExists($table, 'is_recursive', false));
            $this->assertTrue(isIndex($table, 'is_recursive'));
        } finally {
            // La colonne reste en place pour les autres tests, même si une assertion échoue.
            CustodyCase::install(new Migration('test410'));
            $DB->beginTransaction();
        }
    }
}
