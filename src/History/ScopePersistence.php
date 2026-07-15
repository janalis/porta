<?php

declare(strict_types=1);

namespace Porta\History;

/**
 * How a saved Find-in-Files scope is retained: a {@see self::Recent} entry is
 * capped and evicted oldest-first, a {@see self::Pinned} entry is never evicted.
 * Replaces the former `bool $pinned` flag on {@see SearchScopeStore::promote()}.
 */
enum ScopePersistence
{
    case Recent;
    case Pinned;
}
