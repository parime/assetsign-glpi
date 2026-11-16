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

namespace GlpiPlugin\Assetsign\Workflow;

/**
 * Registre des types de fiche geres par le plugin, alimente une seule fois
 * (hook.php, plugin_init_assetsign()) puis consulte partout ou le comportement
 * varie selon le type (Assetsign.php, Template.php, HandoverPdfBuilder,
 * NotificationTargetAssetsign).
 */
final class WorkflowTypeRegistry
{
    /** @var array<int, WorkflowTypeInterface> */
   private static array $types = [];

   public static function register(WorkflowTypeInterface $type): void {
       self::$types[$type->getId()] = $type;
   }

    /**
     * @throws \RuntimeException si aucun type n'est enregistre sous cet id -
     *     un type inconnu est un vrai bug (donnee corrompue, type retire sans
     *     migration) qui doit remonter immediatement plutot que de se rabattre
     *     silencieusement sur un type par defaut.
     */
   public static function get(int $id): WorkflowTypeInterface {
       return self::$types[$id]
           ?? throw new \RuntimeException("Plugin assetsign : type de workflow inconnu (id=$id).");
   }

    /** @return array<int, WorkflowTypeInterface> */
   public static function all(): array {
       return self::$types;
   }
}
