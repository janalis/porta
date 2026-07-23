<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\FindModalPresenter;
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
 * The Find-in-Files presenter: the per-frame span delivery over the pane
 * union, the pre-first-frame retry, and the clear-exactly-once discipline
 * that keeps it from clobbering the Welcome modal's shared span slot.
 */
final class FindModalPresenterTest extends TestCase
{
    private BidiTui $tui;
    private Renderer $renderer;
    private CodeEditorWidget $editor;
    private AbstractWidget&MenuOverlayHost $pane;

    private function presenter(): FindModalPresenter
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

        return new FindModalPresenter(
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

    public function testSyncPushesSpansShadowAndBackdropWhileOpen(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->editor->findModal()->open('Find in Files');

        $presenter->sync();

        $this->assertNotSame([], $this->pane->modalSpans, 'the framed box was pushed onto the pane');
        $this->assertTrue($this->pane->backdrop, 'the workspace dims beneath it');
        $region = $presenter->region();
        $this->assertNotNull($region);
        $this->assertTrue($region->ltr, 'code content: an LTR region, never mirrored');
    }

    /** Before any frame there are no rects — the presenter keeps asking for one. */
    public function testSyncBeforeAnyFrameRetriesWithoutPushing(): void
    {
        $presenter = $this->presenter();
        $this->editor->findModal()->open('Find in Files');

        $presenter->sync();

        $this->assertSame([], $this->pane->modalSpans, 'nothing to lay out against yet');
        $this->assertNull($presenter->region());
    }

    /** Closing clears spans and backdrop exactly once — the slot is shared with the Welcome modal. */
    public function testSyncClearsExactlyOnceAfterClose(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->editor->findModal()->open('Find in Files');
        $presenter->sync();

        $this->editor->findModal()->close();
        $presenter->sync();
        $this->assertSame([], $this->pane->modalSpans, 'closing cleared the overlay');
        $this->assertFalse($this->pane->backdrop);
        $this->assertNull($presenter->region());

        // A later sync must NOT touch the pane again (another modal may own it).
        $this->pane->modalSpans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'W']];
        $this->pane->backdrop = true;
        $presenter->sync();
        $this->assertNotSame([], $this->pane->modalSpans, 'a closed presenter leaves the shared slot alone');
        $this->assertTrue($this->pane->backdrop);
    }
}
