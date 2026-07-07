<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\DiffDocument;
use Porta\Widget\Git\DiffViewWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DiffViewWidgetTest extends TestCase
{
    private const string RAW = <<<'DIFF'
        diff --git a/src/demo.php b/src/demo.php
        --- a/src/demo.php
        +++ b/src/demo.php
        @@ -3,3 +3,4 @@ function demo()
         $keep = 1;
        -$old = 2;
        +$new = 2;
        +$extra = 3;
        @@ -10,1 +11,1 @@
        -tail
        +tail!
        DIFF;

    private function widget(): DiffViewWidget
    {
        $widget = new DiffViewWidget();
        $widget->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');

        return $widget;
    }

    /** @return list<string> */
    private function plainLines(DiffViewWidget $widget, int $cols = 80, int $rows = 12): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($cols, $rows)));
    }

    public function testRendersGuttersHunkHeadersAndBodies(): void
    {
        $lines = $this->plainLines($this->widget());

        $this->assertStringContainsString('src/demo.php', $lines[0]);
        $this->assertStringContainsString('@@ -3,3 +3,4 @@ function demo()', $lines[1]);
        $this->assertMatchesRegularExpression('/^\s+3\s+3 │ \$keep = 1;/', $lines[2], 'context: both line numbers');
        $this->assertMatchesRegularExpression('/^\s+4\s+ │-\$old = 2;/', $lines[3], 'deletion: old side only');
        $this->assertMatchesRegularExpression('/^\s+4 │\+\$new = 2;/', $lines[4], 'addition: new side only');
        $this->assertStringContainsString('@@ -10,1 +11,1 @@', $lines[6]);
    }

    public function testColorsMarkRowKinds(): void
    {
        $raw = $this->widget()->render(new RenderContext(80, 12));

        $this->assertStringContainsString("\x1b[38;5;75m", $raw[1], 'hunk header cyan');
        $this->assertStringContainsString("\x1b[38;5;203m", $raw[3], 'deletion red');
        $this->assertStringContainsString("\x1b[38;5;114m", $raw[4], 'addition green');
    }

    public function testAddedAndContextLinesKeepSyntaxHighlighting(): void
    {
        $raw = $this->widget()->render(new RenderContext(80, 12));

        // tempest/highlight colors PHP variables; the raw added row must carry
        // SGR codes beyond the plain green marker (i.e. highlighted body).
        $addRow = AnsiUtils::stripAnsiCodes($raw[4]);
        $this->assertStringContainsString('$new = 2;', $addRow);
        $this->assertGreaterThan(
            substr_count($raw[3], "\x1b["),
            substr_count($raw[4], "\x1b["),
            'the addition carries syntax SGR spans, the deletion stays plain',
        );
    }

    public function testPlaceholderWithoutADocument(): void
    {
        $widget = new DiffViewWidget();
        $widget->setDocument(null, null, 'Select a file');

        $lines = $this->plainLines($widget, 40, 5);
        $this->assertStringContainsString('Select a file', $lines[1]);
        $this->assertCount(5, $lines, 'pads to the full height');
    }

    public function testCursorMovementScrollFollowsAndClamps(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4); // a first frame teaches the widget its viewport height

        // Three rows visible; moving the cursor past the window scrolls it.
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('[scrolled +1]', $lines[0]);

        $widget->handleInput("\x1b[6~"); // page down clamps at the last row
        $widget->handleInput("\x1b[6~");
        $widget->handleInput("\x1b[6~");
        $this->assertStringContainsString('tail!', implode("\n", $this->plainLines($widget, 80, 4)));

        for ($i = 0; $i < 5; ++$i) {
            $widget->handleInput("\x1b[5~"); // page up back past the top clamps
        }
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('@@ -3,3', $lines[1], 'clamped back to the top');
        $this->assertStringNotContainsString('[scrolled', $lines[0]);
    }

    public function testSpaceOnAHunkHeaderSelectsTheWholeHunk(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });

        $widget->handleInput(' '); // cursor starts on hunk 0's header

        $this->assertSame([0 => [0 => true, 1 => true, 2 => true, 3 => true]], $captured);
    }

    public function testSpaceOnALineSelectsJustThatLine(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });

        $widget->handleInput("\x1b[B"); // rows: header, ctx, -old, +new...
        $widget->handleInput("\x1b[B");
        $widget->handleInput(' '); // on '-$old = 2;' (hunk 0, line 1)

        $this->assertSame([0 => [1 => true]], $captured);
    }

    public function testRangeAnchorSelectsTheSpanAndClears(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });

        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // on the deletion row
        $widget->handleInput('v');      // arm the anchor
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // down to '+extra'
        $widget->handleInput(' ');

        $this->assertSame([0 => [1 => true, 2 => true, 3 => true]], $captured, 'the ± rows in the range');

        // The anchor cleared: the next space is single-line again.
        $widget->handleInput(' ');
        $this->assertSame([0 => [3 => true]], $captured);
    }

    public function testCursorUpAndEmptyDocumentMovesAreSafe(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[A"); // back to the top
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput(' ');
        $this->assertArrayHasKey(0, (array) $captured, 'cursor is back on hunk 0');

        $empty = new DiffViewWidget();
        $empty->setDocument(null, null);
        $empty->setFocused(true);
        $empty->handleInput("\x1b[B"); // no rows: consumed, no crash
        $empty->handleInput(' ');
        $this->assertTrue(true);
    }

    public function testArmedRangeHighlightsRowsIncludingReversedAnchors(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 12);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput('v');      // anchor at row 2
        $widget->handleInput("\x1b[A"); // cursor row 1: reversed range [1..2]
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringContainsString("\x1b[48;5;236m", $raw[3], 'the anchor row (below the cursor) is range-highlighted');
    }

    public function testReadOnlyIgnoresStagingKeys(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $widget->setReadOnly(true);
        $fired = false;
        $widget->onStageSelection(function () use (&$fired): void {
            $fired = true;
        });

        $widget->handleInput('v');
        $widget->handleInput(' ');
        $this->assertFalse($fired);
    }

    public function testSamePathReloadKeepsTheCursor(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 12);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // cursor row 3

        // Reload the SAME path with a shorter diff: cursor clamps, not resets.
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n"), 'src/demo.php');
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true]], $captured, 'cursor clamped to the last row (+b)');

        // A DIFFERENT path resets to the top.
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n"), 'other.php');
        $widget->handleInput(' ');
        $this->assertSame([0 => [0 => true, 1 => true]], $captured, 'cursor back on the hunk header');
    }

    public function testTabAndEscapeFireTheirHooks(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $events = [];
        $widget->onFocusNext(function () use (&$events): void {
            $events[] = 'next';
        });
        $widget->onExit(function () use (&$events): void {
            $events[] = 'exit';
        });

        $widget->handleInput("\t");
        $widget->handleInput("\x1b");

        $this->assertSame(['next', 'exit'], $events);
    }

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4);
        $widget->onInput(static fn (string $data): bool => true);

        $widget->handleInput("\x1b[B"); // swallowed
        $this->assertStringNotContainsString('[scrolled', $this->plainLines($widget, 80, 4)[0]);
    }

    public function testReadOnlyFlagRoundTrips(): void
    {
        $widget = $this->widget();
        $this->assertFalse($widget->isReadOnly());
        $widget->setReadOnly(true);
        $this->assertTrue($widget->isReadOnly());
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded(), 'always fills its pane');
    }

    public function testNoNewlineMarkerRendersDim(): void
    {
        $widget = new DiffViewWidget();
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n"), 'x.txt');

        $lines = $this->plainLines($widget, 60, 6);
        $this->assertStringContainsString('No newline at end of file', $lines[3]);

        // In split mode the marker rides the old side of its own row.
        $widget->setSplit(true);
        $lines = $this->plainLines($widget, 60, 6);
        $this->assertMatchesRegularExpression('/No newline.*│/', $lines[3], 'the marker rides the old side (truncated to the half width)');
    }

    public function testSplitModeRendersPairsSideBySide(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $widget->handleInput('s');
        $this->assertTrue($widget->isSplit());

        $lines = $this->plainLines($widget);
        // The paired change sits on ONE row: old text left, new text right.
        $this->assertMatchesRegularExpression('/^\s+4 -\$old = 2;\s*│\s+4 \+\$new = 2;/', $lines[3]);
        // The surplus addition leaves the old side blank.
        $this->assertMatchesRegularExpression('/^\s{39}│\s+5 \+\$extra = 3;/', $lines[4]);
        // Context shows the same text on both sides.
        $this->assertMatchesRegularExpression('/\$keep = 1;.*│.*\$keep = 1;/', $lines[2]);

        $widget->handleInput('s'); // back to unified
        $this->assertFalse($widget->isSplit());
        $this->assertMatchesRegularExpression('/^\s+4\s+ │-\$old = 2;/', $this->plainLines($widget)[3]);
    }

    public function testSplitStagingSelectsThePairAndRangesUnionBothSides(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $widget->handleInput('s');
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });

        // Split rows: header, ctx pair, (-old|+new), (blank|+extra), header, pair.
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput(' '); // the pair row stages BOTH sides
        $this->assertSame([0 => [1 => true, 2 => true]], $captured);

        $widget->handleInput('v');
        $widget->handleInput("\x1b[B"); // include the surplus addition row
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true, 2 => true, 3 => true]], $captured, 'the range unions every side in it');
    }

    public function testToolbarTagTogglesByClickAndFiresThePersistenceHook(): void
    {
        $widget = $this->widget();
        $toggles = [];
        $widget->onLayoutToggle(function (bool $split) use (&$toggles): void {
            $toggles[] = $split;
        });
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringNotContainsString("\x1b[48;5;31m", $raw[0], 'inactive tag renders dim');

        // At 80 cols the tags start at col 58: ' s:split ' spans 58-66.
        $widget->handleClick(0, 60);
        $this->assertTrue($widget->isSplit());
        $this->assertSame([true], $toggles);
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringContainsString("\x1b[48;5;31m", $raw[0], 'active tag renders selected');

        $widget->handleClick(0, 5); // title text: not a button
        $this->assertTrue($widget->isSplit());

        $widget->setFocused(true);
        $widget->handleInput('s'); // keyboard path fires the hook too
        $this->assertSame([true, false], $toggles);
    }

    public function testBodyClickMovesTheCursor(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget); // teach the viewport
        $captured = null;
        $widget->onStageSelection(function (array $selected) use (&$captured): void {
            $captured = $selected;
        });

        $widget->handleClick(3, 10); // row 3 = the deletion (hunk 0 line 1)
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true]], $captured);

        $widget->handleClick(50, 10); // past the rows: clamps to the last row
        $widget->handleInput(' ');
        $this->assertSame([1 => [1 => true]], $captured, 'clamped to the final +tail! row');

        $empty = new DiffViewWidget();
        $empty->setDocument(null, null);
        $empty->handleClick(2, 2); // no rows: a safe no-op
        $this->assertFalse($empty->isSplit());
    }

    public function testReadOnlyStillAllowsTheLayoutToggle(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $widget->setReadOnly(true);
        $widget->handleInput('s');
        $this->assertTrue($widget->isSplit(), 'split is display-only, fine for history diffs');

        // setSplit applies a preference silently (no hook fire).
        $fired = false;
        $widget->onLayoutToggle(function () use (&$fired): void {
            $fired = true;
        });
        $widget->setSplit(false);
        $this->assertFalse($widget->isSplit());
        $widget->setSplit(false); // idempotent
        $this->assertFalse($fired);
    }

    public function testWhitespaceTagFiresTheHookAndMirrorsTheState(): void
    {
        $widget = $this->widget();
        $fired = 0;
        $widget->onToggleWhitespace(function () use (&$fired): void {
            ++$fired;
        });
        $widget->render(new RenderContext(80, 12));

        // ' w:hide-ws ' spans cols 68-78 at 80 cols.
        $widget->handleClick(0, 70);
        $this->assertSame(1, $fired);

        $widget->setFocused(true);
        $widget->setReadOnly(true);
        $widget->handleInput('w'); // allowed on read-only history diffs
        $this->assertSame(2, $fired);

        // The tag reflects the controller-owned state.
        $off = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringNotContainsString("\x1b[48;5;31m\x1b[38;5;231m w:hide-ws ", $off, 'inactive until the controller says so');
        $widget->setWhitespaceHidden(true);
        $widget->setWhitespaceHidden(true); // idempotent
        $on = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringContainsString("\x1b[48;5;31m\x1b[38;5;231m w:hide-ws ", $on, 'active tag renders selected');
    }
}
