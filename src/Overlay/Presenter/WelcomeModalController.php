<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Onboarding\WelcomeSteps;
use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\WindowPlacement;
use Porta\Overlay\Region\WindowZone;
use Porta\Overlay\State\WindowControls;
use Porta\Overlay\State\WindowFrame;
use Porta\Ui\FrameHooks;
use Porta\Ui\Theme\Styles;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Render\PopupMetrics;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\RenderContext;
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
 * dispatcher's modal branch). A press outside the box closes it, as it closes
 * every floating window, and never reaches the panes beneath; the menu bar
 * stays reachable, by routing order.
 *
 * Geometry is computed from the previous frame's pane-rect union — rows
 * outside it (the menu bar, the status bar) are not overlay hosts and would
 * simply not paint, so clamping to it is correctness, not polish. The rects
 * do not exist at first-run open time (no frame has rendered), so a
 * {@see FrameHooks::onBeforeFrame} hook lays the box out as soon as they do and
 * re-clamps it when a resize moves them. It moves and resizes as every window
 * does ({@see WindowFrame}); both are session-only — reopening re-centers.
 *
 * Like the view controllers it replaces, it attaches itself to
 * {@see \Porta\Workbench\View\ViewRegistry} in its constructor and has no inbound service
 * references, so {@see \Porta\Ide} resolves it eagerly for that side effect.
 */
#[AsIdeService]
#[AsEager(priority: 400)]
final class WelcomeModalController implements ContributedView
{
    use StylesDefault;

    /** The frame's widest content column; matches the widget's own comfortable cap. */
    private const int MAX_INNER_WIDTH = 76;

    /** The tallest content window — past this the cards scroll. */
    private const int MAX_INNER_HEIGHT = 18;

    /** The content window's floors: a narrower or shorter box has no card left to read. */
    private const int MIN_INNER_WIDTH = 20;

    private const int MIN_INNER_HEIGHT = 5;

    private bool $open = false;

    /** Where the user moved the box and how big they made it. */
    private readonly WindowFrame $frame;

    /** Which end of the title bar the ✖ sits at: the platform's. */
    private readonly CloseButton $closeButton;

    private ?ModalBox $box = null;

    /** The box plus its painted shadow bands, for the RTL segmenter. */
    private ?DropdownRegion $shadowRegion = null;

    /** @var array{int, int, int, int}|null the pane-union bounds of the last compose, for the per-frame re-sync */
    private ?array $lastBounds = null;

    public function __construct(
        private readonly OverlayDelivery $delivery,
        private readonly WelcomeWidget $welcome,
        private readonly WelcomeSteps $steps,
        private readonly MenuBarWidget $menuBar,
        private readonly EditorArea $editorArea,
        private readonly Tui $tui,
        ViewRegistry $views,
        ?Styles $styles = null,
        ?FrameHooks $frameHooks = null,
        ?WindowControls $controls = null,
    ) {
        $this->initStyles($styles);
        $this->frame = new WindowFrame();
        $this->closeButton = $controls->closeButton ?? CloseButton::Standard;
        $views->register($this);
        $this->welcome
            ->onExit($this->close(...))
            ->onRan($this->refresh(...));
        $frameHooks?->onBeforeFrame($this->syncBeforeFrame(...));
    }

    #[\Override]
    public function viewId(): string
    {
        return 'welcome';
    }

    #[\Override]
    public function hostsWindow(): bool
    {
        return false;
    }

    #[\Override]
    public function title(): string
    {
        return $this->welcome->title();
    }

    #[\Override]
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * None: ModalWindowRouter holds THIS controller, so the reference cannot
     * run the other way — and it does not need to. The modal is app-modal, so
     * MouseRouter answers it in its own branch before any view dispatch.
     */
    #[\Override]
    public function pointer(): ?ViewPointer
    {
        return null;
    }

    /** The modal offers no context menu; a right-click over it does nothing. */
    #[\Override]
    public function contextMenus(): ?ViewMenuSource
    {
        return null;
    }

    #[\Override]
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->welcome;
    }

    /**
     * No focus ring of its own: while the modal is open the dispatcher's
     * modal branch swallows every key, so the coordinator falls through.
     */
    #[\Override]
    public function cycleFocus(int $direction): bool
    {
        return false;
    }

    /**
     * The box (shadow bands included) as a floating region for RTL
     * segmentation and hit-tests; null while closed or unplaced. Hit-tests on
     * the modal itself keep using the un-shadowed {@see ModalBox}, so a click
     * on a shadow cell is an outside click — swallowed like the rest.
     */
    public function region(): ?DropdownRegion
    {
        return $this->open ? $this->shadowRegion : null;
    }

    #[\Override]
    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    #[\Override]
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
        $this->frame->reset();
        $this->box = null;
        $this->shadowRegion = null;

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

    #[\Override]
    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->box = null;
        $this->shadowRegion = null;
        $this->frame->cancel();
        $this->menuBar->close();
        // Clear every mode's pane set: the modal may close across a view swap.
        $this->delivery->clearModal();
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
     * A left press while the modal is open. The ✖ and a press outside the box
     * close it; the title bar and the frame's edges arm a move or a resize; a
     * content cell selects its card.
     */
    public function clickAt(int $row, int $col): bool
    {
        $box = $this->box;
        if (!$this->open || null === $box) {
            return false;
        }
        $zone = $this->frame->press($box, $row, $col);
        if (WindowZone::Close === $zone || WindowZone::Outside === $zone) {
            $this->close();
        }
        if (WindowZone::Content === $zone) {
            $this->tui->setFocus($this->welcome);
            $this->welcome->handleClick($row - $box->windowTop());
            $this->compose();
        }

        return true;
    }

    /** Tint the card (or the ✖) under the pointer; returns whether anything changed. */
    public function hoverAt(int $row, int $col): bool
    {
        $box = $this->box;
        if (!$this->open || null === $box) {
            return false;
        }
        $frameChanged = $this->frame->hover($box, $row, $col);

        $contentRow = $row - $box->windowTop();
        $inContent = $box->contains($row, $col) && $contentRow >= 0 && $contentRow < $box->count;
        $rowChanged = $this->welcome->hoverRow($inContent ? $contentRow : -1);
        $changed = $frameChanged || $rowChanged;

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

    /**
     * Continue (or finish, on release) whichever drag is armed — a move or a
     * resize, both {@see WindowFrame}'s; compose clamps the result.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function continueDragTo(int $row, int $col, bool $release): bool
    {
        if (!$this->frame->dragTo($row, $col, $release)) {
            return false;
        }
        $this->compose();
        $this->tui->requestRender();

        return true;
    }

    /** A fresh press anywhere disarms a stale drag (the router's cancel sweep). */
    public function cancelDrag(): void
    {
        $this->frame->cancel();
    }

    /**
     * The per-frame re-sync ({@see FrameHooks::onBeforeFrame}): lay the box out
     * once rects exist (first-run opens before any frame) and re-clamp it when
     * the pane union moved (terminal resize). No-ops on a settled frame.
     */
    private function syncBeforeFrame(): void
    {
        if (!$this->open) {
            return;
        }
        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
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
        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
            return; // pre-first-frame: the before-frame hook (same retry path) re-runs this
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $this->lastBounds = $bounds;

        // Height budgets the frame chrome only (no breathing rows): on a
        // 24-row terminal the content window keeps the 18 rows that fit the
        // five standard onboarding cards — the padding must not scroll the
        // last one off. A Symfony project adds a sixth card, which overflows
        // this window by design: the widget's followHeights() scrolls the
        // selection into view, so navigating down reveals what the fold hides.
        $place = $this->frame->place(
            $bounds,
            \min(self::MAX_INNER_WIDTH, $boundRight - $boundLeft - PopupMetrics::CENTERED_MARGIN) + ModalFrame::CHROME_COLS,
            \min(self::MAX_INNER_HEIGHT, $boundBottom - $boundTop - ModalFrame::CHROME_ROWS) + ModalFrame::CHROME_ROWS,
            WindowPlacement::UpperThird,
            self::MIN_INNER_WIDTH + ModalFrame::CHROME_COLS,
            self::MIN_INNER_HEIGHT + ModalFrame::CHROME_ROWS,
        );
        [$top, $col, $width, $rows] = [$place->top, $place->col, $place->width, $place->rows];
        $innerWidth = $width - ModalFrame::CHROME_COLS;
        $innerHeight = $rows - ModalFrame::CHROME_ROWS;
        $content = \array_values($this->welcome->render(new RenderContext($innerWidth, $innerHeight)));
        $hot = $this->frame->hot();
        $panel = [
            ModalFrame::titleBar($this->welcome->title(), $width, $this->styles, $hot, closeButton: $this->closeButton),
            ...ModalFrame::wrap($content, $innerWidth, $this->styles, $hot),
        ];

        $this->delivery->deliverModal([['rows' => $panel, 'top' => $top, 'col' => $col, 'width' => $width]]);
        $this->box = new ModalBox($top, $col, $width, $rows, headerRows: ModalFrame::CHROME_ROWS_TOP, windowStart: 0, count: $innerHeight, closeButton: $this->closeButton);
        $this->shadowRegion = DropdownRegion::shadowClamped($top, $col, $width, $rows, $bounds, shown: $this->delivery->shown());
    }
}
