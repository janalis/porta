<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Show the documentation page at $path in the Documentation window, opening
 * the window when it is closed.
 *
 * A plugin's Settings page offers its docs this way: the settings view and
 * the help window know nothing of each other, and the page lives wherever the
 * plugin is installed, not under Porta's own `docs/`.
 */
final readonly class HelpPageRequested
{
    public function __construct(
        public string $path,
    ) {}
}
