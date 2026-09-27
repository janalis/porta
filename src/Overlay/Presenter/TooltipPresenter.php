<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\TooltipPlacement;
use Porta\Overlay\State\TooltipCenter;
use Porta\Ui\FrameHooks;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\PopupFrame;
use Porta\Widget\Render\TooltipCard;
use Porta\Widget\Support\StylesDefault;

/**
 * Presents the tooltip card flush beside the element it explains: laid out each
 * frame against the CURRENT pane union — so a resize re-places it, flipping
 * sides where the old one no longer has room — and delivered through the
 * panes' tooltip span slot. Passive like the toasts: no dim, no focus, no
 * interlock with the views.
 *
 * Resolved eagerly by {@see \Porta\Ide}: its frame hook is what paints the
 * card, and it must be installed at boot whoever else asks for it later.
 */
#[AsEager(priority: 50)]
final class TooltipPresenter
{
    use StylesDefault;

    /** Whether this presenter's spans are currently painted (its clear-once guard). */
    private bool $shown = false;

    /** @var list<DropdownRegion> */
    private array $regions = [];

    public function __construct(
        private readonly OverlayDelivery $delivery,
        private readonly TooltipCenter $tooltips,
        ?Styles $styles = null,
        ?FrameHooks $frameHooks = null,
    ) {
        $this->initStyles($styles);
        $frameHooks?->onBeforeFrame($this->sync(...));
    }

    /**
     * The painted card for the RTL segmenter — its words mirror with the
     * locale, so the region stays bidi, its own run.
     *
     * @return list<DropdownRegion>
     */
    public function regions(): array
    {
        return $this->regions;
    }

    /**
     * The per-frame delivery: lay the card out against the pane union and
     * push its spans; clear exactly once after it goes.
     */
    public function sync(): void
    {
        $tip = $this->tooltips->shown();
        if (null === $tip) {
            if ($this->shown) {
                $this->shown = false;
                $this->regions = [];
                $this->delivery->clearTooltip();
            }

            return;
        }

        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
            return;
        }
        [, $boundLeft, , $boundRight] = $bounds;

        // The text narrows with the terminal, down to what still frames: a
        // card wider than the union would be cut by it.
        $lines = TooltipCard::lines($tip->text, \min(TooltipCard::MAX_TEXT, $boundRight - $boundLeft - 2 - PopupFrame::CHROME_COLS));
        $width = TooltipCard::width($lines);
        $height = \count($lines) + PopupFrame::CHROME_ROWS;
        $place = TooltipPlacement::place($tip, $width, $height, $bounds);

        $this->delivery->deliverTooltip([['rows' => TooltipCard::render($lines, $this->styles), 'top' => $place->top, 'col' => $place->col, 'width' => $width]]);
        $this->shown = true;
        $this->regions = [new DropdownRegion($place->top, $place->col, $width, $height)];
    }
}
