<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The markdown file as it reads: rendered to the pane's width, following the
 * text it is handed without losing the reader's place, and scrolled by the
 * editor's own navigation keys.
 */
final class MarkdownPreviewWidgetTest extends TestCase
{
    use RendersWidget;

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

    public function testItRendersTheTextItIsHanded(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/README.md', "# Porta\n\nSome text.\n");

        $rows = $this->drawn($pane, 40, 5);
        $this->assertSame('Porta', \rtrim($rows[0]));
        $this->assertSame('Some text.', \rtrim($rows[3]));
        $this->assertSame('markdown', $pane->paneId());
        $this->assertSame('', $pane->statusRight(), 'the editor\'s legend stays beside it');
    }

    public function testANewTextForTheSameFileKeepsTheScrollAndANewFileStartsAtTheTop(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/a.md', self::longDoc());
        $this->drawn($pane, 40, 6);
        $pane->handleInput("\x1b[6~"); // PageDown
        $scrolled = $this->drawn($pane, 40, 6)[0];
        $this->assertNotSame('Title', \rtrim($scrolled), 'a page down moved the view');

        $pane->show('/p/a.md', self::longDoc('Renamed'));
        $this->assertSame($scrolled, $this->drawn($pane, 40, 6)[0], 'an edit keeps the reader where they were');

        $pane->show('/p/b.md', self::longDoc('Other'));
        $this->assertSame('Other', \rtrim($this->drawn($pane, 40, 6)[0]), 'another file starts from its top');
    }

    public function testTheSameTextForTheSameFileIsNotRedrawn(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/a.md', '# A');
        $revision = $pane->getRenderRevision();

        $pane->show('/p/a.md', '# A');

        $this->assertSame($revision, $pane->getRenderRevision());
    }

    public function testTheKeysScrollAndHandTabVerbsToTheHandler(): void
    {
        $pane = new MarkdownPreviewWidget();
        $handler = new RecordingViewerTabHandler();
        $pane->setHandler($handler);
        $pane->show('/p/a.md', self::longDoc());
        $this->drawn($pane, 40, 6);

        $pane->handleInput("\x1b[F"); // End
        $this->assertSame('Paragraph 30.', \rtrim($this->drawn($pane, 40, 6)[5]), 'End shows the last row at the bottom');
        $pane->handleInput("\x1b[H"); // Home
        $this->assertSame('Title', \rtrim($this->drawn($pane, 40, 6)[0]));
        $pane->handleInput("\x1b[B"); // Down
        $pane->wheelBy(1);
        $this->assertSame('Paragraph 1.', \rtrim($this->drawn($pane, 40, 6)[1]), 'two rows down: the underline and the blank are gone');

        $pane->handleInput("\x1bw");
        $this->assertContains('close', $handler->events, 'a tab verb goes to the IDE');
    }

    public function testClearAndATinyPaneDrawNothing(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/a.md', '# A');
        $this->assertSame([], $pane->render(new RenderContext(0, 5)));

        $pane->clear();
        $this->assertSame(['', ''], \array_map(\rtrim(...), $this->drawn($pane, 10, 2)));
    }

    public function testAThemeSwitchRerendersTheText(): void
    {
        $pane = new MarkdownPreviewWidget();
        $pane->show('/p/a.md', '# A');
        $this->drawn($pane, 10, 2);
        $revision = $pane->getRenderRevision();

        $pane->refreshTheme();

        $this->assertNotSame($revision, $pane->getRenderRevision());
    }
}
