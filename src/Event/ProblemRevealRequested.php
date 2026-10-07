<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Editor\DiagnosticSeverity;

/**
 * Show this problem in the Problems panel: open the panel and select its row.
 *
 * Raised where a problem is read — its message in the status bar, its row at
 * the top of the Alt+Enter list — which know nothing of the dock.
 */
final readonly class ProblemRevealRequested
{
    public function __construct(
        /** The file the problem is in, absolute. */
        public string $path,
        /** 0-based. */
        public int $line,
        public DiagnosticSeverity $severity,
        public string $message,
    ) {}
}
