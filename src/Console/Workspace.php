<?php

declare(strict_types=1);

namespace Porta\Console;

/**
 * The resolved target of a `porta` invocation: the workspace root, an optional
 * file to open, and an optional 1-based line to jump to.
 */
final readonly class Workspace
{
    public function __construct(
        public string $rootDir,
        public ?string $initialFile = null,
        public ?int $line = null,
    ) {}
}
