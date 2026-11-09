<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

/** The plugin store's four tabs, in strip order. */
enum StoreTab
{
    /** The featured plugins, then a shelf per category. */
    case Discover;
    /** Every category, each opening its whole shelf. */
    case Categories;
    /** The installed plugins the store has a newer release of. */
    case Updates;
    /** Every installed plugin, with the install scope and the install-from-anywhere field. */
    case Installed;

    /** The strip label, a translation id. */
    public function label(): string
    {
        return match ($this) {
            self::Discover => 'Discover',
            self::Categories => 'Categories',
            self::Updates => 'Updates',
            self::Installed => 'Installed',
        };
    }
}
