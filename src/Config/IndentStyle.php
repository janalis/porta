<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * A language's `indentStyle` setting, as `.porta.json` spells it. `Auto` defers
 * to the file's `.editorconfig`, then to what the file itself does.
 */
enum IndentStyle: string
{
    case Auto = 'auto';
    case Spaces = 'spaces';
    case Tabs = 'tabs';

    /**
     * The stored spellings, for the config validator and the settings choice.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $style): string => $style->value, self::cases());
    }
}
