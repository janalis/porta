<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\TooltipPresenter;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Overlay\State\TooltipCenter;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Ui\Tooltip;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The tooltip presenter: the card laid out beside its element against the
 * pane union each frame, through the tooltip slot, and cleared exactly once.
 */
final class TooltipPresenterTest extends TestCase
{
    use RendersRoot;

    private TooltipCenter $tooltips;
    private TestOverlayPane $pane;

    private function presenter(): TooltipPresenter
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $this->tooltips = new TooltipCenter(new FakeScheduler(), $this->tui);
        $this->tooltips->setDelayMs(0);

        return new TooltipPresenter(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui),
            $this->tooltips,
            frameHooks: $this->tui,
        );
    }

    /** @return list<string> the card's painted rows, plain, top to bottom */
    private function painted(): array
    {
        return \array_map(static fn (array $span): string => AnsiUtils::stripAnsiCodes($span['segment']), $this->pane->tooltipSpans);
    }

    public function testTheCardHangsFlushUnderItsElement(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->tooltips->point(new Tooltip('k', 'Close', 4, 40, 41));

        $presenter->sync();

        $this->assertSame(['╭───────╮', '│ Close │', '╰───────╯'], $this->painted());
        $this->assertSame([5, 6, 7], \array_column($this->pane->tooltipSpans, 'row'), 'flush under the element');
        $this->assertSame(36, $this->pane->tooltipSpans[0]['col'], 'centred on column 40: four cells each side');
        [$card] = $presenter->regions();
        $this->assertSame([5, 36, 9, 3], [$card->top, $card->col, $card->width, $card->count]);
        $this->assertFalse($card->ltr, 'a tooltip is prose: it mirrors with the locale');
    }

    public function testNearTheBottomTheCardFlipsAbove(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->tooltips->point(new Tooltip('k', 'Close', 22, 40, 41));

        $presenter->sync();

        $this->assertSame(19, $this->pane->tooltipSpans[0]['row'], 'flush over the element');
    }

    /** The union is laid out afresh each frame, so a resize re-places the card. */
    public function testTheCardIsPlacedAgainstTheCurrentBounds(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->tooltips->point(new Tooltip('k', 'Close', 14, 40, 41));
        $presenter->sync();
        $this->assertSame(15, $this->pane->tooltipSpans[0]['row'], 'room below at 24 rows');

        $this->frame(rows: 16);
        $presenter->sync();
        $this->assertSame(11, $this->pane->tooltipSpans[0]['row'], 'none at 16: it flips above');
    }

    public function testTheTextNarrowsWithTheUnion(): void
    {
        $presenter = $this->presenter();
        $this->frame(columns: 20);
        $this->tooltips->point(new Tooltip('k', 'a rather long explanation of a glyph', 4, 5, 6));

        $presenter->sync();

        foreach ($this->pane->tooltipSpans as $span) {
            $this->assertLessThanOrEqual(20, $span['col'] + $span['width']);
        }
        $this->assertGreaterThan(3, \count($this->painted()), 'wrapped rather than cut');
    }

    public function testSyncBeforeAnyFramePushesNothing(): void
    {
        $presenter = $this->presenter();
        $this->tooltips->point(new Tooltip('k', 'Close', 4, 40, 41));

        $presenter->sync();

        $this->assertSame([], $this->pane->tooltipSpans);
        $this->assertSame([], $presenter->regions());
    }

    public function testSyncClearsExactlyOnceAfterTheCardGoes(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->tooltips->point(new Tooltip('k', 'Close', 4, 40, 41));
        $presenter->sync();

        $this->tooltips->dismiss();
        $presenter->sync();
        $this->assertSame([], $this->pane->tooltipSpans);
        $this->assertSame([], $presenter->regions());

        $this->pane->tooltipSpans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'W']];
        $presenter->sync();
        $this->assertNotSame([], $this->pane->tooltipSpans, 'a quiet presenter leaves the slot alone');
    }

    public function testTheFrameHookDrivesIt(): void
    {
        $this->presenter();
        $this->frame();
        $this->tooltips->point(new Tooltip('k', 'Close', 4, 40, 41));

        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        $this->assertNotSame([], $this->pane->tooltipSpans, 'painted from the before-frame hook');
    }
}
