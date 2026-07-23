<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Overlay\MenuOverlayPanes;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\PaneBounds;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Ui\BidiTui;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Toast\ToastCenter;
use Porta\Widget\Render\ModalShadow;
use Porta\Widget\Render\PopupFrame;
use Porta\Widget\Render\PopupMetrics;
use Porta\Widget\Render\ToastCard;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Presents the toast stack over the workspace's top-right corner: cards
 * stacked newest-first, each a {@see ToastCard} plus its {@see ModalShadow},
 * delivered per frame through the panes' toast span slot — a third overlay
 * layer that composites above the modal spans (readable over a dimmed modal)
 * and below the menu dropdowns. Deliberately passive: no backdrop dim, no
 * {@see \Porta\Ui\WorkspaceViews} interlock, no focus change — toasts coexist
 * with whatever modal is open and never trap input.
 *
 * Resolved eagerly by {@see \Porta\Ide} — nothing else references it, and
 * without the eager resolve toasts would never paint.
 */
final class ToastPresenter
{
    /** Whether this presenter's spans are currently painted (its clear-once guard). */
    private bool $shown = false;

    /** @var list<DropdownRegion> */
    private array $regions = [];

    private readonly Styles $styles;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuOverlayPanes $panes,
        private readonly ToastCenter $toasts,
        private readonly Tui $tui,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        if ($this->tui instanceof BidiTui) {
            $this->tui->onBeforeFrame($this->sync(...));
        }
    }

    /**
     * The painted cards (shadow bands included) for the RTL segmenter —
     * message prose mirrors with the locale, so the regions stay bidi
     * (ltr: false), each its own run.
     *
     * @return list<DropdownRegion>
     */
    public function regions(): array
    {
        return $this->regions;
    }

    /**
     * The per-frame delivery: lay the stack out against the pane union's
     * top-right corner, push spans + shadows per pane, and record the
     * screen-space boxes for {@see ToastCenter}'s hit-tests; clear exactly
     * once after the last toast expires.
     */
    public function sync(): void
    {
        if ($this->toasts->isEmpty()) {
            if ($this->shown) {
                $this->shown = false;
                $this->regions = [];
                $this->toasts->setBoxes([]);
                foreach ($this->panes->all() as $pane) {
                    $pane->setToastOverlay([]);
                }
            }

            return;
        }

        $bounds = PaneBounds::union($this->panes->current(), $this->renderer);
        if (null === $bounds) {
            // No rects yet: keep asking for frames until the first one lands.
            $this->tui->requestRender();

            return;
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;

        // Cards cap at a third of the union: on a narrow terminal a flat-width
        // card would blanket half the workspace, burying the content the
        // notification is ABOUT.
        $inner = min(PopupMetrics::TOAST_MAX_INNER, intdiv($boundRight - $boundLeft, num2: 3));
        $width = $inner + PopupFrame::CHROME_COLS;
        $col = max($boundLeft, $boundRight - $width - 2); // 1 margin + 1 shadow column
        $top = $boundTop + 1;

        $cards = [];
        $boxes = [];
        $closeHovered = $this->toasts->closeHoveredId();
        foreach ($this->toasts->toasts() as $toast) {
            $rows = ToastCard::render($toast, $inner, $this->styles, $closeHovered === $toast->id);
            if (($top + \count($rows) + 1) > $boundBottom) {
                break; // out of vertical room: the older tail stays queued, not clipped
            }
            $cards[] = ['rows' => $rows, 'top' => $top, 'id' => $toast->id];
            $boxes[] = ['box' => new ModalBox($top, $col, $width, \count($rows), headerRows: 1, windowStart: 0, count: \count($rows) - 2), 'id' => $toast->id];
            $top += \count($rows) + 1; // the gap row doubles as the shadow band
        }

        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            $spans = [];
            if (null !== $rect) {
                foreach ($cards as $card) {
                    $spans = [
                        ...$spans,
                        ...SpanSlicer::localSpans($card['rows'], $rect, $card['top'], $col, $width),
                        ...ModalShadow::spans($rect, $card['top'], $col, $width, \count($card['rows']), $this->styles),
                    ];
                }
            }
            $pane->setToastOverlay($spans);
        }
        $this->shown = true;
        $this->toasts->setBoxes($boxes);
        // Cover only the shadow bands that actually paint, so the RTL
        // segmenter never claims cells outside a card.
        $this->regions = array_map(
            fn (array $card): DropdownRegion => new DropdownRegion(
                $card['top'],
                $col,
                min($width + 1, $boundRight - $col),
                min(\count($card['rows']) + 1, $boundBottom - $card['top']),
            ),
            $cards,
        );
    }
}
