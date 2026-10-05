<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\FloatingLayer;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The presenters' shared delivery half: pane-union bounds with the
 * pre-first-frame retry, the per-pane slice loop for both span slots, and
 * the all-panes clear sweep.
 */
final class OverlayDeliveryTest extends TestCase
{
    use RendersRoot;

    private TestOverlayPane $pane;
    private TestOverlayPane $detachedPane;

    private function delivery(): OverlayDelivery
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $this->pane = $this->newPane();
        $this->detachedPane = $this->newPane(); // never rendered: no rect
        $this->tui->add($this->pane);

        return new OverlayDelivery(
            $this->renderer,
            new MenuOverlayPanes([$this->pane, $this->detachedPane]),
            $this->tui,
        );
    }

    private function newPane(): TestOverlayPane
    {
        return new TestOverlayPane();
    }

    public function testBoundsRequestsAFrameUntilTheFirstRectLands(): void
    {
        $delivery = $this->delivery();
        Probe::set($this->tui, 'renderRequested', false);
        $this->assertNull($delivery->bounds(), 'no frame yet: nothing to lay out against');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'no bounds yet — a frame is requested to try again');

        $this->frame();
        Probe::set($this->tui, 'renderRequested', false);
        $bounds = $delivery->bounds();
        $this->assertNotNull($bounds);
        [$top, $left, $bottom, $right] = $bounds;
        $this->assertSame([0, 0], [$top, $left]);
        $this->assertGreaterThan(0, $bottom * $right);
        $this->assertFalse(Probe::get($this->tui, 'renderRequested'), 'bounds already available — no need to ask for another frame');
    }

    public function testDeliverModalSlicesSpansAndDimsAndSkipsRectlessPanes(): void
    {
        $delivery = $this->delivery();
        $this->frame();

        // Two boxes: proves the per-box spans ACCUMULATE across the loop,
        // rather than the later box overwriting the earlier one's spans.
        $delivery->deliverModal([
            ['rows' => ['ABC', 'DEF'], 'top' => 2, 'col' => 4, 'width' => 3],
            ['rows' => ['XYZ'], 'top' => 10, 'col' => 4, 'width' => 3],
        ]);
        $this->assertNotSame([], $this->pane->modalSpans, 'the box sliced into the rendered pane');
        $rows = \array_column($this->pane->modalSpans, 'row');
        $this->assertContains(2, $rows, 'the first box contributed its spans');
        $this->assertContains(10, $rows, 'the second box contributed its spans too, not replacing the first');
        $this->assertTrue($this->pane->backdrop, 'the modal slot dims');
        $this->assertSame([], $this->detachedPane->modalSpans, 'a pane with no rect gets empty spans');
        $this->assertTrue($this->detachedPane->backdrop, 'but still dims — it may render next frame');

        $delivery->clearModal();
        $this->assertSame([], $this->pane->modalSpans);
        $this->assertFalse($this->pane->backdrop);
        $this->assertFalse($this->detachedPane->backdrop, 'the clear sweep reaches every pane set');
    }

    /** A view window rides its own slot and dim, under the modal's: a picker opened over it keeps both. */
    public function testDeliverWindowUsesItsOwnSlotAndDimUnderTheModals(): void
    {
        $delivery = $this->delivery();
        $this->frame();

        $delivery->deliverModal([['rows' => ['PICK'], 'top' => 3, 'col' => 4, 'width' => 4]]);
        $delivery->deliverWindow([['rows' => ['WIN', 'DOW'], 'top' => 2, 'col' => 4, 'width' => 3]]);
        $this->assertContains(2, \array_column($this->pane->windowSpans, 'row'), 'the window sliced into the pane');
        $this->assertTrue($this->pane->windowDimmed, 'and dims it');
        $this->assertTrue($this->detachedPane->windowDimmed, 'every pane, rect or not');
        $this->assertNotSame([], $this->pane->modalSpans, 'the picker over it kept its own slot');

        $delivery->clearWindow();
        $this->assertSame([], $this->pane->windowSpans);
        $this->assertFalse($this->pane->windowDimmed);
        $this->assertFalse($this->detachedPane->windowDimmed, 'the clear sweep reaches every pane');
        $this->assertNotSame([], $this->pane->modalSpans, 'and leaves the picker alone');
        $this->assertTrue($this->pane->backdrop);
    }

    public function testDeliverToastUsesTheToastSlotAndNeverDims(): void
    {
        $delivery = $this->delivery();
        $this->frame();

        $delivery->deliverToast([['rows' => ['toast'], 'top' => 1, 'col' => 90, 'width' => 5]]);
        $this->assertNotSame([], $this->pane->toastSpans);
        $this->assertSame([], $this->pane->modalSpans, 'the modal slot is untouched');
        $this->assertFalse($this->pane->backdrop, 'toasts are passive');

        $delivery->clearToast();
        $this->assertSame([], $this->pane->toastSpans);
    }

    /** A card flush against its element casts no shadow: the shadow would fall on what it explains. */
    public function testDeliverTooltipUsesItsOwnSlotAndCastsNoShadow(): void
    {
        $delivery = $this->delivery();
        $this->frame();

        $delivery->deliverTooltip([['rows' => ['tip', 'tip'], 'top' => 3, 'col' => 10, 'width' => 3]]);
        $this->assertSame(
            [['row' => 3, 'col' => 10, 'width' => 3, 'segment' => 'tip'], ['row' => 4, 'col' => 10, 'width' => 3, 'segment' => 'tip']],
            $this->pane->tooltipSpans,
            'the card rows alone, no shadow band',
        );
        $this->assertSame([], $this->pane->toastSpans, 'the toast slot is untouched');
        $this->assertFalse($this->pane->backdrop, 'a tooltip is passive');
        $this->assertSame([], $this->detachedPane->tooltipSpans, 'a pane with no rect gets empty spans');

        $delivery->clearTooltip();
        $this->assertSame([], $this->pane->tooltipSpans);
    }

    /**
     * Mirrored, the shadow is cast to the left before the flip, so the region
     * grows to the left; and on the band's row it takes the band alone, not
     * the cell under the box's far corner, which the flip would fill with a
     * pane glyph from across the row.
     */
    public function testAMirroredShadowRegionGrowsLeftAndItsBandRowIsTheBand(): void
    {
        $bounds = [0, 0, 24, 100];

        $region = DropdownRegion::shadowClamped(2, 4, 10, 5, $bounds, shown: BaseDirection::Rtl);
        $this->assertSame([2, 3, 11, 6], [$region->top, $region->col, $region->width, $region->count]);
        $this->assertSame([3, 10], $region->columnsOn(7), 'the band: from the side column, the box wide');
        $this->assertSame([3, 11], $region->columnsOn(6), 'a box row: the box and its side column');
        $this->assertSame([4, 10], $region->columnsOn(2), 'the top row: the box alone, no shadow beside it');
        $this->assertFalse($region->contains(2, 3));
        $this->assertTrue($region->contains(7, 12));
        $this->assertFalse($region->contains(7, 13), 'the far corner is not the band');
        $this->assertTrue($region->contains(6, 13));

        $edge = DropdownRegion::shadowClamped(2, 0, 10, 5, $bounds, shown: BaseDirection::Rtl);
        $this->assertSame([0, 10], [$edge->col, $edge->width], 'flush against the left edge: no side column to claim');
        $this->assertSame([0, 9], $edge->columnsOn(7));

        $this->assertSame([0, 10], $edge->columnsOn(2), 'no side column, so the top row is the box as it is');
        $this->assertNull($edge->firstRow);

        $flush = DropdownRegion::shadowClamped(19, 4, 10, 5, $bounds, shown: BaseDirection::Rtl);
        $this->assertSame(5, $flush->count, 'flush against the bottom: no band');
        $this->assertSame([3, 11], $flush->columnsOn(23), 'and so no row of its own');
    }

    /** A box reaches the screen mirrored under a right-to-left locale, unless it is verbatim. */
    public function testASurfaceIsShownMirroredUnderARightToLeftLocaleUnlessVerbatim(): void
    {
        $this->assertSame(BaseDirection::Ltr, $this->delivery()->shown());

        $mirrored = new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui, translator: new Translator('he'));
        $this->assertSame(BaseDirection::Rtl, $mirrored->shown());
        $this->assertSame(BaseDirection::Ltr, $mirrored->shown(verbatim: true));
    }

    /**
     * Mirrored, the boxes ride the floating layer and the panes keep only the
     * backdrop: a box composited into a pane that is then mirrored away from
     * it leaves a hole where it logically sat. Each box casts by how it is
     * shown, a verbatim one as it would left-to-right.
     */
    public function testAMirroredDeliveryRidesTheLayerAndCastsByHowEachBoxIsShown(): void
    {
        $this->delivery();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $layer = new FloatingLayer();
        $delivery = new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui, translator: new Translator('he'), layer: $layer);
        $box = ['rows' => ['abc', 'def', 'ghi'], 'top' => 2, 'col' => 10, 'width' => 3];
        // The cell's own background: the last SGR before it sets one (a plain
        // cell after a shadow re-emits the shadow's SGR, then resets it).
        $shadowAt = static function (string $row, int $col): bool {
            \preg_match_all('/\e\[([0-9;]*)m/', AnsiUtils::sliceByColumn($row, $col, length: 1), $sgr);

            return \str_starts_with((string) \array_last($sgr[1]), '48;');
        };

        $delivery->deliverModal([$box]);
        $this->assertSame([], $this->pane->modalSpans, 'the pane carries none of it');
        $this->assertTrue($this->pane->backdrop, 'but still dims beneath it');
        $canvas = $layer->rows(20, 6);
        $this->assertSame('abc', AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($canvas[2], 10, length: 3)));
        $this->assertTrue($shadowAt($canvas[3], col: 9), 'mirrored: the side band left of the box');
        $this->assertFalse($shadowAt($canvas[3], col: 13));

        $delivery->deliverModal([[...$box, 'ltr' => true]]);
        $canvas = $layer->rows(20, 6);
        $this->assertTrue($shadowAt($canvas[3], col: 13), 'verbatim: the side band right of it');
        $this->assertFalse($shadowAt($canvas[3], col: 9));

        $delivery->clearModal();
        $this->assertSame(\array_fill(0, count: 6, value: \str_repeat(' ', times: 20)), $layer->rows(20, 6), 'cleared off the layer too');
    }

    /**
     * A pane invalidates itself when its spans change, which is what brings
     * the next frame. The layer is no widget, so its caller asks for one, and
     * only when the slot actually changed: asking every frame would never let
     * the screen settle.
     */
    public function testAChangedLayerAsksForAFrameAndAnUnchangedOneDoesNot(): void
    {
        $this->delivery();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $delivery = new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui, translator: new Translator('he'), layer: new FloatingLayer());
        $box = ['rows' => ['toast'], 'top' => 1, 'col' => 2, 'width' => 5];

        $delivery->deliverToast([$box]);
        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'), 'a new toast on the layer');

        $this->tui->processRender();
        $delivery->deliverToast([$box]);
        $this->assertFalse((bool) Probe::get($this->tui, 'renderRequested'), 'the same toast again');
    }

    /** Each slot goes to the layer when mirrored, and to the panes otherwise. */
    public function testEverySlotGoesToTheLayerOnlyWhenMirrored(): void
    {
        $this->delivery();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $box = ['rows' => ['toast'], 'top' => 1, 'col' => 2, 'width' => 5];
        foreach (['en' => false, 'he' => true] as $locale => $mirrored) {
            $layer = new FloatingLayer();
            $delivery = new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui, translator: new Translator($locale), layer: $layer);

            $delivery->deliverToast([$box]);
            $this->assertSame($mirrored, [] === $this->pane->toastSpans, "{$locale}: the toast's pane spans");
            $this->assertSame($mirrored, \str_contains($layer->rows(12, 3)[1], 'toast'), "{$locale}: the toast on the layer");
            $delivery->clearToast();
            $delivery->deliverTooltip([$box]);
            $this->assertSame($mirrored, [] === $this->pane->tooltipSpans, "{$locale}: the tooltip's pane spans");
            $canvas = $layer->rows(12, 3);
            $this->assertSame($mirrored, \str_contains($canvas[1], 'toast'), "{$locale}: the tooltip on the layer");
            $this->assertStringNotContainsString('48;', $canvas[2], "{$locale}: casting no shadow");
            $delivery->clearToast();
            $delivery->clearTooltip();
            $this->assertStringNotContainsString('toast', \implode('', $layer->rows(12, 3)), "{$locale}: both cleared");
            $delivery->deliverWindow([$box]);
            $this->assertSame($mirrored, [] === $this->pane->windowSpans, "{$locale}: the window's pane spans");
            $this->assertSame($mirrored, \str_contains($layer->rows(12, 3)[1], 'toast'), "{$locale}: the window on the layer");
            $delivery->clearWindow();
            $this->assertStringNotContainsString('toast', \implode('', $layer->rows(12, 3)), "{$locale}: the window cleared");
        }
    }

    public function testShadowClampedGrowsByTheShadowBandsOnlyWhereTheyPaint(): void
    {
        $bounds = [0, 0, 24, 100];

        $inner = DropdownRegion::shadowClamped(2, 4, 10, 5, $bounds, ltr: true);
        $this->assertSame([2, 4, 11, 6], [$inner->top, $inner->col, $inner->width, $inner->count], 'one shadow column and band added');
        $this->assertSame([4, 10], $inner->columnsOn(2), 'the top row casts no side shadow');
        $this->assertSame([5, 10], $inner->columnsOn(7), 'the band, one column in');
        $this->assertSame([4, 11], $inner->columnsOn(4));
        $this->assertTrue($inner->ltr);

        $flush = DropdownRegion::shadowClamped(19, 90, 10, 5, $bounds);
        $this->assertSame(10, $flush->width, 'flush against the right edge: no shadow column to claim');
        $this->assertNull($flush->firstRow, 'nor a first row of its own');
        $this->assertNull($flush->lastRow);
        $this->assertSame(5, $flush->count, 'flush against the bottom: no shadow band');
        $this->assertFalse($flush->ltr);
    }
}
