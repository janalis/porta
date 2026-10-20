<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Editor\EditorPanes;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The panes every floating surface composites over — the editor layout's,
 * which stay mounted under an open view window (itself a floating layer on
 * them), plus the registered panes and the workspace's edge rules. Bundles
 * what were fifteen positional widget arguments to
 * {@see MenuOverlayController} into one value. Delivering and clearing walk
 * the same list: no surface is ever painted anywhere else.
 */
final readonly class MenuOverlayPanes
{
    /**
     * @param list<AbstractWidget&MenuOverlayHost> $editor tree, separator, tab bar, editor, breadcrumbs, find bar
     * @param list<AbstractWidget&MenuOverlayHost> $chrome the workspace's edge rules
     */
    public function __construct(
        private array $editor,
        private ?EditorPanes $panes = null,
        private array $chrome = [],
    ) {}

    /**
     * The panes, asked at call time: this value is built while the widget
     * tree is assembled, and panes register at the constructor tail — a
     * plugin's even later, when it is enabled live.
     *
     * @return list<AbstractWidget&MenuOverlayHost>
     */
    public function current(): array
    {
        $panes = $this->panes?->all() ?? [];
        // A pane's flank is seated beside the editor without being registered.
        $flanks = \array_filter(\array_map(static fn (EditorPaneWidget $pane): ?EditorPaneWidget => $pane->flank(), $panes));

        return [...$this->editor, ...$panes, ...\array_values($flanks), ...$this->chrome];
    }
}
