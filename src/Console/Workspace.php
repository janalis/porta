<?php

declare(strict_types=1);

namespace Porta\Console;

/**
 * The resolved target of a `porta` invocation: the workspace root, an optional
 * file to open, and an optional 1-based line to jump to.
 */
final class Workspace
{
    public function __construct(
        public readonly string $rootDir,
        public readonly ?string $initialFile = null,
        public readonly ?int $line = null,
    ) {}
}
