<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Container\ToolLaunch;

/**
 * The resolved target of a `porta` invocation: the workspace root, an optional
 * file to open, an optional 1-based line to jump to — or, for a launch as git's
 * difftool/mergetool, the files git handed over.
 */
final readonly class Workspace
{
    public function __construct(
        public string $rootDir,
        public ?string $initialFile = null,
        public ?int $line = null,
        public ?ToolLaunch $tool = null,
    ) {}
}
