<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * The result groups shown in the Search Everywhere modal, in display order. Each
 * category caps how many of its hits are shown so no one source floods the list.
 */
enum SearchCategory
{
    case Files;
    case Classes;
    case Symbols;
    case Actions;
    case Text;

    /** The group header label (English source string; translated at render time). */
    public function title(): string
    {
        return match ($this) {
            self::Files => 'Files',
            self::Classes => 'Classes',
            self::Symbols => 'Symbols',
            self::Actions => 'Actions',
            self::Text => 'Text',
        };
    }

    /** Position of the group in the results (lower shows first). */
    public function order(): int
    {
        return match ($this) {
            self::Files => 0,
            self::Classes => 1,
            self::Symbols => 2,
            self::Actions => 3,
            self::Text => 4,
        };
    }

    /** Max hits shown for this category. */
    public function cap(): int
    {
        return 5;
    }
}
