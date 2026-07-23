<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Where the editor tab strip sits around the editor body: a one-row
 * horizontal bar (top/bottom) or a fixed-width vertical list (left/right).
 */
enum TabBarPosition: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';

    /** The vertical-list placements beside the editor body. */
    public function isSide(): bool
    {
        return self::Left === $this || self::Right === $this;
    }

    /** Whether the strip precedes the editor body in the pane's child order. */
    public function tabsFirst(): bool
    {
        return self::Top === $this || self::Left === $this;
    }
}
