<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which side of a tab {@see BufferService::closeTabsToTheSide()} clears.
 *
 * An enum rather than a `bool $right`, for the reason {@see ProblemStep} gives:
 * the two verbs read the same at the call site and a flag would say nothing at
 * it.
 */
enum TabSide
{
    case Left;
    case Right;
}
