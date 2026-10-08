<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\SourceEditRequested;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\RecordingEvents;
use Porta\Tests\Support\RecordingHelpViewerHandler;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MarkdownPageWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Where the page's own links lead: a heading or a footnote on the page, a
 * section of another page, a `<details>` that opens in place, a task's box
 * that ticks the text — and the images that turn to pixels once the terminal
 * says it can draw them.
 */
final class MarkdownPageLinksTest extends TestCase
{
    use RendersWidget;

    private TempProject $project;
    private RecordingHelpViewerHandler $handler;
    private RecordingEvents $events;

    protected function setUp(): void
    {
        $this->project = new TempProject([
            'docs/page.md' => "# Page\n",
            'docs/other.md' => "# Other\n",
            'docs/logo.png' => "\x89PNG\r\n\x1a\n".\pack('N', 13).'IHDR'.\pack('NNCCCCC', 80, 32, 8, 6, 0, 0, 0).\pack('N', 0),
        ]);
        $this->handler = new RecordingHelpViewerHandler();
        $this->events = new RecordingEvents();
    }

    private function preview(?MarkdownPreviewWidget $pane = null): MarkdownPreviewWidget
    {
        $pane ??= new MarkdownPreviewWidget(events: $this->events);
        $pane->setHandler($this->handler);
        $pane->setDocsRoot($this->project->dir);

        return $pane;
    }

    /** @return list<string> */
    private function drawn(MarkdownPageWidget $pane, int $rows = 8): array
    {
        return \array_map(\trim(...), $this->rows($pane->render(new RenderContext(40, $rows))));
    }

    /** Click the first row showing $text, on its first character. */
    private function click(MarkdownPageWidget $pane, string $text): void
    {
        foreach ($this->rows($pane->render(new RenderContext(40, 8))) as $row => $line) {
            $col = \mb_strpos($line, $text);
            if (false !== $col) {
                $pane->press($row, $col);

                return;
            }
        }
        $this->fail('nothing shows '.$text);
    }

    private static function tall(string $end): string
    {
        return "[down](#far-below) [gone](#nowhere) [mail](mailto:me@x.org) [there](other.md#later)\n\n".\str_repeat("filler\n\n", times: 20).$end.\str_repeat("\nmore\n", times: 10);
    }

    public function testAnAnchorLinkBringsItsHeadingToTheTop(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), self::tall("## Far below\n"));
        $this->drawn($pane);
        $this->click($pane, 'down');
        $this->assertSame('Far below', $this->drawn($pane)[2]);
        $this->click($pane, 'Far below'); // not a link: the text is revealed instead
    }

    public function testAMissingAnchorAndAMailLinkGoWhereTheHandlerSays(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), self::tall(''));
        $this->click($pane, 'gone');
        $this->click($pane, 'mail');
        $this->assertSame(['missing:#nowhere', 'url:mailto:me@x.org'], $this->handler->events);
    }

    public function testALinkToAnotherPagesSectionOpensItThere(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), self::tall(''));
        $this->click($pane, 'there');
        $this->assertSame(['help:other.md'], $this->handler->events);
        $pane->show($this->project->path('docs/other.md'), self::tall("## Later\n")); // what the handler does
        $this->assertSame('Later', $this->drawn($pane)[2]);
        $pane->show($this->project->path('docs/page.md'), self::tall('')); // a page without it: the view starts at the top
        $this->assertSame('down gone mail there', $this->drawn($pane)[2]);
    }

    public function testADetailsSectionOpensAndClosesWhereItIs(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), "<details>\n<summary>More</summary>\n\nHidden.\n</details>\n");
        $this->assertNotContains('Hidden.', $this->drawn($pane));
        $this->click($pane, 'More');
        $this->assertContains('Hidden.', $this->drawn($pane));
        $this->click($pane, 'More');
        $this->assertNotContains('Hidden.', $this->drawn($pane));
        $pane->handleInput("\t");
        $pane->handleInput("\r");
        $this->assertContains('Hidden.', $this->drawn($pane), 'Tab focuses it, Enter opens it');
    }

    public function testATasksBoxAsksForItsTextToBeTicked(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), "- [ ] todo\n- [X] done\n");
        $this->click($pane, '[ ]');
        $this->click($pane, '['."\u{2713}".']');
        $edits = $this->events->take(SourceEditRequested::class);
        $this->assertEquals(
            [
                new SourceEditRequested($this->project->path('docs/page.md'), 2, 5, '[ ]', '[x]'),
                new SourceEditRequested($this->project->path('docs/page.md'), 13, 16, '[X]', '[ ]'),
            ],
            $edits,
        );
    }

    public function testTheHelpBrowsersTasksAreReadOnly(): void
    {
        $viewer = new HelpViewerWidget();
        $viewer->setHandler($this->handler);
        $viewer->setDocsRoot($this->project->dir);
        $viewer->show($this->project->path('docs/page.md'), "- [ ] todo\n");
        $this->click($viewer, '[ ]');
        $this->assertSame([], $this->handler->events);
    }

    public function testAnImageTurnsToPixelsOnceTheTerminalAnswers(): void
    {
        [$graphics] = PixelGraphics::unanswered();
        $pane = $this->preview(new MarkdownPreviewWidget(graphics: $graphics, events: $this->events));
        $pane->show($this->project->path('docs/page.md'), "![Logo](logo.png)\n");
        $this->assertSame('[Logo]', $this->drawn($pane)[2]);
        PixelGraphics::answer($graphics, "\x1b_Gi=31;OK\x1b\\", "\x1b[?62;22c");
        $this->assertStringContainsString("\u{100000}", $this->rows($pane->render(new RenderContext(40, 8)))[2] ?? '', 'the placeholder now');
    }
}
