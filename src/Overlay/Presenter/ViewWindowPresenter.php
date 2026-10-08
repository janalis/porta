<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Layout\LayoutController;
use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\WindowPlacement;
use Porta\Overlay\Region\WindowZone;
use Porta\Overlay\State\WindowControls;
use Porta\Overlay\State\WindowFrame;
use Porta\Ui\FrameHooks;
use Porta\Ui\LtrPane;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\ViewWindow;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The view window on screen: Settings, Git or Review in a framed, movable,
 * resizable window over the editor layout, which stays mounted and dimmed
 * behind it.
 *
 * The view's region is a LIVE widget tree — focus, carets, clickable panes, a
 * draggable separator — so it cannot be drawn off-tree the way the Welcome
 * card list is: a detached widget has no context (so no theme, no focus, no
 * render requests) and no rect (so no hit-test). It stays attached, in a host
 * the frame never lays out ({@see \Porta\Layout\WorkspaceLayout::$windowFrame}),
 * and is rendered here, before each frame, through the tree renderer itself.
 * Between frames the renderer's position stack rests at the screen origin,
 * and the frame container is padded to the window's inner origin, so every
 * widget in the region records its rect at the cells it will be seen at: the
 * views' pointer routers, the separator drag and the select lists hit-test it
 * unchanged. The padding is then cut off and the rows framed and delivered
 * to the window slot, the lowest floating layer — a picker or a menu opened
 * over the window floats above it.
 *
 * It opens at 80% of the workspace, centred, and moves and resizes as every
 * floating window does ({@see WindowFrame}).
 */
#[AsIdeService]
#[AsEager(priority: 390)]
final class ViewWindowPresenter
{
    use StylesDefault;

    /** The share of the workspace a fresh window covers, each way. */
    private const int SHARE_PERCENT = 80;

    /** The smallest window worth drawing a two-pane view in, frame included. */
    private const int MIN_WIDTH = 40;

    private const int MIN_ROWS = 12;

    private readonly WindowFrame $frame;

    private readonly CloseButton $closeButton;

    /** Which window the frame's geometry belongs to ({@see ViewWindow::opened()}). */
    private int $opened = 0;

    private ?ModalBox $box = null;

    /** The box plus its painted shadow bands, for the RTL segmenter. */
    private ?DropdownRegion $shadowRegion = null;

    /** @var array{string, int}|null the view and inner width its sidebar was last fitted to */
    private ?array $fitted = null;

    /** @var array{int, int}|null the inner origin the frame container is padded to */
    private ?array $origin = null;

    /** @var array{rows: list<string>, top: int, col: int, width: int}|null what was last delivered */
    private ?array $delivered = null;

    public function __construct(
        private readonly ViewWindow $window,
        private readonly ViewRegistry $views,
        private readonly OverlayDelivery $delivery,
        private readonly Renderer $renderer,
        private readonly Tui $tui,
        private readonly LayoutController $layout,
        ?Styles $styles = null,
        ?FrameHooks $frameHooks = null,
        ?WindowControls $controls = null,
    ) {
        $this->initStyles($styles);
        $this->frame = new WindowFrame();
        $this->closeButton = $controls->closeButton ?? CloseButton::Standard;
        $frameHooks?->onBeforeFrame($this->compose(...));
    }

    public function isOpen(): bool
    {
        return null !== $this->window->openId();
    }

    /** The box as drawn, or null while no window is. */
    public function box(): ?ModalBox
    {
        return $this->isOpen() ? $this->box : null;
    }

    /**
     * The window as floating regions, for the RTL segmenter: the box with its
     * shadow, then each pane inside it, in paint order. A mirrored frame
     * mirrors every floating region across the row, so the panes land
     * mirrored inside the mirrored box; each reorders its own text within
     * its own cells — mirrored as one run, the box would merge two panes'
     * words across the line between them. A pane that reads left-to-right
     * whatever the locale (code) stays verbatim. Empty while closed.
     *
     * @return list<DropdownRegion>
     */
    public function regions(): array
    {
        $box = $this->isOpen() ? $this->shadowRegion : null;
        if (null === $box) {
            return [];
        }
        $regions = [$box];
        $this->collectPanes($this->window->frame(), $regions);

        return $regions;
    }

    /** @param list<DropdownRegion> $regions */
    private function collectPanes(AbstractWidget $widget, array &$regions): void
    {
        if ($widget instanceof ContainerWidget) {
            foreach ($widget->all() as $child) {
                $this->collectPanes($child, $regions);
            }

            return;
        }
        $rect = $this->renderer->getWidgetRect($widget);
        if (null !== $rect && $rect->columns > 0 && $rect->rows > 0) {
            $regions[] = new DropdownRegion($rect->row, $rect->col, $rect->columns, $rect->rows, ltr: $widget instanceof LtrPane);
        }
    }

    /**
     * A left press while the window is open, answered for the frame: the ✖
     * and a press outside the window — on the workspace behind it — close
     * the view; the title bar and the edges arm a move or a resize. False on
     * the content, which the view's own pointer answers, and on the chrome
     * around the workspace (the status bar), which answers for itself.
     */
    public function press(int $row, int $col): bool
    {
        $box = $this->box();
        if (null === $box) {
            return false;
        }
        $zone = $this->frame->press($box, $row, $col);
        if (WindowZone::Content === $zone || (WindowZone::Outside === $zone && !$this->inWorkspace($row, $col))) {
            return false; // the view's, or the chrome's: the menu and status bars stay live
        }
        if (WindowZone::Close === $zone || WindowZone::Outside === $zone) {
            $this->closeView();
        }
        $this->tui->requestRender();

        return true;
    }

    /** Follow an armed move or resize; false when none is armed. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function dragTo(int $row, int $col, bool $release): bool
    {
        if (!$this->frame->dragTo($row, $col, $release)) {
            return false;
        }
        $this->compose();
        $this->tui->requestRender();

        return true;
    }

    /** A fresh press anywhere disarms a stale drag. */
    public function cancelDrag(): void
    {
        $this->frame->cancel();
    }

    /** Light the handle under the pointer; true when that changed. */
    public function hover(int $row, int $col): bool
    {
        if (!$this->frame->hover($this->box(), $row, $col)) {
            return false;
        }
        $this->compose();
        $this->tui->requestRender();

        return true;
    }

    private function inWorkspace(int $row, int $col): bool
    {
        [$top, $left, $bottom, $right] = $this->delivery->bounds() ?? [0, 0, 0, 0];

        return $row >= $top && $row < $bottom && $col >= $left && $col < $right;
    }

    private function closeView(): void
    {
        $id = $this->window->openId();
        if (null !== $id) {
            $this->views->close($id);
        }
    }

    /**
     * The per-frame funnel: place the window, render the region into it,
     * frame it and deliver it — or clear it once the window has closed.
     * Nothing is re-delivered while nothing changed, so an idle window costs
     * a cached render and a comparison.
     */
    public function compose(): void
    {
        if (!$this->isOpen()) {
            $this->clear();

            return;
        }
        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
            return; // pre-first-frame: the hook runs again on the next one
        }
        if ($this->window->opened() !== $this->opened) {
            $this->opened = $this->window->opened();
            $this->frame->reset();
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $place = $this->frame->place(
            $bounds,
            \intdiv(($boundRight - $boundLeft) * self::SHARE_PERCENT, num2: 100),
            \intdiv(($boundBottom - $boundTop) * self::SHARE_PERCENT, num2: 100),
            WindowPlacement::Centered,
            self::MIN_WIDTH,
            self::MIN_ROWS,
        );
        $innerWidth = $place->width - ModalFrame::CHROME_COLS;
        $innerHeight = $place->rows - ModalFrame::CHROME_ROWS;
        $id = (string) $this->window->openId();
        if ([$id, $innerWidth] !== $this->fitted) {
            // The sidebar's share is of the window, which a resize just changed.
            $this->fitted = [$id, $innerWidth];
            $this->layout->fitViewSidebar($id, $innerWidth);
        }
        // A flush region renders from the padding row down, and the frame's
        // padding row is dropped for it: its sidebar keeps the row as an inset.
        $flush = true === $this->window->region()?->contentFlushTop() ? 1 : 0;
        $content = $this->renderRegion($place->top + ModalFrame::CHROME_ROWS_TOP - $flush, $place->col + 2, $innerWidth, $innerHeight + $flush);
        $hot = $this->frame->hot();
        $title = $this->views->find($id)?->title() ?? '';
        $box = [
            'rows' => [
                ModalFrame::titleBar($title, $place->width, $this->styles, $hot, $this->closeButton),
                ...\array_slice(ModalFrame::wrap($content, $innerWidth, $this->styles, $hot), $flush),
            ],
            'top' => $place->top,
            'col' => $place->col,
            'width' => $place->width,
        ];
        $this->box = new ModalBox($place->top, $place->col, $place->width, $place->rows, headerRows: ModalFrame::CHROME_ROWS_TOP, windowStart: 0, count: $innerHeight, closeButton: $this->closeButton);
        $this->shadowRegion = DropdownRegion::shadowClamped($place->top, $place->col, $place->width, $place->rows, $bounds, shown: $this->delivery->shown());
        if ($box === $this->delivered) {
            return;
        }
        $this->delivered = $box;
        $this->delivery->deliverWindow([$box]);
    }

    /**
     * The region's rows at the window's inner size, rendered by the tree
     * renderer with its frame container padded to the inner origin — so the
     * rects it records are the screen cells the rows land on — and the
     * padding cut off again.
     *
     * @return list<string>
     */
    private function renderRegion(int $originRow, int $originCol, int $innerWidth, int $innerHeight): array
    {
        $container = $this->window->frame();
        if ([$originRow, $originCol] !== $this->origin) {
            $this->origin = [$originRow, $originCol];
            $container->setStyle(new Style(padding: new Padding($originRow, 0, 0, $originCol)));
        }
        $lines = $this->renderer->renderWidgetLines($container, new RenderContext($originCol + $innerWidth, $originRow + $innerHeight, fillRows: true))->toArray();

        return \array_map(static fn (string $line): string => \substr($line, $originCol), \array_slice($lines, $originRow, $innerHeight));
    }

    private function clear(): void
    {
        if (null === $this->delivered) {
            return;
        }
        $this->delivered = null;
        $this->fitted = null;
        $this->box = null;
        $this->shadowRegion = null;
        $this->frame->cancel();
        $this->delivery->clearWindow();
    }
}
