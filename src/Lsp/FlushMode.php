<?php

declare(strict_types=1);

namespace Porta\Lsp;

/** Whether a readiness check flushes the debounced pending edit first or leaves it. */
enum FlushMode
{
    case Flush;
    case Keep;
}
