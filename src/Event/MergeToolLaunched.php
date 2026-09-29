<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The IDE was launched as git's mergetool: git's `$LOCAL`, `$REMOTE`, `$BASE`
 * and `$MERGED`, the last being the working-tree file with the conflict
 * markers, which is the one to resolve and save.
 *
 * A fact, dispatched once right after {@see IdeReady}; the git plugin answers.
 * Whoever answers, CORE gives git its verdict: the process exits 0 when
 * `$merged` holds no conflict block at quit, 1 otherwise — what
 * `mergetool.<tool>.trustExitCode` reads.
 */
final readonly class MergeToolLaunched implements PluginObservable
{
    public function __construct(
        public string $local,
        public string $remote,
        public string $base,
        public string $merged,
    ) {}
}
