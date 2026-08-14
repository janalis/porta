<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayPanes;
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
        $this->assertNull($delivery->bounds(), 'no frame yet: nothing to lay out against');

        $this->frame();
        $bounds = $delivery->bounds();
        $this->assertNotNull($bounds);
        [$top, $left, $bottom, $right] = $bounds;
        $this->assertSame([0, 0], [$top, $left]);
        $this->assertGreaterThan(0, $bottom * $right);
    }

    public function testDeliverModalSlicesSpansAndDimsAndSkipsRectlessPanes(): void
    {
        $delivery = $this->delivery();
        $this->frame();

        $delivery->deliverModal([['rows' => ['ABC', 'DEF'], 'top' => 2, 'col' => 4, 'width' => 3]]);
        $this->assertNotSame([], $this->pane->modalSpans, 'the box sliced into the rendered pane');
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
