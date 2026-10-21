<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Editor\PreviewFeeder;
use Porta\Editor\PreviewMode;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestPane;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

final class PreviewFeederTest extends TestCase
{
    private BufferManager $buffers;
    private TestPane $pane;
    private FakeScheduler $scheduler;
    private PreviewFeeder $feeder;
    private CodeEditorWidget $editor;
    private TestPane $flank;
    private Tui $tui;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->flank = new TestPane('ours');
        $this->pane = new TestPane('md', $this->flank);
        $panes = new EditorPanes();
        $panes->register($this->pane, static fn (string $path): string => '');
        $this->scheduler = new FakeScheduler();
        $this->editor = new CodeEditorWidget();
        $this->tui = TestTui::create(80, 24);
        $this->feeder = new PreviewFeeder($this->buffers, $panes, $this->scheduler, $this->tui, $this->editor);
    }

    private function open(string $text, PreviewMode $mode = PreviewMode::Split): Buffer
    {
        $buffer = $this->buffers->open('/p/README.md', $text);
        $buffer->previewId = 'md';
        $buffer->previewMode = $mode;

        return $buffer;
    }

    private function shows(): int
    {
        return \count(\array_filter($this->pane->calls, static fn (string $call): bool => \str_starts_with($call, 'show:')));
    }

    public function testFeedHandsThePaneTheTextNow(): void
    {
        $this->feeder->feed($this->open('# one'));

        $this->assertSame(1, $this->shows());
        $this->assertSame('# one', $this->pane->shownPayload);
        $this->assertSame('/p/README.md', $this->pane->shownPath);
    }

    public function testAChangedTextIsFedOncePerPauseNotPerFrame(): void
    {
        $buffer = $this->open('# one');
        $this->feeder->feed($buffer);

        $this->feeder->watch();
        $this->assertSame([], $this->scheduler->scheduled, 'an unchanged text arms nothing');

        $buffer->document->insert('x');
        $this->feeder->watch();
        $this->feeder->watch();
        $this->assertCount(1, $this->scheduler->scheduled, 'the same version pending: one timer, however many frames');
        $this->assertSame([PreviewFeeder::PAUSE_SECONDS], \array_values($this->scheduler->delays));

        // Typing on re-arms the pause rather than stacking a second render.
        $buffer->document->insert('y');
        $this->feeder->watch();
        $this->assertCount(1, $this->scheduler->scheduled);
        $this->assertCount(1, $this->scheduler->cancelled, 'the older pause was cancelled');

        $this->scheduler->fire();
        $this->assertSame(2, $this->shows(), 'fed once more');
        $this->assertSame('xy# one', $this->pane->shownPayload, 'with the text as it stands');

        $this->feeder->watch();
        $this->assertSame([], $this->scheduler->scheduled, 'the fed version arms nothing more');
    }

    public function testNothingIsFedWhileThePreviewIsOffScreen(): void
    {
        $buffer = $this->open('# one', PreviewMode::Editor);
        $this->feeder->feed($buffer);
        $buffer->document->insert('x');
        $this->feeder->watch();

        $this->assertSame(0, $this->shows());
        $this->assertSame([], $this->scheduler->scheduled);
    }

    public function testATimerThatFiresWithNoTabOpenFeedsNothing(): void
    {
        $buffer = $this->open('# one');
        $this->feeder->feed($buffer);
        $buffer->document->insert('x');
        $this->feeder->watch();
        $this->buffers->close($buffer->path);

        $this->scheduler->fire();

        $this->assertSame(1, $this->shows(), 'only the first feed');
    }

    /** @return list<string> the follow calls, in order */
    private function follows(): array
    {
        return \array_values(\array_filter($this->pane->calls, static fn (string $call): bool => \str_starts_with($call, 'follow:')));
    }

    public function testThePreviewFollowsTheCaretOncePerLineItReaches(): void
    {
        $buffer = $this->open("# one\n\ntwo\nthree\n");
        $this->feeder->watch();
        $this->assertSame(['follow:0,0'], $this->follows(), 'where the caret starts');

        $buffer->document->setCursor(2, 1);
        $this->feeder->watch();
        $buffer->document->setCursor(2, 3); // the same line: nothing new to show
        $this->feeder->watch();
        $buffer->document->setCursor(3, 2);
        $this->feeder->watch();

        $this->assertSame(['follow:0,0', 'follow:2,1', 'follow:3,2'], $this->follows());
    }

    public function testAHiddenPreviewFollowsNothing(): void
    {
        $buffer = $this->open("# one\n\ntwo\n", PreviewMode::Editor);
        $buffer->document->setCursor(2, 0);
        $this->feeder->watch();

        $this->assertSame([], $this->follows());
    }

    public function testAnEditorScrollRepaintsThePreviewAndItsFlankOnce(): void
    {
        $this->open("# one\n");
        $this->editor->openFile('README.md', \implode("\n", \range(1, end: 60)));
        $this->editor->render(new RenderContext(80, 10));
        $this->feeder->watch();
        $revisions = static fn (TestPane ...$panes): array => \array_map(static fn (TestPane $pane): int => $pane->getRenderRevision(), $panes);
        $before = $revisions($this->pane, $this->flank);

        Probe::set($this->tui, 'renderRequested', false);
        $this->feeder->watch();
        $this->assertSame($before, $revisions($this->pane, $this->flank), 'a frame with the view where it was repaints nothing');
        $this->assertFalse((bool) Probe::get($this->tui, 'renderRequested'), 'and asks for no frame');

        $this->editor->scrollBy(5);
        $this->feeder->watch();
        $this->assertSame([$before[0] + 1, $before[1] + 1], $revisions($this->pane, $this->flank), 'a scroll repaints both');
        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'), 'and asks for the frame that sees the headers the editor pins as it renders');
    }

    /**
     * Under soft wrap the view moves without its top LINE moving: a toggle
     * re-breaks every line, and a notch scrolls within a tall one. The merge
     * view's sides draw level with those rows, so each repaints them.
     */
    public function testASoftWrapToggleAndAScrollWithinAWrappedLineRepaintTheFlank(): void
    {
        $this->open("# one\n");
        $this->editor->openFile('README.md', \str_repeat('x', times: 400)."\n".\implode("\n", \range(1, end: 60)));
        $this->editor->render(new RenderContext(80, 10));
        $this->feeder->watch();
        $revisions = fn (): int => $this->flank->getRenderRevision();
        $before = $revisions();

        $this->editor->display->setSoftWrap(true);
        $this->editor->render(new RenderContext(80, 10));
        $this->feeder->watch();
        $this->assertSame($before + 1, $revisions(), 'the toggle repaints the flank');

        // The caret at the long line's end, in a pane shorter than the line:
        // the view scrolls INTO it, its top line unmoved.
        $this->editor->getDocument()->setCursor(0, 400);
        $this->editor->render(new RenderContext(80, 3));
        $this->assertSame(0, $this->editor->viewport()->vScroll, 'still the long line on top');
        $this->assertGreaterThan(0, $this->editor->viewport()->vScrollSegment, 'some of its rows scrolled away');
        $this->feeder->watch();
        $this->assertSame($before + 2, $revisions(), 'a scroll within the line repaints it too');
    }
}
