<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Ui\LtrPane;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A pane that can own the editor area's slot instead of the code editor: the
 * image and graph viewers, the help viewer, and anything a plugin registers
 * through {@see \Porta\Plugin\Api\PluginApi::registerPane()}.
 *
 * It is a class rather than an interface for the reason {@see DockPanelWidget}
 * gives: every caller needs an `AbstractWidget`. `BufferService` adds it to the
 * editor-body container, the mouse routers hand it to the hit-tester, and
 * `Tui::setFocus()` takes it — an interface plus a `widget()` accessor would be
 * an indirection each of those undoes immediately.
 *
 * The five traits are the pane baseline the three built-in viewers already
 * shared by hand. A pane is LTR (the RTL reorder leaves its columns verbatim),
 * fills its pane height, hosts the menu/modal/toast overlay spans, and carries
 * the {@see ViewerTabHandler} that routes tab keys back to the IDE while the
 * code editor is detached.
 */
abstract class EditorPaneWidget extends AbstractWidget implements FocusableInterface, LtrPane, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use StylesDefault;

    protected ?ViewerTabHandler $handler = null;

    /** The tab actions re-bound while the editor is detached; nullable so a test can construct a bare pane. */
    final public function setHandler(ViewerTabHandler $handler): void
    {
        $this->handler = $handler;
    }

    /** The routing id this pane answers to — the same id a viewer claim returns ('image', 'graph', 'help'). */
    abstract public function paneId(): string;

    /**
     * Display $path's payload — whatever this pane's loader produced for it.
     *
     * The payload is `mixed` on purpose: it is this pane's own type (decoded
     * pixels, DOT source, markdown), travelling through core untouched. A pane
     * narrows it on the way in; core never looks.
     */
    abstract public function show(string $path, mixed $payload): void;

    /** Drop what is shown (the tab closed, or the editor took the slot back). */
    abstract public function clear(): void;

    /**
     * This pane's status-bar segment, INCLUDING its own key hints — a pane knows
     * what its keys do and core does not.
     */
    abstract public function statusRight(): string;

    /** Drop per-path state (a remembered zoom, a cached layout); most panes keep none. */
    public function forget(string $path): void {}

    /** A theme switch re-bakes any SGR codes cached in rendered lines. */
    public function refreshTheme(): void
    {
        $this->invalidate();
    }

    /**
     * The funnel every zoom/pan/selection change ends in: repaint, and tell the
     * IDE so the status bar follows. A pane with work to reschedule (a debounced
     * re-render) overrides this and calls `parent::afterViewChange()` first.
     */
    protected function afterViewChange(): void
    {
        $this->invalidate();
        $this->handler?->viewChanged();
    }
}
