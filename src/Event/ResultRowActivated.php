<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A result row was activated: open its location.
 *
 * The path is project-RELATIVE, as the row carries it — resolving it against a
 * root is the listener's job, not the widget's.
 */
final readonly class ResultRowActivated
{
    public function __construct(
        public string $relativePath,
        public int $line,
    ) {}
}
