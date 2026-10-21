<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\Tests\Support\DiffWidgetEvents;
use Porta\DiffPlugin\Widget\DiffViewWidget;
use Porta\I18n\Translator;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The diff pane under soft wrap: a long line continues on the rows below, its
 * numbers and marker on the first only, and every mapping from a screen row
 * — a click, a drag, a page, a scroll — goes through the rows it takes.
 *
 * At 40 columns the unified text is 28 wide (12 for the gutters and marker).
 * The body rows are then: the hunk, ` keep`, the 30 o's on two rows and the
 * 70 n's on three.
 */
final class DiffViewWrapTest extends TestCase
{
    private const string RAW = <<<'DIFF'
        diff --git a/a.txt b/a.txt
        --- a/a.txt
        +++ b/a.txt
        @@ -1,2 +1,2 @@
         keep
        -oooooooooooooooooooooooooooooo
        +nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn
        DIFF;

    /** The title and the rule under it, above the first body row. */
    private const int HEADER = 2;

    private function widget(?EventDispatcher $events = null): DiffViewWidget
    {
        $widget = new DiffViewWidget(new Translator('en'), events: $events);
        $widget->setDocument(DiffDocument::parse(self::RAW), 'a.txt');
        $widget->useSoftWrap();

        return $widget;
    }

    /** @return list<string> the body rows, plain */
    private static function body(DiffViewWidget $widget, int $cols = 40, int $rows = 9): array
    {
        return \array_slice(\array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($cols, $rows + self::HEADER))), self::HEADER);
    }

    public function testALongLineContinuesBelowWithItsNumbersAndMarkerOnTheFirstRowOnly(): void
    {
        $body = self::body($this->widget());

        $this->assertMatchesRegularExpression('/^\s+2\s+ │-o{28}$/', $body[2], 'the deletion\'s first row: its number and marker');
        $this->assertSame(\str_repeat(' ', times: 10).'│ oo'.\str_repeat(' ', times: 26), $body[3], 'its second: blank gutter, blank marker, the rest');
        $this->assertMatchesRegularExpression('/^\s+2 │\+n{28}$/', $body[4]);
        $this->assertSame(\str_repeat(' ', times: 10).'│ '.\str_repeat('n', times: 28), $body[5]);
        $this->assertSame(\str_repeat(' ', times: 10).'│ '.\str_repeat('n', times: 14).\str_repeat(' ', times: 14), $body[6]);
        $this->assertSame(\str_repeat(' ', times: 40), $body[7], 'nothing after the last row');
    }

    /** A continuation row is still the change: banded, and a deletion's text still red. */
    public function testAContinuationRowKeepsItsBandAndColour(): void
    {
        $raw = \array_slice($this->widget()->render(new RenderContext(40, 9 + self::HEADER)), self::HEADER);

        $this->assertStringStartsWith("\x1b[48;5;52m", $raw[3], 'the deletion\'s band');
        $this->assertStringContainsString("\x1b[38;5;203m", $raw[3], 'and its red');
        $this->assertStringStartsWith("\x1b[48;5;22m", $raw[6], 'the addition\'s band on its last row');
        foreach ($raw as $row) {
            $this->assertSame(40, AnsiUtils::visibleWidth($row));
        }
    }

    /** Side by side each half breaks at its own width; the shorter one shows blank rows, banded still. */
    public function testASplitPairIsAsTallAsItsTallerHalf(): void
    {
        $widget = $this->widget();
        $widget->useSplitView();
        // 61 columns: each half 24 wide — the o's take two rows, the n's three.
        $raw = \array_slice($widget->render(new RenderContext(61, 6 + self::HEADER)), self::HEADER);
        $body = \array_map(AnsiUtils::stripAnsiCodes(...), $raw);

        $this->assertMatchesRegularExpression('/^\s+2 -o{24}│\s+2 \+n{24}$/', $body[2]);
        $this->assertSame('      oooooo'.\str_repeat(' ', times: 18).'│      '.\str_repeat('n', times: 24), $body[3]);
        $this->assertSame(\str_repeat(' ', times: 30).'│      '.\str_repeat('n', times: 22).'  ', $body[4], 'the old half has run out');
        $this->assertStringStartsWith("\x1b[48;5;52m", $raw[4], 'and is still banded red');
        $this->assertSame(\str_repeat(' ', times: 61), $body[5]);
    }

    public function testNothingScrollsSidewaysWhileWrapped(): void
    {
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse(self::RAW), 'a.txt');
        $widget->render(new RenderContext(40, 11));
        $widget->scrollHBy(8);
        $this->assertSame(8, $widget->hScroll());

        $widget->useSoftWrap();
        $this->assertTrue($widget->isWrapped());
        $this->assertSame(0, $widget->hScroll(), 'wrapping goes back to the start');
        $widget->handleInput("\x1b[C");
        $this->assertSame(0, $widget->hScroll(), '→ has nothing to show');
        $this->assertStringNotContainsString('[→', self::body($widget)[0] ?? '');

        $widget->useSoftWrap();
        $widget->useHorizontalScroll();
        $widget->useHorizontalScroll();
        $this->assertFalse($widget->isWrapped());
        $this->assertCount(1, \array_filter(self::body($widget), static fn (string $row): bool => \str_contains($row, 'n')), 'one row per line again');
        $widget->scrollHBy(8);
        $this->assertSame(8, $widget->hScroll());
    }

    /**
     * A press and a drag on continuation rows select from where the text is
     * shown there: a second row's first column is the 29th of the line. A press
     * on the blank gutter lands at that row's start, never the row above's end.
     */
    public function testAClickOnAContinuationRowMapsToItsSegment(): void
    {
        $events = new EventDispatcher();
        $copies = [];
        DiffWidgetEvents::wire($events, onCopy: static function (string $text) use (&$copies): void {
            $copies[] = $text;
        });
        $widget = $this->widget($events);
        self::body($widget);

        // Body rows 5 and 6 are the n's second and third: text from col 12.
        $widget->handleClick(self::HEADER + 5, 15);
        $widget->dragSelectTo(self::HEADER + 6, 14);
        $widget->handleInput("\x03");
        $this->assertSame([\str_repeat('n', times: 27)], $copies, 'from the 31st column to the 58th');

        $widget->handleClick(self::HEADER + 5, 2);
        $widget->dragSelectTo(self::HEADER + 6, 12);
        $widget->handleInput("\x03");
        $this->assertSame(\str_repeat('n', times: 28), $copies[1], 'the gutter press is the second row\'s start');

        $widget->handleClick(self::HEADER + 30, 12);
        $widget->handleInput("\x03");
        $this->assertSame(\str_repeat('n', times: 70)."\n", $copies[2], 'below the last row: the last row');
    }

    public function testTheCursorKeepsATallRowInViewAndAPageCountsRows(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        self::body($widget, rows: 4);

        $widget->handleInput("\x1b[6~"); // PgDn: hunk, keep and the o's fill four rows — a page is two rows on
        $widget->handleInput("\x1b[B");
        $body = self::body($widget, rows: 4);
        $this->assertSame(\str_repeat(' ', times: 10).'│ '.\str_repeat('n', times: 14).\str_repeat(' ', times: 14), $body[2], 'the n\'s all shown, from their first row');
        $this->assertStringContainsString('[scrolled +3]', AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(40, 6))[0]));

        $widget->scrollBy(-10);
        $widget->scrollBy(10);
        $this->assertStringContainsString('[scrolled +3]', AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(40, 6))[0]), 'the wheel reaches the last row and stops there');
    }

    public function testAWiderPaneReWraps(): void
    {
        $widget = $this->widget();
        $this->assertCount(3, \array_filter(self::body($widget), static fn (string $row): bool => \str_contains($row, 'n')));

        $this->assertCount(1, \array_filter(self::body($widget, cols: 100), static fn (string $row): bool => \str_contains($row, 'n')), 'seventy fit in 88');
    }

    public function testOnlyAHunkRowAnswersAsTheExpandMark(): void
    {
        $widget = $this->widget();
        self::body($widget);

        $this->assertInstanceOf(Tooltip::class, $widget->tooltipAt(self::HEADER, 1), 'the hunk row');
        $this->assertNull($widget->tooltipAt(self::HEADER + 3, 1), 'a continuation row');
        $this->assertNull($widget->tooltipAt(self::HEADER + 20, 1), 'below the last row');
    }

    /** git's `\ No newline at end of file` wraps like a line, dim on every row. */
    public function testTheNoNewlineMarkerStaysDimOnItsContinuationRows(): void
    {
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse("diff --git a/a.txt b/a.txt\n--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-a\n+b\n\\ No newline at end of file"), 'a.txt');
        $widget->useSoftWrap();
        // 30 columns: 18 of text, so the 26-column ` No newline at end of file` takes two rows.
        $raw = \array_slice($widget->render(new RenderContext(30, 6 + self::HEADER)), self::HEADER);

        $this->assertSame(\str_repeat(' ', times: 10).'│  of file'.\str_repeat(' ', times: 10), AnsiUtils::stripAnsiCodes($raw[4]));
        $this->assertStringContainsString(Styles::dark()->of(Role::Dim)->getAnsiRestore().' ', $raw[4], 'the dim opened in the marker column');
    }
}
