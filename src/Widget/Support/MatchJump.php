<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Whether recomputing the find-bar matches also moves the caret to the nearest
 * match. Replaces the former `bool $jumpToNearest` flag on
 * {@see FindBarWidget}'s recompute.
 */
enum MatchJump
{
    case ToNearest;
    case None;
}
