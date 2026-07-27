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
use Porta\Ui\BidiTui;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
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
    private BidiTui $tui;
    private Renderer $renderer;
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
        $this->pane = new class extends AbstractWidget implements MenuOverlayHost {
            use MenuOverlayHostTrait;

            /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed toast spans */
            public array $toastSpans = [];

            public bool $backdrop = false;

            public function setToastOverlay(array $spans): void
            {
                $this->toastSpans = $spans;
            }

            public function setModalBackdrop(bool $on): void
            {
                $this->backdrop = $on;
            }

            public function render(RenderContext $context): array
            {
                return array_fill(0, $context->getRows(), str_repeat('.', $context->getColumns()));
            }

            public function handleInput(string $data): void {}
        };
        $this->tui->add($this->pane);
        $this->scheduler = new FakeScheduler();
        $this->toasts = new ToastCenter($this->scheduler, $this->tui);

        return new ToastPresenter(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], [], [], static fn (): bool => false, static fn (): bool => false), $this->tui),
            $this->toasts,
            frameHooks: $this->tui,
        );
    }

    /** Record the pane's rect so the presenter has bounds to lay out against. */
    private function frame(): void
    {
        $root = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'root')->getValue($this->tui);
        \assert($root instanceof \Symfony\Component\Tui\Widget\ContainerWidget);
        $this->renderer->render($root, 100, 24);
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

        // A later sync must NOT touch the pane again.
        $this->pane->toastSpans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'W']];
        $presenter->sync();
        $this->assertNotSame([], $this->pane->toastSpans, 'a quiet presenter leaves the slot alone');
    }

    public function testAStackTallerThanTheUnionStopsInsteadOfClipping(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        foreach (range(1, 5) as $i) {
            $this->toasts->push(str_repeat("very long message {$i} ", 12), ToastSeverity::Info);
        }

        $presenter->sync();

        $this->assertLessThan(5, \count($presenter->regions()), 'the older tail waits instead of clipping past the union');
        foreach ($presenter->regions() as $region) {
            $this->assertLessThanOrEqual(24, $region->top + $region->count);
        }
    }
}
