<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\PickerModalPresenter;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Overlay\State\OverlayHost;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Symfony\Component\Tui\Input\Keybindings;
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
    use RendersRoot;

    private CodeEditorWidget $editor;
    private OverlayHost $overlays;
    private AbstractWidget&MenuOverlayHost $pane;

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    private function presenter(bool $attachEditor = true): PickerModalPresenter
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        // One host, shared: the widget delegates to it and the presenter reads
        // it, which is the whole point of it no longer being the widget's own.
        $this->overlays = new OverlayHost($this->tui);
        $this->editor = new CodeEditorWidget(overlayHost: $this->overlays);
        if ($attachEditor) {
            $this->tui->add($this->editor);
        }

        return new PickerModalPresenter(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui),
            $this->overlays,
            frameHooks: $this->tui,
        );
    }

    private function openList(): void
    {
        $this->editor->openListOverlay('Recent Files', [['label' => 'a.php', 'hint' => 'src', 'value' => 'A']], static fn (): null => null);
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
        $this->assertFalse($regions[0]->ltr, 'a bidi region: its chrome and row layout mirror under RTL, a path keeping its own order inside');
    }

    public function testFrameHookWiringAutoSyncsOnTheNextFrame(): void
    {
        // Every other test drives sync() by hand; this one proves the
        // constructor's onBeforeFrame registration is what makes that
        // automatic in the real app.
        $this->presenter();
        $this->frame();
        $this->openList();

        $this->tui->processRender(); // not $presenter->sync() — the frame hook must do it

        $this->assertNotSame([], $this->pane->modalSpans, 'the frame hook drove sync() automatically');
    }

    /** Every open surface paints — the prompt stacks over the search modal. */
    public function testEveryOpenSurfaceGetsARegion(): void
    {
        $presenter = $this->presenter();
        $this->frame();
        $this->editor->openSearch('Search', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $this->editor->openPrompt('Go to line', '', static fn (): null => null);

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

    /**
     * A detached editor still paints. It used not to, and that was the defect:
     * a full-screen view detaches the editor, so every picker raised over one —
     * the command palette the Git menu offers as view-safe, a branch list —
     * went invisible, and the git verbs closed the view to be seen at all.
     */
    public function testADetachedEditorStillPaintsThePickers(): void
    {
        $presenter = $this->presenter(attachEditor: false);
        $this->frame();
        $this->openList();

        $presenter->sync();

        $this->assertNotSame([], $this->pane->modalSpans, 'the picker paints over whatever owns the main area');
        $this->assertTrue($this->pane->backdrop);
        $this->assertCount(1, $presenter->regions(), 'and owns a shadow region like any other painted box');
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
