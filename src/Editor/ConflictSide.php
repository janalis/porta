<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which side of a merge conflict survives a resolution. Backed because the
 * resolve menu is a list overlay, whose item values are strings: the picked
 * value comes back through {@see self::from()}, so a value the menu never
 * offered is a loud ValueError instead of an unmatched match.
 */
enum ConflictSide: string
{
    case Ours = 'ours';
    case Theirs = 'theirs';
    case Both = 'both';
}
