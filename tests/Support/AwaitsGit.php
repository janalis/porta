<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The git completion barrier, for any test that starts real git subprocesses.
 *
 * It waits on BOTH of the plugin's process lanes. It used to read one runner's
 * in-flight map, which was one lane of the four the plugin then had — so a
 * blame, a mark refresh or a fetch could still be running when a test walked
 * out, which is a flake and a `[loop-leak]` in whatever ran next.
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
 * The lanes arrive as a list of untyped objects, and both facts are deliberate.
 * Untyped, because git is a PLUGIN and `Porta\GitPlugin\Process\ProcessLane` is not a
 * name core's test support may hold — the arch gate would read it as a
 * core→plugin edge. A list, because the plugin keeps two of them on purpose:
 * remote operations are separately cancellable, so they are separately tracked,
 * and the caller walks to each rather than this deciding where they live.
 */
trait AwaitsGit
{
    /** @param list<object> $lanes every ProcessLane whose emptiness means "done" */
    private function awaitLanes(array $lanes, float $timeout = 5.0): void
    {
        $this->await(
            static function () use ($lanes): bool {
                foreach ($lanes as $lane) {
                    if (0 !== $lane->inFlight()) {
                        return false;
                    }
                }

                return true;
            },
            $timeout,
            'git to finish',
        );
    }
}
