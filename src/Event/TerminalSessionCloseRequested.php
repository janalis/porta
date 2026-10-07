<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A terminal session should be closed for real — its ✖ was pressed, on the tab
 * strip or inside the panel.
 *
 * `-1` means "whichever session is active", which is the panel's own key path:
 * it knows a close was asked for, not which index that is.
 */
final readonly class TerminalSessionCloseRequested
{
    public function __construct(
        public int $index,
    ) {}
}
