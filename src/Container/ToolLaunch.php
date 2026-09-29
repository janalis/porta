<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * The IDE was launched as git's difftool or mergetool rather than as an editor:
 * which of the two, and the files git handed over, in git's own order.
 *
 * Its presence changes what a launch leaves behind as much as what it shows. A
 * tool run is EPHEMERAL: git calls it once per file, on temp blobs, from the
 * work-tree top, and none of that is the user's project session — so nothing is
 * recorded as a recent project, no Welcome is shown, and above all the project's
 * `session.json` is neither restored nor overwritten with one diff's tabs.
 */
final readonly class ToolLaunch
{
    /** @param list<string> $paths git's `$LOCAL $REMOTE` (diff) or `$LOCAL $REMOTE $BASE $MERGED` (merge) */
    private function __construct(
        public ToolMode $mode,
        public array $paths,
    ) {}

    public static function diff(string $left, string $right): self
    {
        return new self(ToolMode::Diff, [$left, $right]);
    }

    public static function merge(string $local, string $remote, string $base, string $merged): self
    {
        return new self(ToolMode::Merge, [$local, $remote, $base, $merged]);
    }

    /** The file the editor opens when nothing answers the launch: the result of a merge, the right side of a diff. */
    public function editable(): string
    {
        return ToolMode::Merge === $this->mode ? $this->paths[3] : $this->paths[1];
    }
}
