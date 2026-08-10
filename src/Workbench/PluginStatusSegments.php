<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The plugins' slot in the status bar's prefix: one {@see StatusSegment} for
 * all of them, after every core contributor.
 *
 * One rather than one-per-plugin because the tag is a compiled container list
 * and a plugin cannot be in it — the same reason the pane registry has a drain
 * instead of plugin services.
 *
 * A plugin that returns a bare string shares ONE cell with every other such
 * plugin, joined by the hint dialect's separator and painted
 * {@see StatusTone::Subtle}. A plugin that returns a {@see StatusCell} gets its
 * own, because a cell carries a tone and a right-click menu and neither
 * survives being concatenated — that is how the git branch keeps its accent and
 * its branch menu now that git contributes it from outside `src/`.
 *
 * The tone is still core's to pick from, so no theme gains an entry and the
 * theme lock stays pinned. A plugin choosing its own COLOUR is what that lock
 * is there to stop.
 */
#[AsTaggedItem(priority: 100)]
final class PluginStatusSegments implements StatusSegment
{
    public function __construct(
        private readonly StatusSegmentSource $plugins,
    ) {}

    /** @return list<StatusCell> */
    public function statusCells(): array
    {
        $plain = [];
        $cells = [];
        foreach ($this->plugins->statusSegments() as $segment) {
            if ($segment instanceof StatusCell) {
                $cells[] = $segment;

                continue;
            }
            $plain[] = $segment;
        }
        if ([] !== $plain) {
            array_unshift($cells, new StatusCell(implode(' · ', $plain)));
        }

        return $cells;
    }
}
