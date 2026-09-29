<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The IDE was launched as git's difftool, on two files: git's `$LOCAL` and
 * `$REMOTE`, either of which may be a temp blob outside the project.
 *
 * A fact — the launch happened — dispatched once, right after {@see IdeReady}.
 * Core does not know git: the bundled git plugin answers by opening its view
 * on the comparison. When nothing listens, core opens `$right` in the editor
 * and says why, so a launch is never a blank screen.
 */
final readonly class DiffToolLaunched implements PluginObservable
{
    public function __construct(
        public string $left,
        public string $right,
    ) {}
}
