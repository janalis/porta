<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * Human-readable labels for every rebindable action, used by the command
 * palette (Find Action). Served from the {@see ActionCatalog} descriptor
 * table; unknown actions fall back to a title-cased slug.
 */
final class ActionRegistry
{
    public static function label(string $action): string
    {
        return ActionCatalog::ACTIONS[$action]['label'] ?? ucwords(str_replace('_', ' ', $action));
    }

    /** @return array<string, string> action => label */
    public static function all(): array
    {
        return ActionCatalog::labels();
    }
}
