<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The git completion barrier, for any test that starts real git subprocesses —
 * `src` carries no test-only `isBusy()`, so it reads the runner's in-flight map
 * through the blessed {@see Probe}.
 *
 * Two reasons to wait rather than walk out. The first is correctness: git
 * mutates the tree before the exit callback fires, so an empty map is what
 * "the operation landed on disk" means. The second is hygiene: a test that
 * leaves mid-flight leaves a watcher armed on a stream whose child is about to
 * be reaped, which is a `[loop-leak]` and, before the isolation fallback
 * landed, a watcher the NEXT test inherited.
 *
 * Composed by {@see RunsIde}; a unit test pairs it with {@see PumpsLoop}.
 *
 * Untyped $git on purpose: git is a PLUGIN now, so `Porta\GitPlugin\GitInfo`
 * is not a name core's test support may hold — the arch gate would read it as
 * a core→plugin edge. What is reached through it is private state either way.
 */
trait AwaitsGit
{
    private function awaitGitInfo(object $git, float $timeout = 5.0): void
    {
        $runner = Probe::get($git, 'runner');
        $this->await(static fn (): bool => [] === Probe::get($runner, 'running'), $timeout, 'git to finish');
    }
}
