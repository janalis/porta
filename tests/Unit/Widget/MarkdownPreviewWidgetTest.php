<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RecordingHelpViewerHandler;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The markdown file as the documentation reads: under the navigation bar,
 * following the text it is handed without losing the reader's place, and
 * leading its links to the files they name.
 */
final class MarkdownPreviewWidgetTest extends TestCase
{
    use RendersWidget;

    private TempProject $project;
    private RecordingHelpViewerHandler $handler;

    /** @return list<string> the pane at $columns × $rows, ANSI-stripped */
    private function drawn(MarkdownPreviewWidget $pane, int $columns, int $rows): array
    {
        return $this->rows($pane->render(new RenderContext($columns, $rows)));
    }

    /** A document taller than the pane: a heading, then numbered paragraphs. */
    private static function longDoc(string $heading = 'Title'): string
    {
        $paragraphs = \array_map(static fn (int $n): string => 'Paragraph '.$n.'.', \range(1, end: 30));

        return '# '.$heading."\n\n".\implode("\n\n", $paragraphs)."\n";
    }

    /** A preview rooted in a project holding docs/guide.md, wired to a recording handler. */
    private function pane(): MarkdownPreviewWidget
    {
        $this->project = new TempProject([
            'README.md' => "# Project\n",
            'docs/README.md' => "# Docs\n",
            'docs/guide.md' => "# Guide\n\nSee [the other](other.md) and [the site](https://example.com).\n",
            'docs/other.md' => "# Other\n",
        ]);
        $pane = new MarkdownPreviewWidget();
        $this->handler = new RecordingHelpViewerHandler();
        $pane->setHandler($this->handler);
        $pane->setDocsRoot($this->project->dir);

        return $pane;
    }

    public function testThePageReadsUnderTheNavigationBarWithAMargin(): void
    {
        $pane = $this->pane();
        $pane->show($this->project->path('docs/guide.md'), (string) \file_get_contents($this->project->path('docs/guide.md')));

        $rows = $this->drawn($pane, 60, 6);
        $this->assertStringContainsString('README › docs › guide', $rows[0], 'the breadcrumb walks the project, like the docs tree');
        $this->assertSame(' Guide', \rtrim($rows[1]), 'a column of margin, away from the divider');
        $this->assertSame('markdown', $pane->paneId());
        $this->assertSame('', $pane->statusRight(), 'the editor\'s legend stays beside it');
    }

    public function testANewTextForTheSameFileKeepsThePlaceAndANewFileStartsAtTheTop(): void
    {
        $pane = $this->pane();
        $pane->show('/p/a.md', self::longDoc());
        $this->drawn($pane, 40, 6);
        $pane->handleInput("\x1b[6~"); // PageDown
        $scrolled = $this->drawn($pane, 40, 6)[1];
        $this->assertNotSame('Title', \trim($scrolled), 'a page down moved the view');

        $pane->show('/p/a.md', self::longDoc('Renamed'));
        $this->assertSame($scrolled, $this->drawn($pane, 40, 6)[1], 'an edit keeps the reader where they were');

        $pane->show('/p/b.md', self::longDoc('Other'));
        $this->assertSame('Other', \trim($this->drawn($pane, 40, 6)[1]), 'another file starts from its top');
    }

    public function testTheSameTextForTheSameFileIsNotRedrawn(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/a.md', '# A');
        $revision = $pane->getRenderRevision();

        $pane->show('/p/a.md', '# A');

        $this->assertSame($revision, $pane->getRenderRevision());
    }

    public function testALinkToAnotherMarkdownFileLeadsThereAndBackspaceComesBack(): void
    {
        $pane = $this->pane();
        $guide = $this->project->path('docs/guide.md');
        $pane->show($guide, (string) \file_get_contents($guide));
        // What the IDE does with openHelp for the preview: that file's tab, whose
        // preview is this same pane, handed its text.
        $this->handler->onOpenHelp = static function (string $path) use ($pane): void {
            $pane->show($path, (string) \file_get_contents($path));
        };
        $this->drawn($pane, 80, 8);

        $pane->handleInput("\t"); // the first link
        $pane->handleInput("\r"); // follow it
        $this->assertSame(['help:other.md'], $this->handler->events);
        $this->assertStringContainsString('Other', $this->drawn($pane, 80, 8)[1]);

        $pane->handleInput("\x7f"); // Backspace
        $this->assertSame(['help:other.md', 'help:guide.md'], $this->handler->events, 'back to the page the link was followed from');

        $this->drawn($pane, 80, 8);
        $pane->handleInput("\t"); // back where it was on the first link: Tab moves on to the second, a URL
        $pane->handleInput("\r");
        $this->assertSame('url:https://example.com', \end($this->handler->events), 'a web link goes to the browser');
    }

    public function testCtrlFFindsInThePage(): void
    {
        $pane = $this->pane();
        $pane->show('/p/a.md', '# A');

        $pane->handleInput("\x06"); // Ctrl+F

        $this->assertSame(['find'], $this->handler->events);
    }

    public function testAOneColumnPaneHasNoRoomForTheMargin(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/a.md', '# A');

        $this->assertNotSame(' ', $this->drawn($pane, 1, 3)[1]);
    }
}
