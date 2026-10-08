<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\SourceEditRequested;
use Porta\Highlight\CodeHighlighterFactory;
use Porta\I18n\Translator;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingEvents;
use Porta\Tests\Support\RecordingHelpViewerHandler;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
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
        $revision = $pane->getRenderRevision();
        $this->click($pane, 'down');
        $this->assertGreaterThan($revision, $pane->getRenderRevision(), 'the scroll asks to be redrawn');
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

        // Typing above the section moves where it starts, not which one it is: it stays open.
        $pane->show($this->project->path('docs/page.md'), "Typed.\n\n<details>\n<summary>More</summary>\n\nHidden.\n</details>\n");
        $this->assertContains('Hidden.', $this->drawn($pane));
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

    public function testALinkWrittenToLookLikeAPageActionIsOnlyALink(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), "abc [tick](task:0-3) [open](details:0) <a href=\"task:0-3\">html</a>\n");
        $this->click($pane, 'tick');
        $this->click($pane, 'open');
        $this->click($pane, 'html');
        $this->assertSame([], $this->events->take(SourceEditRequested::class), 'the text is never edited');
        $this->assertSame(['missing:task:0-3', 'missing:details:0', 'missing:task:0-3'], $this->handler->events);
    }

    public function testEnterBeforeAnyLinkIsTakenDoesNothing(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), "- [ ] todo [link](other.md)\n");
        $this->drawn($pane);
        $pane->handleInput("\r");
        $this->assertSame([], $this->handler->events);
        $this->assertSame([], $this->events->take(SourceEditRequested::class));
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
        $revision = $pane->getRenderRevision();
        PixelGraphics::answer($graphics, "\x1b_Gi=31;OK\x1b\\", "\x1b[?62;22c");
        $this->assertStringContainsString("\u{100000}", $this->rows($pane->render(new RenderContext(40, 8)))[2] ?? '', 'the placeholder now');
        $this->assertGreaterThan($revision, $pane->getRenderRevision(), 'the answer asks for the page to be laid out again');
    }

    public function testTheWordsThePageDrawsItselfSpeakTheIdesLanguage(): void
    {
        $pane = $this->preview(new MarkdownPreviewWidget(translator: new Translator('fr'), events: $this->events));
        $kinds = ['NOTE', 'TIP', 'IMPORTANT', 'WARNING', 'CAUTION'];
        $text = \implode("\n", \array_map(static fn (string $kind): string => "> [!{$kind}]\n> x\n", $kinds))."\n<details>\n\nhidden\n\n</details>\n";
        $pane->show($this->project->path('docs/page.md'), $text);

        $drawn = \implode("\n", $this->drawn($pane, rows: 24));
        foreach (['Remarque', 'Astuce', 'Important', 'Avertissement', 'Attention', 'Détails'] as $word) {
            $this->assertStringContainsString($word, $drawn);
        }
    }

    public function testEachDetailsSectionOpensItselfAndAsksToBeRedrawn(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), "<details><summary>One</summary>first</details>\n\n<details><summary>Two</summary>second</details>\n");
        $this->drawn($pane);
        $revision = $pane->getRenderRevision();
        $this->click($pane, 'Two');
        $this->assertGreaterThan($revision, $pane->getRenderRevision());
        $drawn = $this->drawn($pane);
        $this->assertContains('second', $drawn);
        $this->assertNotContains('first', $drawn);
    }

    public function testAFootnoteWhoseLabelHoldsAHashIsFound(): void
    {
        $pane = $this->preview();
        $pane->show($this->project->path('docs/page.md'), "See[^a#1].\n\n[^a#1]: Note.\n");
        $this->click($pane, '[1]');
        $this->assertSame([], $this->handler->events, 'no missing target: the # is part of the label');
    }

    public function testAThemeSwitchForgetsThePagesCodeColours(): void
    {
        $pane = $this->preview(new MarkdownPreviewWidget(new Styles(DarkTheme::definition()), new CodeHighlighterFactory(), events: $this->events));
        $pane->show($this->project->path('docs/page.md'), "```php\n\$a = 1;\n```\n");
        $pane->render(new RenderContext(40, 8));
        $code = Probe::get(Probe::get($pane, 'renderer'), 'code');
        $this->assertNotSame([], Probe::get($code, 'memo'), 'the coloured line is remembered');
        $pane->refreshTheme();
        $this->assertSame([], Probe::get($code, 'memo'), 'and forgotten with the old palette');
    }

    public function testThePreviewReadsASlashFromItsProjectsRoot(): void
    {
        $pane = $this->preview(new MarkdownPreviewWidget(graphics: PixelGraphics::answered()[0], events: $this->events));
        $pane->show($this->project->path('docs/page.md'), "![Logo](/docs/logo.png)\n");
        $this->assertStringContainsString("\u{100000}", $this->rows($pane->render(new RenderContext(40, 8)))[2] ?? '', 'the project is the tree, its root where / starts');
    }
}
