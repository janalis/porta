<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Widget\TabBarPosition;

/** The tab strip was re-seated around the editor body (tree already re-arranged). */
final readonly class TabBarPositionChanged
{
    public function __construct(
        public TabBarPosition $position,
    ) {}
}
