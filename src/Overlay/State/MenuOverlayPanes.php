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
     * @param list<AbstractWidget&MenuOverlayHost> $editor   tree, separator, tab bar, editor, breadcrumbs
     * @param list<AbstractWidget&MenuOverlayHost> $git      the git view's panes
     * @param list<AbstractWidget&MenuOverlayHost> $settings the settings view's panes
     * @param \Closure(): bool                     $gitViewOpen
     * @param \Closure(): bool                     $settingsViewOpen
     */
    public function __construct(
        private array $editor,
        private array $git,
        private array $settings,
        private \Closure $gitViewOpen,
        private \Closure $settingsViewOpen,
        private ?EditorPanes $panes = null,
    ) {}

    /**
     * The panes the app-menu dropdown floats over in the current mode: the
     * settings view's, else the git view's, else the editor layout's.
     *
     * @return list<AbstractWidget&MenuOverlayHost>
     */
    public function current(): array
    {
        return match (true) {
            ($this->settingsViewOpen)() => $this->settings,
            ($this->gitViewOpen)() => $this->git,
            // The registered panes are appended at ASK time, not baked in: this
            // value is built while the widget tree is assembled, and panes
            // register at the constructor tail — a plugin's even later, when it
            // is enabled live.
            default => [...$this->editor, ...($this->panes?->all() ?? [])],
        };
    }

    /**
     * Every pane across all modes, for clearing a dropdown that may close across
     * a view swap (clearing a detached widget is harmless).
     *
     * @return list<AbstractWidget&MenuOverlayHost>
     */
    public function all(): array
    {
        return [...$this->editor, ...$this->git, ...$this->settings];
    }
}
