<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\ToastPresenter;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Tests\Support\FakeScheduler;
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
 * The toast presenter: corner placement over the pane union, the toast span
 * slot (never the modal slot, never the backdrop), the boxes handed back for
 * hit-testing, and the clear-exactly-once discipline.
 */
final class ToastPresenterTest extends TestCase
{
    use RendersRoot;

    private ToastCenter $toasts;
    private FakeScheduler $scheduler;
    private AbstractWidget&MenuOverlayHost $pane;

    private function presenter(): ToastPresenter
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $this->scheduler = new FakeScheduler();
        $this->toasts = new ToastCenter($this->scheduler, $this->tui);

        return new ToastPresenter(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui),
            $this->toasts,
            frameHooks: $this->tui,
        );
    }

    public function testSyncPushesCornerSpansAndRecordsBoxesWithoutDimming(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('saved', ToastSeverity::Success);

        $presenter->sync();

        $this->assertNotSame([], $this->pane->toastSpans, 'the card was pushed onto the pane');
        $this->assertFalse($this->pane->backdrop, 'toasts never dim the workspace');
        $this->assertCount(1, $presenter->regions());
        $this->assertTrue($this->toasts->clickAt($presenter->regions()[0]->top + 1, $presenter->regions()[0]->col + 2), 'the recorded box hit-tests');

        $rightmost = max(array_map(static fn (array $s): int => $s['col'] + $s['width'], $this->pane->toastSpans));
        $this->assertGreaterThan(60, $rightmost, 'the stack hangs off the top-right corner');
    }

    public function testTwoToastsStackDownwardNewestFirst(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('older', ToastSeverity::Info);
        $this->toasts->push('newer', ToastSeverity::Error);

        $presenter->sync();

        $regions = $presenter->regions();
        $this->assertCount(2, $regions);
        $this->assertLessThan($regions[1]->top, $regions[0]->top, 'the newest card sits on top');
    }

    public function testSyncBeforeAnyFrameRetriesWithoutPushing(): void
    {
        $presenter = $this->presenter();
        $this->toasts->push('early', ToastSeverity::Info);

        $presenter->sync();

        $this->assertSame([], $this->pane->toastSpans, 'nothing to lay out against yet');
        $this->assertSame([], $presenter->regions());
    }

    public function testSyncClearsExactlyOnceAfterTheLastToastExpires(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('bye', ToastSeverity::Info);
        $presenter->sync();

        $this->scheduler->fire(); // expiry
        $presenter->sync();
        $this->assertSame([], $this->pane->toastSpans, 'expiry cleared the slot');
        $this->assertSame([], $presenter->regions());
        $this->assertFalse($this->toasts->hoverAt(1, 63), 'stale hit-test boxes were cleared along with the spans');

        // A later sync must NOT touch the pane again.
        $this->pane->toastSpans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'W']];
        $presenter->sync();
        $this->assertNotSame([], $this->pane->toastSpans, 'a quiet presenter leaves the slot alone');
    }

    public function testSyncPlacesTheCardAtTheExactCornerGeometry(): void
    {
        // 100x24 union → boundTop=0, boundLeft=0, boundBottom=23, boundRight=99.
        // Pinning the exact numbers catches any sign/operand drift in the
        // corner-placement arithmetic (inner/width/col/top), not just "some
        // card showed up somewhere on the right".
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('hi', ToastSeverity::Info);

        $presenter->sync();

        $region = $presenter->regions()[0];
        $this->assertSame(1, $region->top, 'boundTop + 1');
        $this->assertSame(63, $region->col);
        $this->assertSame(36, $region->width);
        $this->assertStringContainsString('hi', implode('', array_column($this->pane->toastSpans, 'segment')), 'the card carries its real message, not a malformed row entry');
    }

    public function testSyncRecordsTheExactBoxWindowShapeForHitTesting(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('hi', ToastSeverity::Info);

        $presenter->sync();

        $boxes = Probe::get($this->toasts, 'boxes');
        $box = $boxes[0]['box'];
        $this->assertSame(1, $box->headerRows, 'one chrome row above the item window');
        $this->assertSame(0, $box->windowStart);
        $this->assertSame($box->rows - 2, $box->count, 'the window excludes the top and bottom border rows');
    }

    public function testHoverStylingIsScopedToTheHoveredCardOnly(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('older', ToastSeverity::Info);
        $this->toasts->push('newer', ToastSeverity::Info);
        $presenter->sync();

        $before = $this->pane->toastSpans;

        // At baseline (nothing ever hovered), every card must show the
        // inactive close glyph — not just "whichever card isn't the one we're
        // about to hover", which a flipped `===`/`!==` comparison against a
        // null closeHoveredId can't distinguish from the correct baseline.
        $beforeTitles = implode('', array_column($before, 'segment'));
        $this->assertStringNotContainsString("\x1b[38;5;252m×", $beforeTitles, 'no card starts out showing the active (hovered) close glyph');

        // Boxes carry the real (non-shadow-inclusive) width the closeHit
        // geometry is defined against — regions() is 1 wider (shadow column).
        $boxes = Probe::get($this->toasts, 'boxes');
        $newestBox = $boxes[0]['box'];
        $oldestBox = $boxes[1]['box'];
        $this->assertTrue(
            $this->toasts->hoverAt($newestBox->startRow, $newestBox->col + $newestBox->width - 2),
            'the hover lands on the newest card',
        );
        $presenter->sync();

        $after = $this->pane->toastSpans;
        $this->assertNotEquals($before, $after, 'hovering the newest card changes its rendered title row');

        // The older card's own title row (its top-border span) is byte-identical —
        // the hover styling did not leak onto a card that isn't hovered.
        $oldestTitleBefore = array_values(array_filter($before, static fn (array $s): bool => $s['row'] === $oldestBox->startRow && $s['col'] === $oldestBox->col));
        $oldestTitleAfter = array_values(array_filter($after, static fn (array $s): bool => $s['row'] === $oldestBox->startRow && $s['col'] === $oldestBox->col));
        $this->assertNotEmpty($oldestTitleBefore);
        $this->assertSame($oldestTitleBefore, $oldestTitleAfter);
    }

    public function testATallEarlierCardThatDoesNotFitStopsTheOlderTailInstead(): void
    {
        // A smaller union (12 rows) so a too-tall card can run out of room
        // without needing to fill all 5 of ToastCenter's MAX_TOASTS slots.
        // toasts() iterates newest→oldest. Pushed oldest to newest: an early
        // short toast, a too-tall one, then one filler. Processing renders the
        // filler, then hits the tall one and runs out of room — the
        // even-older short toast behind it must NOT render just because it
        // would itself fit if reached; proves `break` (not `continue`) on the
        // out-of-room guard.
        $presenter = $this->presenter();
        $this->frame(rows: 12);
        $this->toasts->push('early-short', ToastSeverity::Info); // oldest: must never render
        $this->toasts->push(str_repeat('word ', times: 60), ToastSeverity::Info); // too tall to fit once reached
        $this->toasts->push('filler', ToastSeverity::Info); // newest: consumes the room first

        $presenter->sync();

        $this->assertCount(1, $presenter->regions(), 'only the newest filler fits; the tail (tall + early-short) stays queued');
    }

    public function testConstructedWithoutFrameHooksNeverAutoSyncs(): void
    {
        // frameHooks is nullable so a presenter can be built without wiring —
        // must not crash, and must never call sync() on its own.
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $scheduler = new FakeScheduler();
        $toasts = new ToastCenter($scheduler, $this->tui);
        new ToastPresenter(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui),
            $toasts,
            frameHooks: null,
        );

        $this->frame();
        $toasts->push('hi', ToastSeverity::Info);
        $this->tui->processRender();

        $this->assertSame([], $this->pane->toastSpans, 'no frame-hook wiring — nothing auto-syncs');
    }

    public function testFrameHookWiringAutoSyncsOnTheNextFrame(): void
    {
        // Every other test drives sync() by hand; this one proves the
        // constructor's onBeforeFrame registration is what makes that
        // automatic in the real app.
        $presenter = $this->presenter();
        $this->frame();
        $this->toasts->push('hi', ToastSeverity::Info);

        $this->tui->processRender(); // not $presenter->sync() — the frame hook must do it

        $this->assertNotSame([], $this->pane->toastSpans, 'the frame hook drove sync() automatically');
    }

    public function testAStackTallerThanTheUnionStopsInsteadOfClipping(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        foreach (range(1, end: 5) as $i) {
            $this->toasts->push(str_repeat("very long message {$i} ", times: 12), ToastSeverity::Info);
        }

        $presenter->sync();

        $this->assertLessThan(5, \count($presenter->regions()), 'the older tail waits instead of clipping past the union');
        foreach ($presenter->regions() as $region) {
            $this->assertLessThanOrEqual(24, $region->top + $region->count);
        }
    }
}
