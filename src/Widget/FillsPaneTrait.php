<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Default {@see \Symfony\Component\Tui\Widget\VerticallyExpandableInterface}
 * implementation for widgets that always fill their pane's height: expansion is
 * inherent, so enabling/disabling it is a no-op. Shared by the tree, editor, git
 * and settings panes instead of each repeating the two-line stub.
 */
trait FillsPaneTrait
{
    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // always fills its pane height
    }
}
