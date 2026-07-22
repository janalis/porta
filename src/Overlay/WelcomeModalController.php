<?php

declare(strict_types=1);

namespace Porta\Overlay;

use Porta\Onboarding\WelcomeSteps;
use Porta\Support\IntMath;
use Porta\Ui\BidiTui;
use Porta\Ui\EditorArea;
use Porta\Ui\Theme\Styles;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Porta\Widget\Render\ModalFrame;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The Welcome screen as a floating, draggable modal: a framed box
 * ({@see ModalFrame}) composited over the editor layout's panes through the
 * same span-slicing path the menu dropdowns use, so the workspace stays
 * mounted (and visible) behind it. The {@see WelcomeWidget} renders off-tree
 * into the frame's content area; nothing is swapped in the widget tree.
 *
 * Modality: while open, the keyboard is captured whole (the input
 * dispatcher's modal branch) and every mouse press inside or outside the box
 * is swallowed — an outside click neither dismisses (the × and Esc do that;
 * accidental clicks must not dump the onboarding context) nor reaches the
 * panes beneath. Only the menu bar stays reachable, by routing order.
 *
 * Geometry is computed from the previous frame's pane-rect union — rows
 * outside it (the menu bar, the status bar) are not overlay hosts and would
 * simply not paint, so clamping to it is correctness, not polish. The rects
 * do not exist at first-run open time (no frame has rendered), so a
 * {@see BidiTui::onBeforeFrame} hook lays the box out as soon as they do and
 * re-clamps it when a resize moves them; drag positions are session-only —
 * reopening re-centers.
 *
 * Like the view controllers it replaces, it attaches itself to
 * {@see WorkspaceViews} in its constructor and has no inbound service
 * references, so {@see \Porta\Ide} resolves it eagerly for that side effect.
 */
final class WelcomeModalController
{
    /** The frame's widest content column; matches the widget's own comfortable cap. */
    private const int MAX_INNER_WIDTH = 76;

    /** The tallest content window — past this the cards scroll. */
    private const int MAX_INNER_HEIGHT = 18;

    private bool $open = false;

    /** Box top/left; null while auto-centered (recomputed until dragged). */
    private ?int $top = null;
    private ?int $col = null;

    /** @var array{rowOffset: int, colOffset: int}|null a title-bar drag in progress */
    private ?array $drag = null;

    private bool $closeHovered = false;

    private ?ModalBox $box = null;

    /** @var array{int, int, int, int}|null the pane-union bounds of the last compose, for the per-frame re-sync */
    private ?array $lastBounds = null;

    private readonly Styles $styles;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuOverlayPanes $panes,
        private readonly WelcomeWidget $welcome,
        private readonly WelcomeSteps $steps,
        private readonly MenuBarWidget $menuBar,
        private readonly EditorArea $editorArea,
        private readonly Tui $tui,
        WorkspaceViews $views,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $views->attachWelcome($this);
        $this->welcome
            ->onExit($this->close(...))
            ->onRan($this->refresh(...));
        if ($this->tui instanceof BidiTui) {
            $this->tui->onBeforeFrame($this->syncBeforeFrame(...));
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->welcome;
    }

    /** The box as a floating region for RTL segmentation and hit-tests; null while closed or unplaced. */
    public function region(): ?DropdownRegion
    {
        return $this->open ? $this->box?->region() : null;
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->open = true;
        // The menu bar stays put; drop any open dropdown so its tracked hit
        // region cannot go stale across the open.
        $this->menuBar->close();
        // Reopening re-centers deliberately: a dragged position goes stale
        // across terminal sizes and this is a transient surface.
        $this->top = null;
        $this->col = null;
        $this->drag = null;
        $this->closeHovered = false;
        $this->box = null;

        // Refresh first so the reset sees the freshly built steps when it
        // looks for the first pending one.
        $this->welcome->setSteps($this->steps->all());
        $this->welcome->resetView();
        // Focus drives the selection's :focus styling; keys reach the widget
        // through the dispatcher's modal branch regardless.
        $this->tui->setFocus($this->welcome);
        $this->compose();
        $this->tui->requestRender();
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->box = null;
        $this->drag = null;
        $this->menuBar->close();
        // Clear every mode's pane set: the modal may close across a view swap.
        foreach ($this->panes->all() as $pane) {
            $pane->setModalOverlay([]);
        }
        $this->tui->setFocus($this->editorArea->widget());
        $this->tui->requestRender();
    }

    /** Rebuild the steps so one just completed reads as done, and repaint. */
    public function refresh(): void
    {
        $this->welcome->setSteps($this->steps->all());
        $this->compose();
        $this->tui->requestRender();
    }

    /** Modal keyboard handling: the widget takes every key (Esc exits via its hook). */
    public function handleKey(string $data): void
    {
        $this->welcome->handleInput($data);
        $this->compose();
    }

    /**
     * A left press while the modal is open. The × closes; the rest of the
     * title bar arms a drag; a content cell selects its card; anywhere else —
     * inside or out — is swallowed without dismissing.
     */
    public function clickAt(int $row, int $col): bool
    {
        $box = $this->box;
        if (!$this->open || null === $box) {
            return false;
        }
        if ($box->closeHit($row, $col)) {
            $this->close();

            return true;
        }
        if ($row === $box->startRow && $box->contains($row, $col)) {
            // Freeze the auto-centered position into an absolute one, then
            // capture the grab offset so the box moves with the pointer.
            $this->top = $box->startRow;
            $this->col = $box->col;
            $this->drag = ['rowOffset' => $row - $box->startRow, 'colOffset' => $col - $box->col];

            return true;
        }
        if ($box->contains($row, $col)) {
            $this->tui->setFocus($this->welcome);
            $this->welcome->handleClick($row - $box->startRow - 1);
            $this->compose();
        }

        return true;
    }

    /** Tint the card (or the ×) under the pointer; returns whether anything changed. */
    public function hoverAt(int $row, int $col): bool
    {
        $box = $this->box;
        if (!$this->open || null === $box) {
            return false;
        }
        $closeHovered = $box->closeHit($row, $col);
        $changed = $closeHovered !== $this->closeHovered;
        $this->closeHovered = $closeHovered;

        $inContent = $box->contains($row, $col) && $row > $box->startRow;
        $changed = $this->welcome->hoverRow($inContent ? $row - $box->startRow - 1 : -1) || $changed;

        if ($changed) {
            $this->compose();
        }

        return $changed;
    }

    /** Wheel over the box scrolls the cards; anywhere else it is swallowed (modal). */
    public function wheelAt(int $row, int $col, int $rows): bool
    {
        $box = $this->box;
        if (!$this->open || null === $box) {
            return false;
        }
        if ($box->contains($row, $col)) {
            $this->welcome->scrollBy($rows);
            $this->compose();
            $this->tui->requestRender();
        }

        return true;
    }

    /** Continue (or finish, on release) a title-bar drag; clamping happens in compose. */
    public function continueDragTo(int $row, int $col, bool $release): bool
    {
        $drag = $this->drag;
        if (null === $drag) {
            return false;
        }
        $this->top = $row - $drag['rowOffset'];
        $this->col = $col - $drag['colOffset'];
        if ($release) {
            $this->drag = null;
        }
        $this->compose();
        $this->tui->requestRender();

        return true;
    }

    /** A fresh press anywhere disarms a stale drag (the router's cancel sweep). */
    public function cancelDrag(): void
    {
        $this->drag = null;
    }

    /**
     * The per-frame re-sync ({@see BidiTui::onBeforeFrame}): lay the box out
     * once rects exist (first-run opens before any frame) and re-clamp it when
     * the pane union moved (terminal resize). No-ops on a settled frame.
     */
    private function syncBeforeFrame(): void
    {
        if (!$this->open) {
            return;
        }
        $bounds = $this->paneBounds();
        if (null === $bounds) {
            // No rects yet: keep asking for frames until the first one lands.
            $this->tui->requestRender();

            return;
        }
        if (null === $this->box || $bounds !== $this->lastBounds) {
            $this->compose();
        }
    }

    /**
     * The render funnel: render the widget off-tree, wrap it in the frame,
     * clamp the position to the pane union, slice into per-pane spans and
     * push them. Every mutation ends here so the box on screen can never go
     * stale against the state it draws.
     */
    private function compose(): void
    {
        if (!$this->open) {
            return;
        }
        $bounds = $this->paneBounds();
        if (null === $bounds) {
            return; // pre-first-frame: the before-frame hook will retry
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $this->lastBounds = $bounds;

        $innerWidth = max(20, min(self::MAX_INNER_WIDTH, $boundRight - $boundLeft - 6));
        $innerHeight = max(5, min(self::MAX_INNER_HEIGHT, $boundBottom - $boundTop - 4));
        $width = $innerWidth + 2;
        $content = array_values($this->welcome->render(new RenderContext($innerWidth, $innerHeight)));
        $panel = [
            ModalFrame::titleBar($this->welcome->title(), $width, $this->styles, $this->closeHovered),
            ...ModalFrame::wrap($content, $innerWidth, $this->styles),
        ];
        $rows = \count($panel);

        // Auto-center (a third down, like the pickers) until a drag freezes an
        // absolute position; clamp either into the pane union.
        $top = $this->top ?? ($boundTop + max(0, intdiv($boundBottom - $boundTop - $rows, num2: 3)));
        $col = $this->col ?? ($boundLeft + max(0, intdiv($boundRight - $boundLeft - $width, num2: 2)));
        $top = IntMath::clamp($top, $boundTop, max($boundTop, $boundBottom - $rows));
        $col = IntMath::clamp($col, $boundLeft, max($boundLeft, $boundRight - $width));
        if (null !== $this->top || null !== $this->col) {
            $this->top = $top;
            $this->col = $col;
        }

        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            $pane->setModalOverlay(null !== $rect ? SpanSlicer::localSpans($panel, $rect, $top, $col, $width) : []);
        }
        $this->box = new ModalBox($top, $col, $width, $rows, headerRows: 1, windowStart: 0, count: $innerHeight);
    }

    /**
     * The union of the current pane set's rects — the paintable screen area.
     *
     * @return array{int, int, int, int}|null [top, left, bottom, right), or null before the first frame
     */
    private function paneBounds(): ?array
    {
        $top = PHP_INT_MAX;
        $left = PHP_INT_MAX;
        $bottom = 0;
        $right = 0;
        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            if (null === $rect) {
                continue;
            }
            $top = min($top, $rect->row);
            $left = min($left, $rect->col);
            $bottom = max($bottom, $rect->row + $rect->rows);
            $right = max($right, $rect->col + $rect->columns);
        }

        return $bottom > $top && $right > $left ? [$top, $left, $bottom, $right] : null;
    }
}
