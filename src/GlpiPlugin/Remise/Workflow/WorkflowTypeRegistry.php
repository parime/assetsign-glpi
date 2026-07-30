<?php

namespace GlpiPlugin\Remise\Workflow;

/**
 * Registre des types de fiche geres par le plugin, alimente une seule fois
 * (hook.php, plugin_init_remise()) puis consulte partout ou le comportement
 * varie selon le type (Remise.php, Template.php, HandoverPdfBuilder,
 * NotificationTargetRemise).
 */
final class WorkflowTypeRegistry
{
    /** @var array<int, WorkflowTypeInterface> */
    private static array $types = [];

    public static function register(WorkflowTypeInterface $type): void
    {
        self::$types[$type->getId()] = $type;
    }

    /**
     * @throws \RuntimeException si aucun type n'est enregistre sous cet id —
     *     un type inconnu est un vrai bug (donnee corrompue, type retire sans
     *     migration) qui doit remonter immediatement plutot que de se rabattre
     *     silencieusement sur un type par defaut.
     */
    public static function get(int $id): WorkflowTypeInterface
    {
        return self::$types[$id]
            ?? throw new \RuntimeException("Plugin remise : type de workflow inconnu (id=$id).");
    }

    /** @return array<int, WorkflowTypeInterface> */
    public static function all(): array
    {
        return self::$types;
    }
}
