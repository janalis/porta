<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Widget\Render\MenuOverlayHost;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The presenters' shared delivery half: pane-union bounds with the
 * pre-first-frame retry, the per-pane slice loop for both span slots, and
 * the all-panes clear sweep.
 */
final class OverlayDeliveryTest extends TestCase
{
    use RendersRoot;

    private AbstractWidget&MenuOverlayHost $pane;
    private AbstractWidget&MenuOverlayHost $detachedPane;

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
            new MenuOverlayPanes([$this->pane, $this->detachedPane], static fn (): array => []),
            $this->tui,
        );
    }

    private function newPane(): AbstractWidget&MenuOverlayHost
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
        $this->assertTrue($region->contains(7, 12));
        $this->assertFalse($region->contains(7, 13), 'the far corner is not the band');
        $this->assertTrue($region->contains(6, 13));

        $edge = DropdownRegion::shadowClamped(2, 0, 10, 5, $bounds, shown: BaseDirection::Rtl);
        $this->assertSame([0, 10], [$edge->col, $edge->width], 'flush against the left edge: no side column to claim');
        $this->assertSame([0, 9], $edge->columnsOn(7));

        $flush = DropdownRegion::shadowClamped(19, 4, 10, 5, $bounds, shown: BaseDirection::Rtl);
        $this->assertSame(5, $flush->count, 'flush against the bottom: no band');
        $this->assertSame([3, 11], $flush->columnsOn(23), 'and so no row of its own');
    }

    /** A box reaches the screen mirrored under a right-to-left locale, unless it is verbatim. */
    public function testASurfaceIsShownMirroredUnderARightToLeftLocaleUnlessVerbatim(): void
    {
        $this->assertSame(BaseDirection::Ltr, $this->delivery()->shown());

        $mirrored = new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui, translator: new Translator('he'));
        $this->assertSame(BaseDirection::Rtl, $mirrored->shown());
        $this->assertSame(BaseDirection::Ltr, $mirrored->shown(verbatim: true));
    }

    /** Each box casts by how it is shown: a verbatim one as it would left-to-right. */
    public function testEachBoxCastsItsShadowByHowItIsShown(): void
    {
        $this->delivery();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $delivery = new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui, translator: new Translator('he'));
        $box = ['rows' => ['abc', 'def', 'ghi'], 'top' => 2, 'col' => 10, 'width' => 3];

        $delivery->deliverModal([$box]);
        $this->assertContains(9, \array_column($this->pane->modalSpans, 'col'), 'mirrored: the side band left of the box');

        $delivery->deliverModal([[...$box, 'ltr' => true]]);
        $this->assertContains(13, \array_column($this->pane->modalSpans, 'col'), 'verbatim: the side band right of it');
        $this->assertNotContains(9, \array_column($this->pane->modalSpans, 'col'));
    }

    public function testShadowClampedGrowsByTheShadowBandsOnlyWhereTheyPaint(): void
    {
        $bounds = [0, 0, 24, 100];

        $inner = DropdownRegion::shadowClamped(2, 4, 10, 5, $bounds, ltr: true);
        $this->assertSame([2, 4, 11, 6], [$inner->top, $inner->col, $inner->width, $inner->count], 'one shadow column and band added');
        $this->assertTrue($inner->ltr);

        $flush = DropdownRegion::shadowClamped(19, 90, 10, 5, $bounds);
        $this->assertSame(10, $flush->width, 'flush against the right edge: no shadow column to claim');
        $this->assertSame(5, $flush->count, 'flush against the bottom: no shadow band');
        $this->assertFalse($flush->ltr);
    }
}
