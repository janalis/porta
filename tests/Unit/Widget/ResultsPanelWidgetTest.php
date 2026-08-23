<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\ResultRowActivated;
use Porta\Event\ResultsExportRequested;
use Porta\Event\ResultsRerunRequested;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;

final class ResultsPanelWidgetTest extends TestCase
{
    /** @return list<array{path: string, line: int, text: string}> */
    private function results(int $count): array
    {
        return array_map(
            static fn (int $i) => ['path' => "src/File{$i}.php", 'line' => $i, 'text' => "match {$i}"],
            range(0, $count - 1),
        );
    }

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function groupedResults(): array
    {
        return [
            ['path' => 'src/A.php', 'line' => 0, 'text' => 'match zero', 'ranges' => []],
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'match one', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 2, 'text' => 'match two', 'ranges' => []],
        ];
    }

    public function testClosedRendersNothingAndIgnoresInput(): void
    {
        $panel = new ResultsPanelWidget();
        $this->assertSame([], $panel->render(new RenderContext(80, 12)));
        $panel->handleInput("\r");
        $panel->close(); // no-op
        $this->assertFalse($panel->isOpen());
    }

    public function testSearchingStatusIsCarriedByTheStripStatus(): void
    {
        // List tools show their status on the dock strip, not a header row.
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setSearching();
        $this->assertStringContainsString('searching…', $panel->statusText());
    }

    public function testResultsOnlyModeIgnoresTyping(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));

        $panel->handleInput('x'); // no input field to edit — a no-op
        $this->assertTrue($panel->isOpen());
    }

    public function testJumpOnSelectedResultNotifiesAndStaysOpen(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $jumped = null;
        $events->addListener(ResultRowActivated::class, static function (ResultRowActivated $event) use (&$jumped): void {
            [$path, $line] = [$event->relativePath, $event->line];
            $jumped = [$path, $line];
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(3));

        $panel->handleInput("\x1b[B"); // down to second
        $panel->handleInput("\r");

        $this->assertSame(['src/File1.php', 1], $jumped);
        $this->assertTrue($panel->isOpen(), 'the pane stays open after a jump');
    }

    public function testJumpWithNoResultsIsNoOp(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $jumped = false;
        $events->addListener(ResultRowActivated::class, static function () use (&$jumped): void {
            $jumped = true;
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());

        $panel->handleInput("\r");
        $this->assertFalse($jumped);
        $this->assertTrue($panel->isOpen());
    }

    public function testScrollWindowFollowsSelection(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(25));

        for ($i = 0; $i < 15; ++$i) {
            $panel->handleInput("\x1b[B");
        }
        $lines = $panel->render(new RenderContext(60, 12));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('File15.php:16', $plain, 'selection scrolled into view');

        for ($i = 0; $i < 20; ++$i) {
            $panel->handleInput("\x1b[A"); // up, clamped at 0
        }
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(60, 12))));
        $this->assertStringContainsString('File0.php:1', $plain);
    }

    public function testScrollByWithTheWheelMovesThroughResults(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->results(25));

        $panel->scrollBy(100); // wheel down past the end → clamps at the last row
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(60, 12))));
        $this->assertStringContainsString('File24.php', $plain, 'scrolled to the bottom');

        $panel->scrollBy(-100); // wheel up past the top → clamps at the first row
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(60, 12))));
        $this->assertStringContainsString('File0.php', $plain, 'scrolled back to the top');
    }

    public function testUnfocusedSelectionStyleAndExactWidths(): void
    {
        $panel = new ResultsPanelWidget();
        AttachedStyles::attach($panel);
        $panel->setFocused(false);
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));

        $lines = $panel->render(new RenderContext(44, 12));
        foreach ($lines as $line) {
            $this->assertSame(44, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString("\x1b[48;5;237m", implode('', $lines), 'unfocused selection background');
    }

    public function testSearchResultRowHighlightsMatchRangesWithoutChangingWidth(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'the needle here', 'ranges' => [['start' => 4, 'length' => 6]]],
        ]);

        $lines = $panel->render(new RenderContext(60, 12));
        foreach ($lines as $line) {
            $this->assertSame(60, AnsiUtils::visibleWidth($line), 'the highlight only inserts zero-width SGR');
        }
        $body = implode("\n", $lines);
        $this->assertStringContainsString("\x1b[48;5;58m", $body, 'the matched span carries the SearchBg background');
        $this->assertStringContainsString('needle', AnsiUtils::stripAnsiCodes($body), 'the match text is still shown');
    }

    /** Exact flat-row layout: leading space, then `path:line`, then the gutter, then the text. */
    public function testFlatMatchRowHasExactLayout(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::resultsOnly());
        $panel->setResults([['path' => 'src/A.php', 'line' => 4, 'text' => 'the needle here']]);

        $row = rtrim(AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(60, 4))[0]));

        $this->assertSame(' src/A.php:5  the needle here', $row);
    }

    /** A flat (ungrouped) row's search-range highlight must land at the exact `path:line  ` prefix width. */
    public function testFlatMatchHighlightLandsAtThePrefixWidthOffset(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::resultsOnly());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'the needle here', 'ranges' => [['start' => 4, 'length' => 6]]],
        ]);

        $raw = $panel->render(new RenderContext(60, 4))[0];

        // prefixWidth = 1 (leading space) + strwidth('src/A.php:5') (11) + 2 (gutter) = 14; range start 4 → column 18.
        $this->assertStringNotContainsString('48;5;58', AnsiUtils::sliceByColumn($raw, 17, 1, strict: true), 'column 17 is not tinted');
        $this->assertStringContainsString('48;5;58', AnsiUtils::sliceByColumn($raw, 18, 1, strict: true), 'column 18 ("n" of needle) is tinted');
    }

    /** A location-less row (log tools) starts its text with a leading space, not the reverse. */
    public function testLocationlessRowLeadsWithASpaceBeforeTheText(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Problems', ResultsPanelFields::resultsOnly());
        $panel->setResults([['path' => '', 'line' => 0, 'text' => 'a diagnostic message']]);

        $row = rtrim(AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(60, 4))[0]));

        $this->assertSame(' a diagnostic message', $row);
    }

    /** The location-less row's highlight prefix is exactly 1 (the leading space), not 0 or 2. */
    public function testLocationlessRowHighlightLandsRightAfterTheLeadingSpace(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Problems', ResultsPanelFields::resultsOnly());
        $panel->setResults([['path' => '', 'line' => 0, 'text' => 'needle here', 'ranges' => [['start' => 0, 'length' => 6]]]]);

        $raw = $panel->render(new RenderContext(60, 4))[0];

        $this->assertStringNotContainsString('48;5;58', AnsiUtils::sliceByColumn($raw, 0, 1, strict: true), 'column 0 (the leading space) is not tinted');
        $this->assertStringContainsString('48;5;58', AnsiUtils::sliceByColumn($raw, 1, 1, strict: true), 'column 1 ("n" of needle) is tinted');
    }

    public function testResultsWithoutRangesRenderUnhighlighted(): void
    {
        // Non-search modes provide no `ranges` key → no SearchBg, no crash.
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));

        $body = implode('', $panel->render(new RenderContext(60, 12)));
        $this->assertStringNotContainsString("\x1b[48;5;58m", $body, 'no highlight without ranges');
    }

    public function testOnInputHookConsumes(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('X', ResultsPanelFields::searchResults());
        $panel->onInput(static fn (string $data): bool => 'Z' === $data);

        $panel->handleInput('Z'); // consumed by the hook, never reaches the filter
        $panel->handleInput('a'); // the filter takes this
        $plain = implode('', array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(60, 12))));
        $this->assertStringContainsString('Filter: a', $plain, 'the filter took the un-hooked key');
        $this->assertStringNotContainsString('Z', $plain, 'the hooked key never reached the filter');
    }

    public function testVisibleEmptyPanelReservesItsFullHeight(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly()); // no filter, no results
        $panel->setVisibleRows(6);

        $lines = $panel->render(new RenderContext(60, 20));
        $this->assertCount(6, $lines, 'an empty results tool reserves its configured height so the dock stays resizable');
        foreach ($lines as $line) {
            $this->assertSame(60, AnsiUtils::visibleWidth($line), 'each reserved row spans the full width');
        }
    }

    public function testFilterFieldShowsACaretOnlyWhenFocused(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('X', ResultsPanelFields::searchResults()); // a filter row
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $panel->setFocused(true);
        $focused = implode('', $panel->render(new RenderContext(60, 12)));
        $this->assertStringContainsString($marker, $focused, 'the focused filter field shows its caret');

        $panel->setFocused(false);
        $blurred = implode('', $panel->render(new RenderContext(60, 12)));
        $this->assertStringNotContainsString($marker, $blurred, 'a blurred filter field hides the caret');
    }

    public function testClickResultRowSelectsAndJumps(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $jumped = null;
        $events->addListener(ResultRowActivated::class, static function (ResultRowActivated $event) use (&$jumped): void {
            [$path, $line] = [$event->relativePath, $event->line];
            $jumped = [$path, $line];
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(3));
        $panel->render(new RenderContext(60, 12)); // establish geometry (scroll = 0)

        $panel->handleClick(1); // no header rows: result rows start at 0 → index 1
        $this->assertSame(['src/File1.php', 1], $jumped);
        $this->assertTrue($panel->isOpen(), 'the pane stays open after a jump');
    }

    public function testClickHeaderAndBelowResultsAreNoOps(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $jumped = false;
        $events->addListener(ResultRowActivated::class, static function () use (&$jumped): void {
            $jumped = true;
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));
        $panel->render(new RenderContext(60, 12));

        $panel->handleClick(-1); // above the panel → nothing
        $panel->handleClick(9); // past the last result → nothing
        $this->assertFalse($jumped);
        $this->assertTrue($panel->isOpen());
    }

    public function testHoverRowTracksPointer(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(5));
        $panel->render(new RenderContext(60, 12));

        $this->assertTrue($panel->hoverRow(2), 'row 2 → result index 2 tinted (no header rows)');
        $this->assertFalse($panel->hoverRow(2), 'same row → no change');
        $this->assertTrue($panel->hoverRow(-1), 'above the panel is no row: the tint clears, so the caller repaints');
        $this->assertFalse($panel->hoverRow(-1), 'still no row → no change');
        $this->assertTrue($panel->hoverRow(2), 'back onto a row');
        $this->assertTrue($panel->clearHover(), 'the pointer left the panel');
        $this->assertFalse($panel->clearHover(), 'nothing left to clear');
    }

    public function testHoverTintsTheRowWithoutMovingTheSelection(): void
    {
        $panel = new ResultsPanelWidget();
        AttachedStyles::attach($panel);
        $panel->setFocused(true);
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(5));
        $panel->render(new RenderContext(60, 12));

        $panel->hoverRow(2); // the pointer rests on the third result
        $lines = $panel->render(new RenderContext(60, 12));

        // Enter still jumps to the first result: hover lit row 2, it did not select it.
        $this->assertStringContainsString("\x1b[48;5;31m", $lines[0], 'the selection stays on the first result');
        $this->assertStringContainsString("\x1b[48;5;236m", $lines[2], 'the hovered row carries the hover tint, not the selection');
    }

    public function testRebuildingTheRowsDropsAStaleHoverTint(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(5));
        $panel->render(new RenderContext(60, 12));
        $panel->hoverRow(2);

        // A fresh result set moves the rows under a pointer that has not moved.
        $panel->setResults($this->results(5));
        $lines = $panel->render(new RenderContext(60, 12));

        $this->assertStringNotContainsString("\x1b[48;5;236m", implode("\n", $lines), 'no row stays tinted after the list is rebuilt');
    }

    public function testDefaultVisibleRowsIsExactlyTen(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(15));

        $lines = $panel->render(new RenderContext(60, 24));
        $resultRows = array_filter($lines, static fn (string $l) => str_contains(AnsiUtils::stripAnsiCodes($l), '.php:'));
        $this->assertCount(10, $resultRows, 'the un-configured default is exactly 10 rows');
    }

    /** Reopening a panel that already has state must reset it, not carry stale filter/selection/rows over. */
    public function testOpenResetsFilterSelectionAndSearchingFromAPriorSession(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput('x'); // types into the filter
        $panel->setSearching();
        $panel->handleInput("\x1b[B"); // move off row 0

        $panel->open('Find in Project', ResultsPanelFields::searchResults()); // reopen: everything above must reset

        $this->assertSame('', Probe::get($panel, 'filter')->value());
        $this->assertFalse(Probe::get($panel, 'searching'));
        $lines = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringNotContainsString('match zero', $lines, 'the stale results are gone — setResults([]) via open()');
    }

    public function testSetVisibleRowsLimitsRenderedResultRows(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(10));

        $panel->setVisibleRows(3);
        $lines = $panel->render(new RenderContext(60, 24));
        $resultRows = array_filter($lines, static fn (string $l) => str_contains(AnsiUtils::stripAnsiCodes($l), '.php:'));
        $this->assertCount(3, $resultRows, 'only the resized number of result rows render');
    }

    public function testCloseNotifies(): void
    {
        $panel = new ResultsPanelWidget();
        $closed = false;
        $panel->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $panel->open('X', ResultsPanelFields::resultsOnly());

        $panel->handleInput("\x1b");
        $this->assertTrue($closed);
    }

    public function testRerunKeyFiresTheCallback(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $reran = 0;
        $events->addListener(ResultsRerunRequested::class, static function () use (&$reran): void {
            ++$reran;
        });
        $panel->open('Find in Project', ResultsPanelFields::searchResults());

        $panel->handleInput("\x1b[15~"); // F5
        $this->assertSame(1, $reran);
    }

    public function testExportKeyFiresTheCallback(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $panel->setFocused(true);
        $exported = 0;
        $events->addListener(ResultsExportRequested::class, static function () use (&$exported): void {
            ++$exported;
        });
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());

        $panel->handleInput("\x1bx"); // alt+x → export
        $this->assertSame(1, $exported);
    }

    public function testFilterFieldNarrowsResultsWithoutRegrepAndShowsShownCount(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/Api.php', 'line' => 0, 'text' => 'match here', 'ranges' => []],
            ['path' => 'src/Web.php', 'line' => 1, 'text' => 'other', 'ranges' => []],
        ]);

        // The results window opens focused on the filter field — typing narrows.
        foreach (str_split('Api') as $ch) {
            $panel->handleInput($ch);
        }

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(90, 16))));
        $this->assertStringContainsString('Filter: Api', $plain);
        $this->assertStringContainsString('· 1 shown', $panel->statusText(), 'the filtered count rides the dock-strip status');
        $this->assertStringContainsString('src/Api.php', $plain);
        $this->assertStringNotContainsString('src/Web.php', $plain, 'the non-matching file is hidden');

        // Backspacing the filter widens the view again; a backspace on the now
        // empty field is a no-op.
        foreach (['', '', '', ''] as $_) {
            $panel->handleInput("\x7f");
        }
        $widened = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(90, 16))));
        $this->assertStringContainsString('src/Web.php', $widened, 'clearing the filter restores the hidden file');
    }

    public function testGroupedRenderShowsFileHeadersWithCountsAndIndentedMatches(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▾ src/A.php  (2)', $plain, 'file header with match count');
        $this->assertStringContainsString('▾ src/B.php  (1)', $plain);
        $this->assertStringContainsString('match zero', $plain);
        $this->assertStringNotContainsString('src/A.php:1', $plain, 'grouped match rows omit the per-line path');
    }

    public function testGroupedEnterOnThePreselectedMatchJumps(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $panel->setFocused(true);
        $jumped = null;
        $events->addListener(ResultRowActivated::class, static function (ResultRowActivated $event) use (&$jumped): void {
            [$path, $line] = [$event->relativePath, $event->line];
            $jumped = [$path, $line];
        });
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());

        // The first match (not the file header) is pre-selected → Enter jumps.
        $panel->handleInput("\r");
        $this->assertSame(['src/A.php', 0], $jumped);
    }

    public function testGroupedClickFileHeaderTogglesCollapse(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->render(new RenderContext(80, 12));

        // Row 0 is the filter (the tool title lives on the dock strip) →
        // grouped rows begin at row 1 (the src/A.php header).
        $panel->handleClick(1);
        $collapsed = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▸ src/A.php  (2)', $collapsed, 'collapsed arrow');
        $this->assertStringNotContainsString('match zero', $collapsed, "A's matches are hidden");
        $this->assertStringNotContainsString('match one', $collapsed);
        $this->assertStringContainsString('match two', $collapsed, 'B stays expanded');

        // Click again → expand.
        $panel->handleClick(1);
        $expanded = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▾ src/A.php', $expanded);
        $this->assertStringContainsString('match zero', $expanded);
    }

    public function testToggleAllGroupsCollapsesThenExpandsEveryGroup(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $render = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));

        // Move the selection to the last match so collapse clamps it.
        for ($i = 0; $i < 6; ++$i) {
            $panel->handleInput("\x1b[B");
        }
        $panel->handleInput("\x1be"); // alt+e → collapse all (some were expanded)
        $collapsed = $render();
        $this->assertStringContainsString('▸ src/A.php', $collapsed, 'both groups collapsed');
        $this->assertStringContainsString('▸ src/B.php', $collapsed);
        $this->assertStringNotContainsString('match zero', $collapsed, 'matches hidden');
        $this->assertStringNotContainsString('match two', $collapsed);

        $panel->handleInput("\x1be"); // alt+e again → expand all (all were collapsed)
        $expanded = $render();
        $this->assertStringContainsString('▾ src/A.php', $expanded);
        $this->assertStringContainsString('match zero', $expanded);
        $this->assertStringContainsString('match two', $expanded);
    }

    public function testGroupByDirectoryTogglesHeadersAndLabels(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ['path' => 'other/C.php', 'line' => 2, 'text' => 'foo three', 'ranges' => []],
        ]);
        $plain = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(120, 20))));

        // Default: one header per file.
        $byFile = $plain();
        $this->assertStringContainsString('src/A.php', $byFile);
        $this->assertStringContainsString('src/B.php', $byFile);

        $panel->handleInput("\x1bg"); // alt+g → group by directory
        $byDir = $plain();
        $this->assertStringContainsString('src  (2)', $byDir, 'src/ groups its two files');
        $this->assertStringContainsString('other  (1)', $byDir);
        $this->assertStringContainsString('A.php:5', $byDir, 'directory rows show basename:line');
        $this->assertStringContainsString('B.php:10', $byDir);

        $panel->handleInput("\x1bg"); // alt+g → nested directory → file → match
        $nested = $plain();
        $this->assertStringContainsString('src/  (2)', $nested, 'nested directory header');
        $this->assertStringContainsString('other/  (1)', $nested);
        $this->assertStringContainsString('A.php  (1)', $nested, 'nested file header shows the basename');
        $this->assertStringContainsString('B.php  (1)', $nested);

        $panel->handleInput("\x1bg"); // alt+g → back to grouping by file (cycle wraps)
        $this->assertStringContainsString('src/A.php', $plain(), 'wraps back to file grouping');
    }

    public function testNestedGroupingCollapsesDirectoriesAndFilesIndependently(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
            ['path' => 'src/A.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ['path' => 'other/C.php', 'line' => 2, 'text' => 'foo three', 'ranges' => []],
        ]);
        $plain = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(120, 20))));

        $panel->handleInput("\x1bg"); // → Directory
        $panel->handleInput("\x1bg"); // → Nested
        $this->assertStringContainsString('src/  (2)', $plain(), 'directory counts its files\' matches');
        $this->assertStringContainsString('foo one', $plain());

        // Land deterministically on the top row (the src/ directory header).
        for ($i = 0; $i < 6; ++$i) {
            $panel->handleInput("\x1b[A"); // up (clamps at row 0)
        }

        // Collapse the directory — its whole subtree (file header + matches) disappears.
        $panel->handleInput("\r");
        $collapsedDir = $plain();
        $this->assertStringContainsString('src/  (2)', $collapsedDir, 'directory header still shows its count');
        $this->assertStringNotContainsString('A.php', $collapsedDir, 'the file header is hidden under the collapsed directory');
        $this->assertStringContainsString('other/', $collapsedDir, 'sibling directories are unaffected');

        // Re-expand the directory, then collapse just its file header.
        $panel->handleInput("\r"); // expand src/
        $panel->handleInput("\x1b[B"); // down → the src/A.php file header
        $panel->handleInput("\r"); // collapse the file
        $collapsedFile = $plain();
        $this->assertStringContainsString('A.php', $collapsedFile, 'the file header remains');
        $this->assertStringNotContainsString('foo one', $collapsedFile, 'the file\'s matches are hidden');
    }

    public function testNestedToggleAllCollapsesAndExpandsEveryLevel(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 2, 'text' => 'foo two', 'ranges' => []],
        ]);
        $plain = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(120, 20))));

        $panel->handleInput("\x1bg"); // → Directory
        $panel->handleInput("\x1bg"); // → Nested

        // Alt+E collapses every level (directories and files); again expands them.
        $panel->handleInput("\x1be");
        $this->assertStringNotContainsString('foo one', $plain(), 'collapse-all hides matches at both levels');
        $panel->handleInput("\x1be");
        $this->assertStringContainsString('foo one', $plain(), 'expand-all restores them');
    }

    public function testContextLinesExpandCollapseAndAreInert(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->setContextProvider(static fn (string $path, int $line): array => [
            ['line' => $line - 1, 'text' => 'above line'],
            ['line' => $line + 1, 'text' => 'below line'],
        ]);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([['path' => 'src/A.php', 'line' => 4, 'text' => 'the match', 'ranges' => []]]);
        $plain = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(120, 20))));

        $this->assertStringNotContainsString('above line', $plain(), 'no context until expanded');

        $panel->handleInput("\x1bm"); // alt+m on the auto-selected match → expand
        $expanded = $plain();
        $this->assertStringContainsString('above line', $expanded);
        $this->assertStringContainsString('below line', $expanded);

        $panel->handleInput("\x1b[B"); // down → onto a context row
        $panel->handleInput("\r"); // enter → inert (no jump/close)
        $this->assertTrue($panel->isOpen(), 'context rows are inert');

        $panel->handleInput("\x1b[A"); // up → back to the match row
        $panel->handleInput("\x1bm"); // alt+m → collapse
        $this->assertStringNotContainsString('above line', $plain(), 'context collapses');

        $panel->handleInput("\x1b[A"); // up → the file header
        $panel->handleInput("\x1bm"); // alt+m on a header → no-op
        $this->assertStringNotContainsString('above line', $plain());
    }

    public function testCaptureAndRestoreRoundTripsFilterCollapseAndSelection(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());

        // Shape some view state: a filter that keeps every row, selection on A:4.
        $panel->handleInput("\x1b[B"); // A:0 -> A:4
        $panel->handleInput('match'); // every fixture row matches, so nothing narrows
        $state = $panel->captureViewState();
        $this->assertSame('match', $state->filter);
        $this->assertSame('src/A.php', $state->selectedPath);

        // Rebind to something else, then back — the dock's switch sequence.
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->restoreViewState($state);

        $restored = $panel->captureViewState();
        $this->assertSame('match', $restored->filter, 'the filter text survives the round trip');
        $this->assertSame('src/A.php', $restored->selectedPath, 'the selection survives by identity');
        $this->assertSame(4, $restored->selectedLine);
    }

    public function testRestoreRelocatesTheSelectionAfterRowsChangedWhileParked(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput("\x1b[B");
        $panel->handleInput("\x1b[B");
        $panel->handleInput("\x1b[B"); // A:0 -> A:4 -> B header -> B:2
        $state = $panel->captureViewState();
        $this->assertSame('src/B.php', $state->selectedPath);

        // The tab's rows were refreshed while parked: a new match prepended.
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/New.php', 'line' => 9, 'text' => 'fresh', 'ranges' => []],
            ...$this->groupedResults(),
        ]);
        $panel->restoreViewState($state);

        $jumped = null;
        $events->addListener(ResultRowActivated::class, static function (ResultRowActivated $event) use (&$jumped): void {
            [$path, $line] = [$event->relativePath, $event->line];
            $jumped = [$path, $line];
        });
        $panel->handleInput("\r");
        $this->assertSame(['src/B.php', 2], $jumped, 'identity relocation found the same match at its new position');
    }

    public function testRestoreFallsBackToGroupKeyThenClampedIndex(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        // Select the A header (row 0), collapse it.
        $panel->handleInput("\x1b[A");
        $panel->handleInput("\r");
        $state = $panel->captureViewState();
        $this->assertSame('src/A.php', $state->selectedGroupKey, 'a header selection stores its group key');
        $this->assertSame(['src/A.php' => true], $state->collapsed);

        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->restoreViewState($state);
        $rendered = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▸', $rendered, 'the collapse state survived');

        // A state whose identities all vanished clamps to the stored index.
        $gone = $panel->captureViewState();
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([['path' => 'src/Z.php', 'line' => 1, 'text' => 'other', 'ranges' => []]]);
        $panel->restoreViewState($gone);
        $this->assertTrue($panel->isOpen(), 'a fully-mismatched restore is safe');
    }

    public function testContextStateSurvivesTheRoundTripByIdentity(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setContextProvider(static fn (string $path, int $line): array => [['line' => $line + 1, 'text' => 'ctx of '.$path]]);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput("\x1bm"); // alt+m expands context on the selected match (A:0)

        $state = $panel->captureViewState();
        $this->assertArrayHasKey("src/A.php\x000", $state->contextExpanded, 'context keys are (path, line) identities');

        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->restoreViewState($state);
        $rendered = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(80, 14))));
        $this->assertStringContainsString('ctx of src/A.php', $rendered, 'the expanded context re-mapped to the same match');

        // An identity that no longer matches any result drops silently.
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([['path' => 'src/Z.php', 'line' => 7, 'text' => 'other', 'ranges' => []]]);
        $panel->restoreViewState($state);
        $rendered = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(80, 14))));
        $this->assertStringNotContainsString('ctx of', $rendered);
    }

    public function testRefreshResultsPreservesSelectionCollapseAndFilter(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Problems', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput("\x1b[B"); // selection A:0 -> A:4
        $panel->handleInput('match'); // keeps every row

        // A refresh prepends a new diagnostic; the user's place must hold.
        $panel->refreshResults([
            ['path' => 'src/New.php', 'line' => 9, 'text' => 'match fresh', 'ranges' => []],
            ...$this->groupedResults(),
        ]);

        $state = $panel->captureViewState();
        $this->assertSame('src/A.php', $state->selectedPath, 'the selected diagnostic survived the refresh');
        $this->assertSame(4, $state->selectedLine);
        $this->assertSame('match', $state->filter, 'the filter survived');
    }

    public function testRefreshResultsPreservesCollapse(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Problems', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput("\x1b[A"); // A:0 -> the A header
        $panel->handleInput("\r"); // collapse it

        $panel->refreshResults($this->groupedResults());
        $this->assertSame(['src/A.php' => true], $panel->captureViewState()->collapsed, 'collapse survived the refresh');
    }

    public function testRefreshResultsReselectsNewestForLocationlessRows(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Notifications', ResultsPanelFields::resultsOnly());
        $panel->setResults([['path' => '', 'line' => 0, 'text' => 'older']]);
        $panel->handleInput("\x1b[B"); // wander (no-op on one row, but selection is 'located' at index 0)

        $panel->refreshResults([
            ['path' => '', 'line' => 0, 'text' => 'newest'],
            ['path' => '', 'line' => 0, 'text' => 'older'],
        ]);
        $rendered = array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(80, 6)));
        $this->assertStringContainsString('newest', $rendered[0] ?? '', 'log refresh keeps the latest line on top and selected');
    }

    /** A header selection (groupKey only, no path/line) must relocate via the group key, not fall back to selectFirstMatch. */
    public function testRefreshResultsRelocatesAHeaderSelectionByGroupKey(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput("\x1b[B"); // A:0 -> A:4
        $panel->handleInput("\x1b[B"); // -> B header

        $panel->refreshResults([
            ['path' => 'src/New.php', 'line' => 9, 'text' => 'fresh', 'ranges' => []],
            ...$this->groupedResults(),
        ]);

        $this->assertSame('src/B.php', $panel->captureViewState()->selectedGroupKey, 'the header selection relocated by its group key');
    }

    /** With no prior identity to relocate (a fresh single-row set, selection not already at 0), refresh must select the first match, not leave the old index untouched. */
    public function testRefreshResultsSelectsFirstMatchWhenNothingToRelocate(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Notifications', ResultsPanelFields::resultsOnly());
        $panel->setResults([
            ['path' => '', 'line' => 0, 'text' => 'a'],
            ['path' => '', 'line' => 0, 'text' => 'b'],
            ['path' => '', 'line' => 0, 'text' => 'c'],
        ]);
        $panel->handleInput("\x1b[B"); // move off row 0

        // A location-less refresh with a wholly different row count/content: no
        // (path,line) or groupKey identity to relocate by at all.
        $panel->refreshResults([['path' => '', 'line' => 0, 'text' => 'only one now']]);

        $this->assertSame(0, $panel->captureViewState()->selectedIndex, 'selection lands on the first (only) match, not wherever it happened to be');
    }

    public function testRefreshResultsClampsWhenTheSelectedIdentityVanished(): void
    {
        $events = new EventDispatcher();
        $panel = new ResultsPanelWidget(events: $events);
        $panel->open('Problems', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());
        $panel->handleInput("\x1b[B");
        $panel->handleInput("\x1b[B");
        $panel->handleInput("\x1b[B"); // B:2

        $panel->refreshResults([['path' => 'src/C.php', 'line' => 5, 'text' => 'only one left', 'ranges' => []]]);
        $jumped = null;
        $events->addListener(ResultRowActivated::class, static function (ResultRowActivated $event) use (&$jumped): void {
            [$path, $line] = [$event->relativePath, $event->line];
            $jumped = [$path, $line];
        });
        $panel->handleInput("\r");
        $this->assertSame(['src/C.php', 5], $jumped, 'a vanished identity clamps onto a real match');
    }

    /**
     * Exact row layout in the nested view: indent, then arrow, then count —
     * catches any reordering/dropping among the header's concatenated pieces,
     * which a loose "contains" check on the file name alone would miss.
     */
    public function testNestedHeaderAndMatchRowsHaveExactLayout(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
        ]);
        $panel->handleInput("\x1bg"); // → Directory
        $panel->handleInput("\x1bg"); // → Nested

        $raw = $panel->render(new RenderContext(30, 4));
        $rows = array_map(static fn (string $r): string => rtrim(AnsiUtils::stripAnsiCodes($r)), $raw);

        $this->assertStringStartsWith("\x1b[48;5;235m", $raw[1], 'the row background opens first, before any content');
        $this->assertStringEndsWith("\x1b[0m", $raw[1], 'and resets last, after all the content');
        $this->assertSame(' ▾ src/  (1)', $rows[1], 'directory header: no indent, arrow right after the leading space');
        $this->assertSame('   ▾ A.php  (1)', $rows[2], 'nested file header: two-space indent, THEN the arrow');
        $this->assertSame('      5  foo one', $rows[3], 'nested match row: six-space indent, THEN the label, THEN the gutter');
    }

    /** A grouped match row's search-range highlight must land on the prefix's exact width, not one column off. */
    public function testGroupedMatchHighlightLandsAtThePrefixWidthOffset(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'needle here', 'ranges' => [['start' => 0, 'length' => 6]]],
        ]);

        $raw = $panel->render(new RenderContext(30, 4))[2]; // the match row, under the file header

        // prefixWidth = 4 (matchIndent) + 1 (label "5") + 2 (gutter) = 7.
        $this->assertStringNotContainsString('48;5;58', AnsiUtils::sliceByColumn($raw, 6, 1, strict: true), 'column 6 (the gutter) is not tinted');
        $this->assertStringContainsString('48;5;58', AnsiUtils::sliceByColumn($raw, 7, 1, strict: true), 'column 7 ("n" of needle) is tinted');
    }
}
