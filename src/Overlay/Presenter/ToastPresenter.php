<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\I18n\Translator;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Ui\FrameHooks;
use Porta\Ui\KeyHints;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\PopupFrame;
use Porta\Widget\Render\PopupMetrics;
use Porta\Widget\Render\ToastCard;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Presents the toast stack over the workspace's top-right corner: cards
 * stacked newest-first, each a {@see ToastCard} plus its {@see ModalShadow},
 * delivered per frame through the panes' toast span slot — a third overlay
 * layer that composites above the modal spans (readable over a dimmed modal)
 * and below the menu dropdowns. Deliberately passive: no backdrop dim, no
 * {@see \Porta\Workbench\View\ViewRegistry} interlock, no focus change — toasts coexist
 * with whatever modal is open and never trap input.
 *
 * Resolved eagerly by {@see \Porta\Ide} — nothing else references it, and
 * without the eager resolve toasts would never paint.
 */
#[AsEager(priority: 100)]
final class ToastPresenter
{
    use StylesDefault;

    /** Whether this presenter's spans are currently painted (its clear-once guard). */
    private bool $shown = false;

    /** @var list<DropdownRegion> */
    private array $regions = [];

    public function __construct(
        private readonly OverlayDelivery $delivery,
        private readonly ToastCenter $toasts,
        ?Styles $styles = null,
        ?FrameHooks $frameHooks = null,
        // The notification log's live key and the words around it, for the
        // hints an error card and a crowded stack carry in their borders.
        private readonly Keybindings $ideKeys = new Keybindings([]),
        private readonly Translator $translator = new Translator('en'),
    ) {
        $this->initStyles($styles);
        $frameHooks?->onBeforeFrame($this->sync(...));
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
                $this->delivery->clearToast();
            }

            return;
        }

        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
            return;
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;

        // Cards cap at a third of the union: on a narrow terminal a flat-width
        // card would blanket half the workspace, burying the content the
        // notification is ABOUT.
        $inner = \min(PopupMetrics::TOAST_MAX_INNER, \intdiv($boundRight - $boundLeft, num2: 3));
        $width = $inner + PopupFrame::CHROME_COLS;
        $col = \max($boundLeft, $boundRight - $width - 2); // 1 margin + 1 shadow column
        $top = $boundTop + 1;

        $cards = [];
        $boxes = [];
        $closeHovered = $this->toasts->closeHoveredId();
        $all = $this->toasts->toasts();
        $logKey = KeyHints::key('ide_notifications', $this->ideKeys);
        foreach ($all as $n => $toast) {
            // An error points at the log that keeps it; the last card that
            // fits says how many wait behind it, and where to read them.
            $hint = ToastSeverity::Error === $toast->severity && '' !== $logKey ? $this->translator->trans('%key% log', ['%key%' => $logKey]) : '';
            $rows = ToastCard::render($toast, $inner, $this->styles, $closeHovered === $toast->id, $hint);
            if (($top + \count($rows) + 1) > $boundBottom) {
                break; // out of vertical room: the older tail stays queued, not clipped
            }
            $more = \count($all) - $n - 1;
            $next = $all[$n + 1] ?? null;
            if (null !== $next && ($top + \count($rows) + 1 + \count(ToastCard::render($next, $inner, $this->styles)) + 1) > $boundBottom) {
                $rows = ToastCard::render(
                    $toast,
                    $inner,
                    $this->styles,
                    $closeHovered === $toast->id,
                    $this->translator->trans('+%count% more', ['%count%' => $more]).('' === $logKey ? '' : ' · '.$logKey),
                );
            }
            $cards[] = ['rows' => $rows, 'top' => $top, 'id' => $toast->id];
            $boxes[] = ['box' => new ModalBox($top, $col, $width, \count($rows), headerRows: 1, windowStart: 0, count: \count($rows) - 2), 'id' => $toast->id];
            $top += \count($rows) + 1; // the gap row doubles as the shadow band
        }

        $this->delivery->deliverToast(\array_map(
            static fn (array $card): array => ['rows' => $card['rows'], 'top' => $card['top'], 'col' => $col, 'width' => $width],
            $cards,
        ));
        $this->shown = true;
        $this->toasts->setBoxes($boxes);
        $this->regions = \array_map(
            static fn (array $card): DropdownRegion => DropdownRegion::shadowClamped($card['top'], $col, $width, \count($card['rows']), $bounds),
            $cards,
        );
    }
}
