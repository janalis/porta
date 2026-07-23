<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\PickerModalPresenter;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Ui\BidiTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The picker presenter: the per-frame span delivery over the pane union, the
 * pre-first-frame retry, the detached-editor gate, and the clear-exactly-once
 * discipline on the span slot it shares with the Welcome and Find-in-Files
 * modals.
 */
final class PickerModalPresenterTest extends TestCase
{
    private BidiTui $tui;
    private Renderer $renderer;
    private CodeEditorWidget $editor;
    private AbstractWidget&MenuOverlayHost $pane;

    private function presenter(bool $attachEditor = true): PickerModalPresenter
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $this->pane = new class extends AbstractWidget implements MenuOverlayHost {
            use MenuOverlayHostTrait;

            /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed modal spans */
            public array $modalSpans = [];

            public bool $backdrop = false;

            public function setModalOverlay(array $spans): void
            {
                $this->modalSpans = $spans;
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
        $this->editor = new CodeEditorWidget();
        if ($attachEditor) {
            $this->tui->add($this->editor);
        }

        return new PickerModalPresenter(
            $this->renderer,
            new MenuOverlayPanes([$this->pane], [], [], static fn (): bool => false, static fn (): bool => false),
            $this->editor,
            $this->tui,
        );
    }

    /** Record the pane's rect so the presenter has bounds to lay out against. */
    private function frame(): void
    {
        $root = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'root')->getValue($this->tui);
        \assert($root instanceof \Symfony\Component\Tui\Widget\ContainerWidget);
        $this->renderer->render($root, 100, 24);
    }

    private function openList(): void
    {
        $this->editor->openListOverlay('Recent Files', [['label' => 'a.php', 'hint' => 'src', 'value' => 'A']], static fn () => null);
    }

    public function testSyncPushesSpansShadowAndBackdropWhileOpen(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->openList();

        $presenter->sync();

        $this->assertNotSame([], $this->pane->modalSpans, 'the framed box was pushed onto the pane');
        $this->assertTrue($this->pane->backdrop, 'the workspace dims beneath it');
        $regions = $presenter->regions();
        $this->assertCount(1, $regions, 'one region per painted box');
        $this->assertTrue($regions[0]->ltr, 'paths/code content: LTR regions, never mirrored');
    }

    /** Every open surface paints — the prompt stacks over the search modal. */
    public function testEveryOpenSurfaceGetsARegion(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->editor->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $this->editor->openPrompt('Go to line', '', static fn () => null);

        $presenter->sync();

        $this->assertCount(2, $presenter->regions(), 'search and prompt each own a region');
    }

    /** Before any frame there are no rects — the presenter keeps asking for one. */
    public function testSyncBeforeAnyFrameRetriesWithoutPushing(): void
    {
        $presenter = $this->presenter();
        $this->openList();

        $presenter->sync();

        $this->assertSame([], $this->pane->modalSpans, 'nothing to lay out against yet');
        $this->assertSame([], $presenter->regions());
    }

    /** A detached editor (image tab, git/settings view) means an invisible picker: paint nothing. */
    public function testADetachedEditorPaintsNothing(): void
    {
        $presenter = $this->presenter(attachEditor: false);
        $this->frame();
        $this->openList();

        $presenter->sync();

        $this->assertSame([], $this->pane->modalSpans, 'no spans for a detached editor');
        $this->assertFalse($this->pane->backdrop);
        $this->assertSame([], $presenter->regions());
    }

    /** Closing clears spans and backdrop exactly once — the slot is shared with the full modals. */
    public function testSyncClearsExactlyOnceAfterClose(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->openList();
        $presenter->sync();

        $this->editor->handleInput("\x1b"); // Esc closes the list overlay
        $presenter->sync();
        $this->assertSame([], $this->pane->modalSpans, 'closing cleared the overlay');
        $this->assertFalse($this->pane->backdrop);
        $this->assertSame([], $presenter->regions());

        // A later sync must NOT touch the pane again (another modal may own it).
        $this->pane->modalSpans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'W']];
        $this->pane->backdrop = true;
        $presenter->sync();
        $this->assertNotSame([], $this->pane->modalSpans, 'a closed presenter leaves the shared slot alone');
        $this->assertTrue($this->pane->backdrop);
    }
}
