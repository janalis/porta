<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ScopeKind;
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
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
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

    public function testSearchOptionTogglesFireAndRender(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $toggled = [];
        $panel->onToggleOption(function (string $name) use (&$toggled): void {
            $toggled[] = $name;
        });
        $panel->open('Find in Project', new ResultsPanelFields(query: true, options: true));

        $panel->handleInput("\x1br"); // alt+r regex
        $panel->handleInput("\x1bc"); // alt+c case
        $panel->handleInput("\x1bw"); // alt+w word
        $panel->handleInput("\x1bp"); // alt+p preserve case
        $this->assertSame(['regex', 'case', 'word', 'preserve'], $toggled);

        // Header shows the indicators; enabling one highlights it.
        $panel->setOptions(['regex' => true, 'case' => false, 'word' => false, 'preserve' => false]);
        $header = $panel->render(new RenderContext(80, 12))[0];
        $plain = AnsiUtils::stripAnsiCodes($header);
        $this->assertStringContainsString('.*', $plain);
        $this->assertStringContainsString('Aa', $plain);
        $this->assertStringContainsString('\\b', $plain);
        $this->assertStringContainsString('Pc', $plain, 'preserve-case tag shown');
        $this->assertStringContainsString("\x1b[48;5;31m", $header, 'enabled option highlighted');
    }

    public function testOptionTogglesIgnoredWithoutOptionsMode(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $fired = false;
        $panel->onToggleOption(function () use (&$fired): void {
            $fired = true;
        });
        $panel->open('Find in Project', ResultsPanelFields::queryOnly()); // withOptions defaults to false

        $panel->handleInput("\x1br"); // alt+r has no effect here
        $this->assertFalse($fired);
        $header = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(80, 12))[0]);
        $this->assertStringNotContainsString('.*', $header, 'no option indicators without options mode');
    }

    public function testReplaceFieldSwitchingTypingAndReplaceAll(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $queries = [];
        $replaced = null;
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->onReplaceAll(function (string $r) use (&$replaced): void {
            $replaced = $r;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());

        // Typing goes to the query field first.
        $panel->handleInput('a');
        $this->assertSame(['a'], $queries);

        // Tab cycles query → mask → filter → replace; land on replace. Typing there does not re-query.
        $panel->handleInput("\t"); // mask
        $panel->handleInput("\t"); // filter
        $panel->handleInput("\t"); // replace
        $panel->handleInput('X');
        $panel->handleInput('Y');
        $panel->handleInput("\x7f"); // backspace in replace field
        $this->assertSame(['a'], $queries, 'replace typing is not a query');

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('Replace: X', $plain);

        // Alt+A fires replace-all with the field's contents.
        $panel->handleInput("\x1ba");
        $this->assertSame('X', $replaced);

        // Tab from replace wraps back to the query; backspace still edits the query.
        $panel->handleInput("\t"); // query (wrap)
        $panel->handleInput("\x7f");
        $this->assertSame(['a', ''], $queries);
    }

    public function testResultsOnlyModeIgnoresTyping(): void
    {
        $panel = new ResultsPanelWidget();
        $queries = [];
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));

        $panel->handleInput('x');
        $this->assertSame([], $queries);
    }

    public function testJumpOnSelectedResultClosesAndNotifies(): void
    {
        $panel = new ResultsPanelWidget();
        $jumped = null;
        $panel->onJump(function (string $path, int $line) use (&$jumped): void {
            $jumped = [$path, $line];
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(3));

        $panel->handleInput("\x1b[B"); // down to second
        $panel->handleInput("\r");

        $this->assertSame(['src/File1.php', 1], $jumped);
        $this->assertFalse($panel->isOpen());
    }

    public function testJumpWithNoResultsIsNoOp(): void
    {
        $panel = new ResultsPanelWidget();
        $jumped = false;
        $panel->onJump(function () use (&$jumped): void {
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
        $this->assertStringContainsString("\x1b[48;5;238m", implode('', $lines), 'unfocused selection background');
    }

    public function testSearchResultRowHighlightsMatchRangesWithoutChangingWidth(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
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
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
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
        $panel->onJump(function (string $path, int $line) use (&$jumped): void {
            $jumped = [$path, $line];
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(3));
        $panel->render(new RenderContext(60, 12)); // establish geometry (scroll = 0)

        $panel->handleClick(2, 5); // header row 0 → result rows 1,2 → index 1
        $this->assertSame(['src/File1.php', 1], $jumped);
        $this->assertFalse($panel->isOpen());
    }

    public function testScopeSelectorCyclesFiresAndRendersTheKind(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $kinds = [];
        $panel->onScopeCycle(function (ScopeKind $k) use (&$kinds): void {
            $kinds[] = $k;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $plain = fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));

        $this->assertStringContainsString('Scope: Whole Project', $plain(), 'defaults to the whole project');

        $panel->handleInput("\x1bs"); // alt+s → Directory
        $this->assertStringContainsString('Scope: Directory', $plain());

        $panel->handleInput("\x1bs"); // → Current File
        $panel->handleInput("\x1bs"); // → Open Files
        $this->assertStringContainsString('Scope: Open Files', $plain());

        $panel->handleInput("\x1bs"); // → Whole Project (wraps)
        $this->assertSame([ScopeKind::Directory, ScopeKind::CurrentFile, ScopeKind::OpenFiles, ScopeKind::Project], $kinds);
        $this->assertStringContainsString('Scope: Whole Project', $plain());
    }

    public function testScopeKindCanBePreset(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setScopeKind(ScopeKind::Directory);

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('Scope: Directory', $plain);
    }

    public function testContextLinesExpandCollapseAndAreInert(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->setContextProvider(static fn (string $path, int $line): array => [
            ['line' => $line - 1, 'text' => 'above line'],
            ['line' => $line + 1, 'text' => 'below line'],
        ]);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults([['path' => 'src/A.php', 'line' => 4, 'text' => 'the match', 'ranges' => []]]);
        $plain = fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(120, 20))));

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

    public function testGroupByDirectoryTogglesHeadersAndLabels(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ['path' => 'other/C.php', 'line' => 2, 'text' => 'foo three', 'ranges' => []],
        ]);
        $plain = fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(120, 20))));

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

        $panel->handleInput("\x1bg"); // alt+g → back to grouping by file
        $this->assertStringContainsString('src/A.php', $plain(), 'toggles back to file grouping');
    }

    public function testMaskValueSeededAndHistoryRecalled(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $masks = [];
        $panel->onMaskChanged(function (string $m) use (&$masks): void {
            $masks[] = $m;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setMaskValue('*.php');
        $panel->setMaskHistory(['*.twig', '*.js']);
        $plain = fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));

        $this->assertStringContainsString('Mask: *.php', $plain(), 'the persisted mask is restored');

        $panel->handleInput("\t"); // query → mask
        $panel->handleInput("\x1b[1;3A"); // alt+up → older mask
        $this->assertStringContainsString('Mask: *.twig', $plain());
        $panel->handleInput("\x1b[1;3A"); // alt+up → the oldest mask
        $this->assertStringContainsString('Mask: *.js', $plain());
        $panel->handleInput("\x1b[1;3A"); // alt+up past the end → no change
        $this->assertStringContainsString('Mask: *.js', $plain());
        $panel->handleInput("\x1b[1;3B"); // alt+down → back to the newer mask
        $this->assertStringContainsString('Mask: *.twig', $plain());

        $this->assertSame(['*.twig', '*.js', '*.twig'], $masks, 'each recall re-runs the mask');
    }

    public function testMaskFieldIsTabReachableAndNotifiesOnEdit(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $masks = [];
        $queries = [];
        $panel->onMaskChanged(function (string $m) use (&$masks): void {
            $masks[] = $m;
        });
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());

        // Tab query → mask; typing fires onMaskChanged (not a query), backspace too.
        $panel->handleInput("\t"); // mask
        $panel->handleInput('*');
        $panel->handleInput('x');
        $panel->handleInput("\x7f"); // backspace
        $this->assertSame(['*', '*x', '*'], $masks);
        $this->assertSame([], $queries, 'mask edits are not queries');

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('Mask: *', $plain);
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

    public function testGroupedRenderShowsFileHeadersWithCountsAndIndentedMatches(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
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
        $panel->onJump(function (string $path, int $line) use (&$jumped): void {
            $jumped = [$path, $line];
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults($this->groupedResults());

        // The first match (not the file header) is pre-selected → Enter jumps.
        $panel->handleInput("\r");
        $this->assertSame(['src/A.php', 0], $jumped);
    }

    public function testGroupedClickFileHeaderTogglesCollapse(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults($this->groupedResults());
        $panel->render(new RenderContext(80, 12));

        // Header rows 0-4; row 5 = file header src/A.php. Click it → collapse.
        $panel->handleClick(5, 3);
        $collapsed = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▸ src/A.php  (2)', $collapsed, 'collapsed arrow');
        $this->assertStringNotContainsString('match zero', $collapsed, "A's matches are hidden");
        $this->assertStringNotContainsString('match one', $collapsed);
        $this->assertStringContainsString('match two', $collapsed, 'B stays expanded');

        // Click again → expand.
        $panel->handleClick(5, 3);
        $expanded = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('▾ src/A.php', $expanded);
        $this->assertStringContainsString('match zero', $expanded);
    }

    public function testToggleAllGroupsCollapsesThenExpandsEveryGroup(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults($this->groupedResults());
        $render = fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));

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

    public function testHistoryRecallCyclesQueriesWithAltArrows(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $queries = [];
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setHistory(['recent', 'older']);
        $shown = fn (): string => AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(80, 12))[0]);

        $panel->handleInput("\x1b[1;3A"); // alt+up → 'recent'
        $this->assertStringContainsString('recent', $shown());
        $panel->handleInput("\x1b[1;3A"); // alt+up → 'older'
        $this->assertStringContainsString('older', $shown());
        $panel->handleInput("\x1b[1;3A"); // alt+up at the end → no change
        $this->assertStringContainsString('older', $shown());
        $panel->handleInput("\x1b[1;3B"); // alt+down → 'recent'
        $panel->handleInput("\x1b[1;3B"); // alt+down past the newest → '' (in-progress)
        $this->assertSame(['recent', 'older', 'recent', ''], $queries);

        // Typing leaves history-browsing; alt+up then recalls the newest again.
        $panel->handleInput('z');
        $panel->handleInput("\x1b[1;3A");
        $this->assertStringContainsString('recent', $shown());
    }

    public function testRerunKeyFiresTheCallback(): void
    {
        $panel = new ResultsPanelWidget();
        $reran = 0;
        $panel->onRerun(function () use (&$reran): void {
            ++$reran;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());

        $panel->handleInput("\x1b[15~"); // F5
        $this->assertSame(1, $reran);
    }

    public function testFilterFieldNarrowsResultsWithoutRegrepAndShowsShownCount(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $queries = [];
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults([
            ['path' => 'src/Api.php', 'line' => 0, 'text' => 'match here', 'ranges' => []],
            ['path' => 'src/Web.php', 'line' => 1, 'text' => 'other', 'ranges' => []],
        ]);

        // Tab query → mask → filter, then narrow.
        $panel->handleInput("\t"); // mask
        $panel->handleInput("\t"); // filter
        foreach (str_split('Api') as $ch) {
            $panel->handleInput($ch);
        }

        $this->assertSame([], $queries, 'filtering does not re-run the search');
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(90, 16))));
        $this->assertStringContainsString('Filter: Api', $plain);
        $this->assertStringContainsString('· 1 shown', $plain, 'the filtered count is shown');
        $this->assertStringContainsString('src/Api.php', $plain);
        $this->assertStringNotContainsString('src/Web.php', $plain, 'the non-matching file is hidden');
    }

    public function testClickWithMaskAndReplaceHeaderOffset(): void
    {
        $panel = new ResultsPanelWidget();
        $jumped = null;
        $panel->onJump(function (string $path, int $line) use (&$jumped): void {
            $jumped = [$path, $line];
        });
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $panel->setResults($this->results(3));
        $panel->render(new RenderContext(80, 12));

        // Header rows 0-4 (title/query, scope, mask, filter, replace); grouped
        // display then begins with a file header at row 5, its match at row 6.
        $panel->handleClick(6, 5);
        $this->assertSame(['src/File0.php', 0], $jumped);
    }

    public function testClickWithReplaceHeaderOffset(): void
    {
        $panel = new ResultsPanelWidget();
        $jumped = null;
        $panel->onJump(function (string $path, int $line) use (&$jumped): void {
            $jumped = [$path, $line];
        });
        $panel->open('Find in Project', new ResultsPanelFields(query: true, replace: true));
        $panel->setResults($this->results(3));
        $panel->render(new RenderContext(80, 12));

        // Header row 0 + replace row 1 → results begin at row 2.
        $panel->handleClick(2, 5); // → index 0
        $this->assertSame(['src/File0.php', 0], $jumped);
    }

    public function testClickHeaderAndBelowResultsAreNoOps(): void
    {
        $panel = new ResultsPanelWidget();
        $jumped = false;
        $panel->onJump(function () use (&$jumped): void {
            $jumped = true;
        });
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $panel->setResults($this->results(2));
        $panel->render(new RenderContext(60, 12));

        $panel->handleClick(0, 5); // header (no option tags) → nothing
        $panel->handleClick(9, 5); // past the last result → nothing
        $this->assertFalse($jumped);
        $this->assertTrue($panel->isOpen());
    }

    public function testClickOptionTagToggles(): void
    {
        $panel = new ResultsPanelWidget();
        $toggled = [];
        $panel->onToggleOption(function (string $name) use (&$toggled): void {
            $toggled[] = $name;
        });
        $panel->open('Find in Project', new ResultsPanelFields(query: true, options: true));
        $panel->render(new RenderContext(80, 12));

        // Unfocused header ' Find in Project: ' (18) + ' 0 results' (10) = col 28;
        // the two-space gap puts the ' .* ' / ' Aa ' / ' \b ' tags at 30/34/38.
        $panel->handleClick(0, 31); // regex  [30,34)
        $panel->handleClick(0, 35); // case   [34,38)
        $panel->handleClick(0, 39); // word   [38,42)
        $panel->handleClick(0, 5); // title area → no toggle
        $this->assertSame(['regex', 'case', 'word'], $toggled);
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
        $panel->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $panel->open('X', ResultsPanelFields::queryOnly());

        $panel->handleInput("\x1b");
        $this->assertTrue($closed);
    }

    public function testLongQueryAndReplacementKeepTheCaretVisible(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::fullSearch());
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        foreach (str_split(str_repeat('q', 100)) as $ch) {
            $panel->handleInput($ch); // overflow the query in the header
        }
        $header = $panel->render(new RenderContext(80, 12))[0];
        $this->assertSame(1, substr_count($header, $marker), 'query caret stays visible');
        $this->assertSame(80, AnsiUtils::visibleWidth($header));

        $panel->handleInput("\t"); // query → mask
        $panel->handleInput("\t"); // mask → filter
        $panel->handleInput("\t"); // filter → replace
        foreach (str_split(str_repeat('r', 100)) as $ch) {
            $panel->handleInput($ch);
        }
        // Rows: 0 title/query, 1 scope, 2 mask, 3 filter, 4 replace.
        $replaceLine = $panel->render(new RenderContext(80, 12))[4];
        $this->assertSame(1, substr_count($replaceLine, $marker), 'replace caret stays visible');
        $this->assertSame(80, AnsiUtils::visibleWidth($replaceLine));
    }
}
