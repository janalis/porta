<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
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

    public function testQueryModeTypingNotifiesAndRenders(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $queries = [];
        $panel->onQueryChanged(static function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Find in Project', ResultsPanelFields::queryOnly());

        $panel->handleInput('a');
        $panel->handleInput('b');
        $panel->handleInput("\x7f"); // backspace
        $panel->handleInput("\x7f");
        $panel->handleInput("\x7f"); // backspace on empty: no notification

        $this->assertSame(['a', 'ab', 'a', ''], $queries);

        $panel->setSearching();
        $line = $panel->render(new RenderContext(60, 12))[0];
        $this->assertStringContainsString('searching…', AnsiUtils::stripAnsiCodes($line));
    }

    public function testResultsOnlyModeIgnoresTyping(): void
    {
        $panel = new ResultsPanelWidget();
        $queries = [];
        $panel->onQueryChanged(static function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));

        $panel->handleInput('x');
        $this->assertSame([], $queries);
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
        $panel->open('Find in Project', ResultsPanelFields::queryOnly());
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

    public function testNoResultsRowShownForNonEmptyQuery(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::queryOnly());
        $panel->handleInput('q');
        $panel->setResults([]);

        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(60, 12))));
        $this->assertStringContainsString('no results', $plain);
        $this->assertStringContainsString('0 results', $plain);
    }

    public function testUnfocusedSelectionStyleAndExactWidths(): void
    {
        $panel = new ResultsPanelWidget();
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
        $queries = [];
        $panel->onQueryChanged(static function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('X', ResultsPanelFields::queryOnly());
        $panel->onInput(static fn (string $data): bool => 'Z' === $data);

        $panel->handleInput('Z');
        $this->assertSame([], $queries);
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

        $panel->handleClick(2); // header row 0 → result rows 1,2 → index 1
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

        $panel->handleClick(0); // header row → nothing
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

        $this->assertTrue($panel->hoverRow(3), 'row 3 → result index 2 selected');
        $this->assertFalse($panel->hoverRow(3), 'same row → no change');
        $this->assertFalse($panel->hoverRow(0), 'header row is not a result');
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
        $panel->open('X', ResultsPanelFields::queryOnly());

        $panel->handleInput("\x1b");
        $this->assertTrue($closed);
    }

    public function testShiftEnterInsertsANewlineInTheQuery(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::queryOnly());
        $panel->handleInput('a');
        $panel->handleInput("\x1b[13;2u"); // shift+enter → newline
        $panel->handleInput('b');

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('a⏎b', $plain, 'the multiline query renders the newline as ⏎');
    }

    public function testLongQueryKeepsTheCaretVisible(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::queryOnly());
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        foreach (str_split(str_repeat('q', times: 100)) as $ch) {
            $panel->handleInput($ch); // overflow the query in the header
        }
        $header = $panel->render(new RenderContext(80, 12))[0];
        $this->assertSame(1, substr_count($header, $marker), 'query caret stays visible');
        $this->assertSame(80, AnsiUtils::visibleWidth($header));
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

    public function testExportPinAndPinIndicator(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $exported = 0;
        $pinned = 0;
        $panel->onExport(static function () use (&$exported): void {
            ++$exported;
        });
        $panel->onPin(static function () use (&$pinned): void {
            ++$pinned;
        });
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults($this->groupedResults());

        $panel->handleInput("\x1bx"); // alt+x → export
        $panel->handleInput("\x1bk"); // alt+k → pin
        $this->assertSame(1, $exported);
        $this->assertSame(1, $pinned);

        // The controller reflects the pinned label back into the header.
        $panel->setPinIndicator('needle');
        $header = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(80, 12))[0]);
        $this->assertStringContainsString('pinned: needle', $header);
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
        $this->assertStringContainsString('· 1 shown', $plain, 'the filtered count is shown');
        $this->assertStringContainsString('src/Api.php', $plain);
        $this->assertStringNotContainsString('src/Web.php', $plain, 'the non-matching file is hidden');
    }

    public function testSwitchFieldCyclesBetweenQueryAndFilter(): void
    {
        // A mode with both a query and a filter: Tab cycles between the two.
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $queries = [];
        $panel->onQueryChanged(static function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Find in Project', new ResultsPanelFields(query: true, filter: true));
        $panel->setResults([
            ['path' => 'src/Api.php', 'line' => 0, 'text' => 'match here', 'ranges' => []],
            ['path' => 'src/Web.php', 'line' => 1, 'text' => 'other', 'ranges' => []],
        ]);

        // Typing starts in the query.
        $panel->handleInput('a');
        $this->assertSame(['a'], $queries);

        // Tab → filter; typing there narrows client-side, not a re-query.
        $panel->handleInput("\t");
        foreach (str_split('Api') as $ch) {
            $panel->handleInput($ch);
        }
        $this->assertSame(['a'], $queries, 'filter typing is not a query');
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(90, 16))));
        $this->assertStringContainsString('Filter: Api', $plain);
        $this->assertStringContainsString('src/Api.php', $plain);
        $this->assertStringNotContainsString('src/Web.php', $plain);

        // Tab wraps back to the query; backspace still edits it.
        $panel->handleInput("\t");
        $panel->handleInput("\x7f");
        $this->assertSame(['a', ''], $queries);
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

        // Row 0 title, row 1 filter → grouped rows begin at row 2 (the src/A.php header).
        $panel->handleClick(2);
        $collapsed = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▸ src/A.php  (2)', $collapsed, 'collapsed arrow');
        $this->assertStringNotContainsString('match zero', $collapsed, "A's matches are hidden");
        $this->assertStringNotContainsString('match one', $collapsed);
        $this->assertStringContainsString('match two', $collapsed, 'B stays expanded');

        // Click again → expand.
        $panel->handleClick(2);
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
