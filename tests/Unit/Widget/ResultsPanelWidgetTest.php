<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\AttachedStyles;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
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
        $panel = new ResultsPanelWidget();
        $jumped = null;
        $panel->onJump(static function (string $path, int $line) use (&$jumped): void {
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
        $panel = new ResultsPanelWidget();
        $jumped = false;
        $panel->onJump(static function () use (&$jumped): void {
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
        $panel = new ResultsPanelWidget();
        $jumped = null;
        $panel->onJump(static function (string $path, int $line) use (&$jumped): void {
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
        $panel = new ResultsPanelWidget();
        $jumped = false;
        $panel->onJump(static function () use (&$jumped): void {
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
        $panel = new ResultsPanelWidget();
        $reran = 0;
        $panel->onRerun(static function () use (&$reran): void {
            ++$reran;
        });
        $panel->open('Find in Project', ResultsPanelFields::searchResults());

        $panel->handleInput("\x1b[15~"); // F5
        $this->assertSame(1, $reran);
    }

    public function testExportKeyFiresTheCallback(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $exported = 0;
        $panel->onExport(static function () use (&$exported): void {
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
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $jumped = null;
        $panel->onJump(static function (string $path, int $line) use (&$jumped): void {
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
}
