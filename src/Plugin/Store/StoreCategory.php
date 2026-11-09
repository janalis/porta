<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * The store's shelves: a closed list, so a category is something to filter
 * by rather than free text every author spells their own way.
 *
 * A plugin names its own in `extra.porta.store.category`; a curated entry in
 * Porta's `store/plugins.json` overrides it. Anything else — a typo, a
 * category from a newer Porta — is {@see self::Other}, never a refusal: a
 * plugin is listed whatever it calls itself.
 */
enum StoreCategory: string
{
    case Languages = 'languages';
    case Linters = 'linters';
    case Testing = 'testing';
    case Debugging = 'debugging';
    case VersionControl = 'version-control';
    case Remote = 'remote';
    case Viewers = 'viewers';
    case Ai = 'ai';
    case Productivity = 'productivity';
    case Other = 'other';

    /** The category $value names, or {@see self::Other} for anything else. */
    public static function read(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::Other : self::Other;
    }

    /** The shelf's English name, a translation id. */
    public function label(): string
    {
        return match ($this) {
            self::Languages => 'Languages',
            self::Linters => 'Linters & formatters',
            self::Testing => 'Testing',
            self::Debugging => 'Debugging',
            self::VersionControl => 'Version control',
            self::Remote => 'Remote',
            self::Viewers => 'Viewers',
            self::Ai => 'AI',
            self::Productivity => 'Productivity',
            self::Other => 'Other',
        };
    }
}
