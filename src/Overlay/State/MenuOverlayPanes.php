<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Editor\EditorPanes;
use Porta\Widget\Render\MenuOverlayHost;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The panes a floating menu can composite over, grouped by which view owns the
 * main area: the editor layout's panes, the git view's, and the settings view's.
 * Bundles what were fifteen positional widget arguments to
 * {@see MenuOverlayController} into one value that answers the two questions
 * the controller asks — the current mode's panes (both dropdowns composite
 * over them), and every pane for a blanket overlay clear.
 */
final readonly class MenuOverlayPanes
{
    /**
     * @param list<AbstractWidget&MenuOverlayHost>            $editor    tree, separator, tab bar, editor, breadcrumbs, find bar
     * @param \Closure(): list<AbstractWidget&MenuOverlayHost> $viewPanes the OPEN view's panes; empty when none is
     * @param list<AbstractWidget&MenuOverlayHost>            $chrome    the workspace's edge rules, under every view
     */
    public function __construct(
        private array $editor,
        private \Closure $viewPanes,
        private ?EditorPanes $panes = null,
        private array $chrome = [],
    ) {}

    /**
     * The panes the app-menu dropdown floats over right now: the open view's,
     * else the editor layout's.
     *
     * Asked, not baked: two lists were held here, one per view core knew, each
     * naming that view's widgets. A view's panes are whatever is in its region,
     * which it fills itself, so the answer has to be computed when the question
     * is asked.
     *
     * @return list<AbstractWidget&MenuOverlayHost>
     */
    public function current(): array
    {
        $view = ($this->viewPanes)();
        if ([] !== $view) {
            return [...$view, ...$this->chrome];
        }

        // The registered panes are appended at ASK time too: this value is
        // built while the widget tree is assembled, and panes register at the
        // constructor tail — a plugin's even later, when it is enabled live.
        return [...$this->editor, ...($this->panes?->all() ?? []), ...$this->chrome];
    }

    /**
     * The editor's panes plus whichever view is open, for clearing a dropdown
     * that may close across a view swap (clearing a detached widget is
     * harmless, and a closed view's are unreachable anyway).
     *
     * @return list<AbstractWidget&MenuOverlayHost>
     */
    public function all(): array
    {
        return [...$this->editor, ...($this->viewPanes)(), ...$this->chrome];
    }
}
