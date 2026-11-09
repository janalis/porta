<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

/**
 * The kind of a row in the settings navigation sidebar, which is a tree: a
 * top-level page, a folder (Plugins, which is a page too, and Languages,
 * which is not), a plugin's page inside Plugins, or a language's inside
 * Languages. Drives the indent, the disclosure triangle, and which folder a
 * row hides with in {@see SettingsNavWidget}.
 */
enum NavEntryKind
{
    case Section;
    case Folder;
    case Plugin;
    /** The plugin store, the first page inside Plugins. */
    case Store;
    case Language;

    /** The folder a row of this kind sits in, or null for a top-level row. */
    public function folder(): ?string
    {
        return match ($this) {
            self::Plugin, self::Store => 'plugins',
            self::Language => 'languages',
            default => null,
        };
    }
}
