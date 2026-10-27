<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Ui\LtrPane;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\StatusClickSpan;
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

    /**
     * The tab keys a pane standing where the editor stood answers as the
     * editor does — next and previous tab, close, Go to File, Save All — bound
     * to the editor's defaults, so tab management keeps working while the code
     * editor is detached ({@see EditorKeymap}). Answered by {@see tabKey()}.
     */
    protected const array TAB_KEYS = [
        'next_buffer' => ['ctrl+right'],
        'prev_buffer' => ['ctrl+left'],
        'close_buffer' => ['ctrl+f4', 'alt+w'],
        'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
        'save_all' => ['ctrl+alt+s'],
    ];

    protected ?ViewerTabHandler $handler = null;

    /** The tab actions re-bound while the editor is detached; nullable so a test can construct a bare pane. */
    final public function setHandler(ViewerTabHandler $handler): void
    {
        $this->handler = $handler;
    }

    /** Answer $data if it is one of the {@see TAB_KEYS} — the pane's own keys come first. */
    protected function tabKey(string $data): void
    {
        $kb = $this->getKeybindings();
        match (true) {
            $kb->matches($data, 'next_buffer') => $this->handler?->cycleBuffer(1),
            $kb->matches($data, 'prev_buffer') => $this->handler?->cycleBuffer(-1),
            $kb->matches($data, 'close_buffer') => $this->handler?->closeBuffer(),
            $kb->matches($data, 'goto_file') => $this->handler?->openGotoFile(),
            $kb->matches($data, 'save_all') => $this->handler?->saveAll(),
            default => null,
        };
    }

    /**
     * Whether this pane matches $action itself — one of its own default keys,
     * not one the IDE-wide map merely passes it. The keymap page asks, to know
     * whether a rebind reaches a widget that follows the live keymap.
     */
    public function bindsAction(string $action): bool
    {
        return \array_key_exists($action, static::getDefaultKeybindings());
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

    /**
     * The stretches of {@see statusRight()} a left click acts on, in columns
     * of that text: a key hint the pointer can press, as the zoom panes' `+`,
     * `-` and fit. Each names an action {@see runStatusAction()} runs. Most
     * panes' hints are only printed.
     *
     * @return list<StatusClickSpan>
     */
    public function statusClicks(): array
    {
        return [];
    }

    /**
     * Run $action, one {@see statusClicks()} named, and say whether it was
     * this pane's to run.
     */
    public function runStatusAction(string $action): bool
    {
        return false;
    }

    /**
     * Another pane (or the editor) took the slot. Free what only the VISIBLE
     * pane needs and keep the rest — the image pane drops its decoded pixels
     * here, while the graph pane's layout cache and the help pane's navigation
     * history survive a tab switch, which is what they did before this had a
     * name.
     */
    public function hide(): void {}

    /**
     * A wheel notch over this pane's body, in ROWS; negative scrolls up.
     *
     * Named `wheelBy` rather than `scrollBy` for the reason
     * {@see DockPanelWidget} gives: a navigable pane's `scrollBy()` takes
     * columns and HALF-rows, so sharing the name would make two different units
     * look interchangeable. The default pane does not scroll.
     */
    public function wheelBy(int $rows): void {}

    /**
     * A left press at a pane-relative cell, AFTER core has focused this pane.
     * Return true to arm a drag: the router then feeds motion to
     * {@see NavigablePaneWidget::scrollBy()} and turns a release without motion
     * into {@see NavigablePaneWidget::selectAt()}. The default is a pane that
     * does nothing with a press beyond taking focus.
     */
    public function press(int $relRow, int $relCol): bool
    {
        return false;
    }

    /**
     * The text this pane previews had its caret moved to ($line, $byteCol),
     * 0-based: bring what that text wrote into view — the other half of
     * click-to-source. Called when the caret reaches another line, never per
     * keystroke on the same one. A pane with nothing to follow ignores it.
     */
    public function followSource(int $line, int $byteCol): void {}

    /**
     * Whether the text this pane previews is on screen beside it (Split), or
     * not (a preview shown alone, or a viewer tab). What the text's own
     * diagnostics already say beside it, a pane shown alone may have to say
     * itself: its reader cannot see them.
     */
    public function textBeside(bool $beside): void {}

    /**
     * A second pane to seat on the editor's OTHER side while this one is a
     * preview beside it — flank | editor | this — or null for the usual
     * editor | this. The merge view's is its "ours" column, the editor holding
     * the result between it and "theirs".
     *
     * The flank is this pane's own: it is not registered, never a viewer tab,
     * and hears about the text only through this pane — {@see show()},
     * {@see followSource()} and the rest arrive here, and this pane passes on
     * what its flank needs. Core only seats it, routes the pointer to it and
     * puts it in the focus ring.
     */
    public function flank(): ?self
    {
        return null;
    }

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
