<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

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
        $panel->open('Find in Project', withQuery: true);

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
        $panel->open('Find in Project', withQuery: true, withOptions: true);

        $panel->handleInput("\x1br"); // alt+r regex
        $panel->handleInput("\x1bc"); // alt+c case
        $panel->handleInput("\x1bw"); // alt+w word
        $this->assertSame(['regex', 'case', 'word'], $toggled);

        // Header shows the indicators; enabling one highlights it.
        $panel->setOptions(['regex' => true, 'case' => false, 'word' => false]);
        $header = $panel->render(new RenderContext(80, 12))[0];
        $plain = AnsiUtils::stripAnsiCodes($header);
        $this->assertStringContainsString('.*', $plain);
        $this->assertStringContainsString('Aa', $plain);
        $this->assertStringContainsString('\\b', $plain);
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
        $panel->open('Find in Project', withQuery: true); // withOptions defaults to false

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
        $panel->open('Find in Project', withQuery: true, withOptions: true, withReplace: true);

        // Typing goes to the query field first.
        $panel->handleInput('a');
        $this->assertSame(['a'], $queries);

        // Tab switches to the replace field; typing there does not re-query.
        $panel->handleInput("\t");
        $panel->handleInput('X');
        $panel->handleInput('Y');
        $panel->handleInput("\x7f"); // backspace in replace field
        $this->assertSame(['a'], $queries, 'replace typing is not a query');

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new RenderContext(80, 12))));
        $this->assertStringContainsString('Replace: X', $plain);

        // Alt+A fires replace-all with the field's contents.
        $panel->handleInput("\x1ba");
        $this->assertSame('X', $replaced);

        // Backspace on the query field after switching back still edits the query.
        $panel->handleInput("\t");
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
        $panel->open('Usages', withQuery: false);
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
        $panel->open('Usages', withQuery: false);
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
        $panel->open('Usages', withQuery: false);

        $panel->handleInput("\r");
        $this->assertFalse($jumped);
        $this->assertTrue($panel->isOpen());
    }

    public function testScrollWindowFollowsSelection(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', withQuery: true);
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
        $panel->open('Find in Project', withQuery: true);
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
        $panel->open('Usages', withQuery: false);
        $panel->setResults($this->results(2));

        $lines = $panel->render(new RenderContext(44, 12));
        foreach ($lines as $line) {
            $this->assertSame(44, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString("\x1b[48;5;238m", implode('', $lines), 'unfocused selection background');
    }

    public function testOnInputHookConsumes(): void
    {
        $panel = new ResultsPanelWidget();
        $queries = [];
        $panel->onQueryChanged(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $panel->open('X', withQuery: true);
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
        $panel->open('Usages', withQuery: false);
        $panel->setResults($this->results(3));
        $panel->render(new RenderContext(60, 12)); // establish geometry (scroll = 0)

        $panel->handleClick(2, 5); // header row 0 → result rows 1,2 → index 1
        $this->assertSame(['src/File1.php', 1], $jumped);
        $this->assertFalse($panel->isOpen());
    }

    public function testClickWithReplaceHeaderOffset(): void
    {
        $panel = new ResultsPanelWidget();
        $jumped = null;
        $panel->onJump(function (string $path, int $line) use (&$jumped): void {
            $jumped = [$path, $line];
        });
        $panel->open('Find in Project', withQuery: true, withReplace: true);
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
        $panel->open('Usages', withQuery: false);
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
        $panel->open('Find in Project', withQuery: true, withOptions: true);
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
        $panel->open('Usages', withQuery: false);
        $panel->setResults($this->results(5));
        $panel->render(new RenderContext(60, 12));

        $this->assertTrue($panel->hoverRow(3), 'row 3 → result index 2 selected');
        $this->assertFalse($panel->hoverRow(3), 'same row → no change');
        $this->assertFalse($panel->hoverRow(0), 'header row is not a result');
    }

    public function testSetVisibleRowsLimitsRenderedResultRows(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', withQuery: false);
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
        $panel->open('X', withQuery: true);

        $panel->handleInput("\x1b");
        $this->assertTrue($closed);
    }
}
