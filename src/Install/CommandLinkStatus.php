<?php

declare(strict_types=1);

namespace Porta\Install;

/** Where the `porta` command stands ({@see CommandLink::status()}). */
enum CommandLinkStatus
{
    /** No `porta` on PATH, nor in `~/.local/bin`. */
    case Absent;

    /** A link to this binary. */
    case Current;

    /** A link to another one — a moved checkout, another phar, or nothing any more. */
    case Stale;

    /** A `porta` that is not a link: installed some other way, and not Porta's to change. */
    case Foreign;
}
