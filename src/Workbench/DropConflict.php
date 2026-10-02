<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * What a drop does with a name its target folder already holds.
 *
 * `Ask` is the tree's own drag and paste: the user grabbed files they can see,
 * and an overwrite is theirs to decide. `KeepBoth` is a drop from OUTSIDE the
 * IDE — Finder's rule — where the source is somebody else's file and the copy
 * takes a free name beside the one it met, never a prompt and never a loss.
 */
enum DropConflict
{
    case Ask;
    case KeepBoth;
}
