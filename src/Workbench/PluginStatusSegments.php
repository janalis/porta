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
 * instead of plugin services. Several plugins with something to say share the
 * slot, joined by the hint dialect's separator.
 *
 * They all paint in {@see Role::Subtle}: an existing role, so no theme gains an
 * entry and the theme lock stays pinned. A plugin choosing its own colour is
 * exactly what that lock is there to stop.
 */
#[AsTaggedItem(priority: 100)]
final class PluginStatusSegments implements StatusSegment
{
    public function __construct(
        private readonly StatusSegmentSource $plugins,
    ) {}

    public function statusCell(): ?StatusCell
    {
        $segments = $this->plugins->statusSegments();

        return [] === $segments ? null : new StatusCell(implode(' · ', $segments));
    }
}
