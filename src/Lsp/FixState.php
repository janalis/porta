<?php

declare(strict_types=1);

namespace Porta\Lsp;

/** Where a {@see FixLookup} stands: asked and not yet answered, answered with nothing, or a fix found. */
enum FixState
{
    case Pending;
    case None;
    case Found;
}
