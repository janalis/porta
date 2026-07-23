<?php

declare(strict_types=1);

namespace Porta\Tests\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\OverlayHost;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

final class OverlayHostTest extends TestCase
{
    private int $invalidations = 0;

    private function host(): OverlayHost
    {
        $this->invalidations = 0;

        return new OverlayHost(function (): void {
            ++$this->invalidations;
        });
    }

    private function keybindings(): Keybindings
    {
        return new Keybindings([
            'select_cancel' => [Key::ESCAPE],
            'accept_completion' => [Key::ENTER],
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'recent_files' => ['ctrl+e'],
            'delete_char_backward' => [Key::BACKSPACE],
        ]);
    }

    /**
     * Lay the host out against a [0, 0, $rows, $columns] union and composite
     * the returned boxes onto a blank canvas — the presenter's job, replayed
     * here so the render/geometry assertions keep exercising the real rows.
     *
     * @return list<string>
     */
    private function canvas(OverlayHost $host, int $columns, int $rows): array
    {
        $lines = array_fill(0, $rows, str_repeat(' ', max(0, $columns)));
        foreach ($host->layout([0, 0, $rows, $columns]) as $box) {
            foreach ($box['rows'] as $i => $rowText) {
                $row = $box['top'] + $i;
                if ($row >= $rows) {
                    break;
                }
                $lines[$row] = OverlayCompositor::into($lines[$row], $box['col'], $box['width'], $columns, $rowText);
            }
        }

        return $lines;
    }

    public function testOpenListIgnoresAnEmptyList(): void
    {
        $host = $this->host();
        $host->openList('Empty', [], static fn () => null);
        $this->assertFalse($host->isListOpen());
        $this->assertSame(0, $this->invalidations);
    }

    public function testInputFallsThroughWhenNothingIsOpen(): void
    {
        $host = $this->host();
        $this->assertFalse($host->handleInput('x', $this->keybindings()));
        $this->assertFalse($host->clickAt(0, 0));
        $this->assertFalse($host->hoverAt(0, 0));
    }

    public function testPromptWinsOverTheListOverlay(): void
    {
        $host = $this->host();
        $picked = null;
        $submitted = null;
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->openPrompt('Rename to', 'old', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });

        // Enter submits the prompt, not the overlay pick.
        $this->assertTrue($host->handleInput("\r", $this->keybindings()));
        $this->assertSame('old', $submitted);
        $this->assertNull($picked);
        $this->assertTrue($host->isListOpen(), 'the list overlay survives the prompt');
    }

    public function testCloseListLeavesThePromptOpen(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);

        $host->closeList();
        $this->assertFalse($host->isListOpen());
        // The prompt still swallows and edits input.
        $this->assertTrue($host->handleInput('4', $this->keybindings()));
        $lines = $this->canvas($host, 40, 5);
        $this->assertStringContainsString('Go to line', AnsiUtils::stripAnsiCodes($lines[0]), 'the label is the frame title');
        $this->assertStringContainsString('4', AnsiUtils::stripAnsiCodes($lines[2]), 'the value on the field row, below the padding');
    }

    public function testPromptBackspaceAndCancel(): void
    {
        $host = $this->host();
        $submitted = null;
        $host->openPrompt('P', 'ab', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $kb = $this->keybindings();

        $host->handleInput("\x7f", $kb); // backspace → 'a'
        $host->handleInput("\x7f", $kb); // backspace → ''
        $host->handleInput("\x7f", $kb); // backspace on empty: no-op
        $host->handleInput("\x1b", $kb); // escape cancels without submitting
        $this->assertNull($submitted);
        $this->assertFalse($host->handleInput('x', $kb), 'prompt closed, input falls through');
    }

    public function testPromptClickIsInertWhenClosed(): void
    {
        $host = $this->host();
        $this->assertFalse($host->promptClickAt(0, 0), 'no prompt open → not consumed');
        $this->assertSame(0, $this->invalidations);
    }

    public function testPromptClickOnTheBoxIsSwallowedAndClickElsewhereDismisses(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 2, 40]); // framed box: rows 0-4, inner width 30 at col 3

        $this->assertTrue($host->promptClickAt(0, 5), 'a click on the frame title bar is consumed');
        $this->assertTrue($host->isPromptOpen(), 'and does not dismiss it — the caret stays');
        $this->assertTrue($host->promptClickAt(2, 5), 'a click on the field row is consumed');
        $this->assertTrue($host->isPromptOpen());

        $this->assertTrue($host->promptClickAt(5, 5), 'a click below the box is consumed');
        $this->assertFalse($host->isPromptOpen(), 'and dismisses it, like every sibling overlay');
    }

    public function testPromptClickBeyondTheBoxWidthDismisses(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 2, 40]);

        $this->assertTrue($host->promptClickAt(0, 37), 'row 0 but past the box width is outside');
        $this->assertFalse($host->isPromptOpen());
    }

    public function testThePromptCloseButtonDismisses(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 2, 40]); // box [4, 36): × at col 34

        $this->assertTrue($host->promptClickAt(0, 34), 'the title bar ×');
        $this->assertFalse($host->isPromptOpen());
    }

    /** Hovering a picker's × brightens it, exactly like the tab strips' close glyph. */
    public function testHoveringTheCloseBrightensItPerSurface(): void
    {
        $host = $this->host();
        $host->openList('Title', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $lines = $this->canvas($host, 80, 20);
        $box = new \ReflectionProperty($host, 'overlayBox')->getValue($host);
        \assert($box instanceof \Porta\Overlay\Region\ModalBox);
        $normalBar = $lines[$box->startRow];

        $this->assertTrue($host->hoverAt($box->startRow, $box->col + $box->width - 2), 'onto the ×');
        $hoveredBar = $this->canvas($host, 80, 20)[$box->startRow];
        $this->assertNotSame($normalBar, $hoveredBar, 'the × restyled under the pointer');
        $this->assertSame(AnsiUtils::stripAnsiCodes($normalBar), AnsiUtils::stripAnsiCodes($hoveredBar), 'same text, different shade');

        $this->assertTrue($host->hoverAt($box->startRow + 3, $box->col + 3), 'off the × again');
        $this->assertFalse($host->hoverAt($box->startRow + 3, $box->col + 3), 'no change → no repaint');
    }

    /** The prompt's only hover affordance is its ×. */
    public function testPromptHoverTracksTheClose(): void
    {
        $host = $this->host();
        $this->assertFalse($host->promptHoverAt(0, 0), 'no prompt open');

        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 8, 40]);
        $box = new \ReflectionProperty($host, 'promptBox')->getValue($host);
        \assert($box instanceof \Porta\Overlay\Region\ModalBox);

        $this->assertTrue($host->promptHoverAt($box->startRow, $box->col + $box->width - 2), 'onto the ×');
        $this->assertTrue($host->promptHoverAt($box->startRow + 2, $box->col + 2), 'off it');
        $this->assertFalse($host->promptHoverAt($box->startRow + 2, $box->col + 2), 'unchanged');
    }

    public function testTheListOverlayCloseButtonDismisses(): void
    {
        $host = $this->host();
        $host->openList('Title', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $host->layout([0, 0, 20, 80]);
        $box = new \ReflectionProperty($host, 'overlayBox')->getValue($host);
        \assert($box instanceof \Porta\Overlay\Region\ModalBox);

        $this->assertTrue($host->clickAt($box->startRow, $box->col + $box->width - 2), 'the title bar ×');
        $this->assertFalse($host->isListOpen());
    }

    public function testTheSearchModalCloseButtonCloses(): void
    {
        $host = $this->host();
        $closed = false;
        $host->openSearch('Search', static fn () => null, static fn () => null, static function () use (&$closed): void {
            $closed = true;
        });
        $host->layout([0, 0, 20, 80]);
        $box = new \ReflectionProperty($host, 'searchBox')->getValue($host);
        \assert($box instanceof \Porta\Overlay\Region\ModalBox);

        $this->assertTrue($host->searchClickAt($box->startRow, $box->col + $box->width - 2), 'the title bar ×');
        $this->assertTrue($closed);
    }

    public function testCompositeRecordsTheBoxForHitTesting(): void
    {
        $host = $this->host();
        $host->openList(
            'Title',
            [
                ['label' => 'a', 'hint' => '', 'value' => 'A'],
                ['label' => 'b', 'hint' => '', 'value' => 'B'],
            ],
            static fn () => null,
        );

        $host->layout([0, 0, 20, 80]);

        // Hovering the second item row moves the selection exactly once.
        $moved = false;
        for ($row = 0; $row < 20 && !$moved; ++$row) {
            $moved = $host->hoverAt($row, 40);
        }
        $this->assertTrue($moved, 'some row hover-selects the second item');
        $this->assertFalse($host->hoverAt($row - 1, 40), 'same-row hover is a no-op');
    }

    // --- Search Everywhere modal ---------------------------------------------

    private function hit(SearchCategory $category, string $label, \Closure $activate): SearchHit
    {
        return new SearchHit($category, $label, '', $activate);
    }

    /** @return list<SearchRow> Files: a, b · Actions: c — headers around hits. */
    private function searchRows(\Closure $onActivate): array
    {
        return [
            SearchRow::header(SearchCategory::Files),
            SearchRow::hit($this->hit(SearchCategory::Files, 'a', static function () use ($onActivate): void {
                $onActivate('a');
            })),
            SearchRow::hit($this->hit(SearchCategory::Files, 'b', static function () use ($onActivate): void {
                $onActivate('b');
            })),
            SearchRow::header(SearchCategory::Actions),
            SearchRow::hit($this->hit(SearchCategory::Actions, 'c', static function () use ($onActivate): void {
                $onActivate('c');
            })),
        ];
    }

    public function testSearchModalIsInertWhenClosed(): void
    {
        $host = $this->host();
        $host->handleSearchInput('x', $this->keybindings()); // no crash, no callbacks
        $host->setSearchRows([SearchRow::header(SearchCategory::Files)]); // ignored while closed
        $this->assertFalse($host->isSearchOpen());
        $this->assertFalse($host->searchClickAt(0, 0));
        $this->assertFalse($host->searchHoverAt(0, 0));
        $this->assertSame(0, $this->invalidations);
    }

    public function testOpenSearchAndTypeQueriesWithTheField(): void
    {
        $host = $this->host();
        $queries = [];
        $host->openSearch(
            'Search',
            static function (string $q) use (&$queries): void {
                $queries[] = $q;
            },
            static fn () => null,
            static fn () => null,
        );
        $this->assertTrue($host->isSearchOpen());

        $kb = $this->keybindings();
        $host->handleSearchInput('u', $kb);
        $host->handleSearchInput('i', $kb);
        $host->handleSearchInput("\x7f", $kb);
        $this->assertSame(['u', 'ui', 'u'], $queries);
    }

    public function testSearchModalShowsTheEmptyMessageForANoHitQuery(): void
    {
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null, 'no results');
        $host->setSearchRows([]); // a query that found nothing

        $lines = $this->canvas($host, 80, 20);
        $joined = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('no results', $joined, 'the no-hit filler row shows the empty message, like the sibling pickers');
    }

    public function testSetSearchRowsSelectsTheFirstHitSkippingTheHeader(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openSearch(
            'Search',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn () => null,
        );
        $host->setSearchRows($this->searchRows(static fn () => null));

        $host->handleSearchInput("\r", $this->keybindings()); // picks the selected hit
        $this->assertSame('a', $picked, 'selection lands on the first hit, not the header');
    }

    public function testArrowKeysMoveOverHitRowsOnlyAndWrap(): void
    {
        $host = $this->host();
        $picked = null;
        $onPick = static function (SearchHit $h) use (&$picked): void {
            $picked = $h->label;
        };
        $host->openSearch('Search', static fn () => null, $onPick, static fn () => null);
        $host->setSearchRows($this->searchRows(static fn () => null));
        $kb = $this->keybindings();

        $host->handleSearchInput("\x1b[B", $kb); // a → b
        $host->handleSearchInput("\x1b[B", $kb); // b → c (skips the Actions header)
        $host->handleSearchInput("\r", $kb);
        $this->assertSame('c', $picked);

        $host->handleSearchInput("\x1b[B", $kb); // c → a (wraps)
        $host->handleSearchInput("\x1b[A", $kb); // a → c (wraps back, skipping the header)
        $host->handleSearchInput("\r", $kb);
        $this->assertSame('c', $picked);
    }

    public function testEscapeClosesAndEnterOnAHeaderlessListIsANoOp(): void
    {
        $host = $this->host();
        $closed = 0;
        $picked = false;
        $host->openSearch(
            'Search',
            static fn () => null,
            static function () use (&$picked): void {
                $picked = true;
            },
            static function () use (&$closed): void {
                ++$closed;
            },
        );

        // A rows list with no hits: Enter finds no hit → no pick; Down stays put.
        $host->setSearchRows([SearchRow::header(SearchCategory::Files)]);
        $kb = $this->keybindings();
        $host->handleSearchInput("\x1b[B", $kb);
        $host->handleSearchInput("\r", $kb);
        $this->assertFalse($picked);

        $host->handleSearchInput("\x1b", $kb);
        $this->assertSame(1, $closed);
    }

    public function testSetSearchRowsKeepsAnExistingHitSelectionAndCloseSearchDismisses(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openSearch(
            'Search',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn () => null,
        );
        $host->setSearchRows($this->searchRows(static fn () => null));
        $host->handleSearchInput("\x1b[B", $this->keybindings()); // Down → second hit 'b'
        $host->setSearchRows($this->searchRows(static fn () => null)); // a refresh keeps the selection on 'b'
        $host->handleSearchInput("\r", $this->keybindings());
        $this->assertSame('b', $picked, 'a rows refresh keeps the current hit selected');

        $host->closeSearch();
        $this->assertFalse($host->isSearchOpen());
    }

    public function testSearchModalClipsWhenTheBoxIsTallerThanTheCanvas(): void
    {
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows($this->searchRows(static fn () => null));

        // A 3-line canvas cannot hold the box (title + query + rows) → the
        // compositor stops early rather than writing past the last line.
        $lines = $this->canvas($host, 80, 3);
        $this->assertCount(3, $lines);
    }

    public function testSearchRowsWithLongLabelsAndDetailsNeverExceedThePaneWidth(): void
    {
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        // A Text hit whose label (the matched line) and detail (a deep path:line)
        // are both far wider than the box — the exact case that overflowed.
        $host->setSearchRows([
            SearchRow::header(SearchCategory::Text),
            SearchRow::hit(new SearchHit(
                SearchCategory::Text,
                str_repeat('"ext-simplexml": "*", ', times: 8),
                'vendor/phpunit/phpunit/src/Framework/Constraint/Traversable/ArraySubset/DeeplyNested.php:1234',
                static fn () => null,
            )),
        ]);

        $lines = $this->canvas($host, 80, 20);
        foreach ($lines as $i => $line) {
            $this->assertSame(80, AnsiUtils::visibleWidth($line), "composited row {$i} must stay 80 columns");
        }
    }

    public function testSearchMouseClickPicksAHitAndClicksElsewhereClose(): void
    {
        $host = $this->host();
        $picked = null;
        $closed = 0;
        $host->openSearch(
            'Search',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static function () use (&$closed): void {
                ++$closed;
            },
        );
        $host->setSearchRows($this->searchRows(static fn () => null));
        $host->layout([0, 0, 20, 80]);

        // Hover finds a hit row; clicking it picks that hit.
        $hitRow = -1;
        for ($row = 0; $row < 20 && $hitRow < 0; ++$row) {
            if (!$host->searchHoverAt($row, 40)) {
                continue;
            }

            $hitRow = $row;
        }
        $this->assertGreaterThanOrEqual(0, $hitRow, 'a hit row is hoverable');
        $this->assertTrue($host->searchClickAt($hitRow, 40));
        $this->assertNotNull($picked);

        // Clicking above the box (row outside) and to the left of it (column
        // outside) both count as clicking away → dismiss.
        $this->assertTrue($host->searchClickAt(0, 40));
        $this->assertTrue($host->searchClickAt($hitRow, 0));
        $this->assertSame(2, $closed);
    }

    public function testSearchClickOnAHeaderRowCloses(): void
    {
        $host = $this->host();
        $closed = 0;
        $host->openSearch('Search', static fn () => null, static fn () => null, static function () use (&$closed): void {
            ++$closed;
        });
        $host->setSearchRows($this->searchRows(static fn () => null));
        $host->layout([0, 0, 20, 80]);

        // Move the selection off the top hit so its row becomes hover-selectable,
        // then the topmost hover-selectable row is the first hit — its category
        // header is the row directly above it.
        $host->handleSearchInput("\x1b[B", $this->keybindings()); // Down → second hit
        $topHitRow = -1;
        for ($row = 0; $row < 20 && $topHitRow < 0; ++$row) {
            if (!$host->searchHoverAt($row, 40)) {
                continue;
            }

            $topHitRow = $row;
        }
        $this->assertGreaterThan(0, $topHitRow);

        $host->searchClickAt($topHitRow - 1, 40); // click the category header → dismiss
        $this->assertSame(1, $closed);
    }

    public function testWheelMovesTheSearchSelectionSkippingHeaders(): void
    {
        $host = $this->host();
        $host->moveSearchSelection(1); // closed: a no-op
        $activated = 0;
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->moveSearchSelection(1); // no rows yet: a no-op
        $host->setSearchRows($this->searchRows(static function () use (&$activated): void {
            ++$activated;
        }));

        $before = $this->invalidations;
        $host->moveSearchSelection(1);
        $this->assertGreaterThan($before, $this->invalidations, 'the wheel moved the search selection');
    }

    public function testWheelWrapsTheListOverlaySelection(): void
    {
        $host = $this->host();
        $host->moveListSelection(1); // closed: a no-op
        $picked = null;
        $host->openList(
            'Recent',
            [
                ['label' => 'a', 'hint' => '', 'value' => 'A'],
                ['label' => 'b', 'hint' => '', 'value' => 'B'],
            ],
            static function (string $value) use (&$picked): void {
                $picked = $value;
            },
        );

        $host->moveListSelection(-1); // wraps from the first row to the last
        $host->handleInput("\r", $this->keybindings());
        $this->assertSame('B', $picked);
    }

    public function testThePromptKeepsItsCaretVisibleAndShowsTheEscHint(): void
    {
        $host = $this->host();
        // A value far longer than the box: a plain truncate would clip the
        // caret marker off the right edge and lose the hardware cursor.
        $host->openPrompt('Rename to', str_repeat('x', times: 80), static fn () => null);

        $lines = $this->canvas($host, 60, 24);
        $joined = implode("\n", $lines);

        $this->assertStringContainsString(AnsiUtils::CURSOR_MARKER_PREFIX, $joined, 'the caret marker survived the truncation');
        $this->assertStringContainsString('Esc', AnsiUtils::stripAnsiCodes($joined), 'the dismiss hint shows');
        $this->assertSame(str_repeat(' ', times: 60), $lines[0], 'the prompt is centered, not on the top row');
        foreach ($lines as $line) {
            $this->assertSame(60, AnsiUtils::visibleWidth($line), 'every row fills its width');
        }
    }

    public function testOnlyTheTopmostSurfaceRendersACaretMarker(): void
    {
        // The modal owns the caret wherever pane focus sits — but when the
        // prompt opens over the search modal, only the topmost surface (the
        // prompt) may embed the marker: two APC markers in one frame mangle
        // ScreenWriter's hardware-cursor conversion.
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->openPrompt('Go to line', '42', static fn () => null);

        $lines = $this->canvas($host, 60, 24);
        $joined = implode("\n", $lines);

        $this->assertSame(1, substr_count($joined, AnsiUtils::CURSOR_MARKER_PREFIX), 'exactly one caret marker — the prompt wins');
        $stripped = AnsiUtils::stripAnsiCodes($joined);
        $this->assertStringContainsString('Go to line', $stripped, 'the label titles the frame');
        $this->assertStringContainsString('42', $stripped, 'the value on the field row');
        $this->assertStringContainsString('Esc', $stripped);
    }

    public function testSearchHoverTintsAHitWithoutMovingTheSelection(): void
    {
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows($this->searchRows(static fn () => null));
        $host->layout([0, 0, 20, 80]);

        // Hover the second hit (rows: header, hit a [selected], hit b, header, hit c).
        $hitRow = -1;
        for ($row = 0; $row < 20; ++$row) {
            if (!$host->searchHoverAt($row, 40)) {
                continue;
            }

            $hitRow = $row;
            break;
        }
        $this->assertGreaterThanOrEqual(0, $hitRow, 'a hit row tinted');
        $body = implode("\n", $this->canvas($host, 80, 20));
        $this->assertStringContainsString("\x1b[48;5;31m", $body, 'the selected hit keeps its background');
        $this->assertStringContainsString("\x1b[48;5;236m", $body, 'the hovered hit takes the tint, not the selection');
    }
}
