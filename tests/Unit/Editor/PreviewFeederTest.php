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
use Porta\Tests\Support\TestPane;
use Porta\Tests\Support\TestTui;

final class PreviewFeederTest extends TestCase
{
    private BufferManager $buffers;
    private TestPane $pane;
    private FakeScheduler $scheduler;
    private PreviewFeeder $feeder;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->pane = new TestPane('md');
        $panes = new EditorPanes();
        $panes->register($this->pane, static fn (string $path): string => '');
        $this->scheduler = new FakeScheduler();
        $this->feeder = new PreviewFeeder($this->buffers, $panes, $this->scheduler, TestTui::create(80, 24));
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
}
