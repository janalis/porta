<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Tests\Support\DrivesOverlayHost;
use Porta\Tests\Support\Probe;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * How a picker is walked and edited: the caret keys of its fields, paging,
 * the category keys, the frame's ▲/▼ cues, and a selection and a box that
 * hold still while results stream in.
 */
final class OverlayHostNavigationTest extends TestCase
{
    use DrivesOverlayHost;

    /** A rename's typo mid-word is fixed where it is: the prompt's caret moves, and Delete and Ctrl+W edit at it. */
    public function testThePromptCaretMovesAndEditsInPlace(): void
    {
        $host = $this->host();
        $submitted = null;
        $host->openPrompt('Rename', 'fooBar', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $kb = $this->editingKeys();

        $host->handleInput("\x1b[H", $kb); // Home
        $host->handleInput("\x1b[3~", $kb); // Delete: the f
        $host->handleInput('g', $kb);
        $this->clearRepaint();
        $host->handleInput("\x1b[C", $kb); // →
        $this->assertTrue($this->repainted(), 'a caret move repaints');
        $host->handleInput("\x1b[F", $kb); // End
        $host->handleInput("\x1b[D", $kb); // ←
        $host->handleInput('x', $kb);
        $host->handleInput("\r", $kb);

        $this->assertSame('gooBaxr', $submitted);
    }

    /** The list filter takes the same caret keys; a Delete that changes the filter narrows afresh from the top. */
    public function testTheListFilterCaretEditsAndRefilters(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openList(
            'T',
            [['label' => 'alpha', 'hint' => '', 'value' => 'A'], ['label' => 'beta', 'hint' => '', 'value' => 'B'], ['label' => 'bet', 'hint' => '', 'value' => 'C']],
            static function (string $v) use (&$picked): void {
                $picked = $v;
            },
        );
        $kb = $this->editingKeys();
        foreach (['z', 'b', 'e'] as $key) {
            $host->handleInput($key, $kb);
        }
        $this->assertStringContainsString('no matches', \implode("\n", $this->canvas($host, 80, 24)), 'zbe matches nothing');

        $host->handleInput("\x1b[H", $kb); // Home — even with nothing matching, the caret moves
        $host->handleInput("\x1b[3~", $kb); // Delete the z
        $host->handleInput("\x1b[B", $kb); // ↓ to the second match
        $host->handleInput("\r", $kb);

        $this->assertSame('C', $picked, 'be matches beta and bet; the second was picked');
    }

    /** PgUp/PgDn move a box's worth of rows and stop at the ends, in the list and in the search modal. */
    public function testPagingMovesABoxfulAndStopsAtTheEnds(): void
    {
        $host = $this->host();
        $items = \array_map(static fn (int $i): array => ['label' => "item {$i}", 'hint' => '', 'value' => (string) $i], \range(0, end: 29));
        $picked = null;
        $host->openList('T', $items, static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->layout([0, 0, 24, 80]);
        $kb = $this->editingKeys();
        $page = Probe::get($host, 'overlayBox')->count;
        $this->assertGreaterThan(1, $page);

        $host->handleInput("\x1b[6~", $kb);
        $this->assertSame($page, Probe::get($host, 'overlay')['selected']);
        foreach (\range(1, end: 5) as $_) {
            $host->handleInput("\x1b[6~", $kb);
        }
        $this->assertSame(29, Probe::get($host, 'overlay')['selected'], 'stops on the last row, no wrap');
        $host->handleInput("\x1b[5~", $kb);
        $this->assertSame(29 - $page, Probe::get($host, 'overlay')['selected']);
        $host->closeList();

        $host->openSearch('S', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $host->setSearchRows($this->searchRows(static fn (): null => null));
        $host->layout([0, 0, 24, 80]);
        $host->handleSearchInput("\x1b[6~", $kb);
        $this->assertSame(4, Probe::get($host, 'search')->selected, 'past the Actions header to the last hit');
        $host->handleSearchInput("\x1b[5~", $kb);
        $this->assertSame(1, Probe::get($host, 'search')->selected, 'back to the first hit, never a header');
    }

    /** Tab and Shift+Tab land on the first hit of the next and previous category, wrapping. */
    public function testTabWalksTheCategories(): void
    {
        $host = $this->host();
        $host->openSearch('S', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $host->setSearchRows($this->searchRows(static fn (): null => null));
        $kb = $this->editingKeys();
        $host->handleSearchInput("\x1b[B", $kb); // on b, still in Files

        $host->handleSearchInput("\t", $kb);
        $this->assertSame(4, Probe::get($host, 'search')->selected, 'Actions: c');
        $host->handleSearchInput("\t", $kb);
        $this->assertSame(1, Probe::get($host, 'search')->selected, 'wraps to Files: a');
        $host->handleSearchInput("\x1b[Z", $kb);
        $this->assertSame(4, Probe::get($host, 'search')->selected, 'Shift+Tab wraps back to Actions');
        $host->handleSearchInput("\x1b[Z", $kb);
        $this->assertSame(1, Probe::get($host, 'search')->selected);
        $this->assertSame('', Probe::get($host, 'search')->query->value(), 'Tab typed nothing');

        $host->setSearchRows($this->consecutiveHits(3)); // no categories to walk
        $host->handleSearchInput("\x1b[B", $kb);
        $host->handleSearchInput("\t", $kb);
        $this->assertSame(1, Probe::get($host, 'search')->selected, 'Tab stays put');
    }

    /** The query's caret keys: a move repaints only; a Delete or Ctrl+W that changes the query asks again. */
    public function testTheSearchQueryCaretKeysEditAndRequery(): void
    {
        $host = $this->host();
        $queries = [];
        $host->openSearch(
            'S',
            static function (string $q) use (&$queries): void {
                $queries[] = $q;
            },
            static fn (): null => null,
            static fn (): null => null,
        );
        $kb = $this->editingKeys();
        foreach (['a', 'b', 'c'] as $key) {
            $host->handleSearchInput($key, $kb);
        }
        $host->handleSearchInput("\x1b[H", $kb); // Home: no new query
        $this->assertSame(['a', 'ab', 'abc'], $queries);

        $host->handleSearchInput("\x1b[3~", $kb); // Delete the a
        $host->handleSearchInput("\x1b[F", $kb);
        $host->handleSearchInput("\x17", $kb); // Ctrl+W: the word before the caret

        $this->assertSame(['a', 'ab', 'abc', 'bc', ''], $queries);
    }

    /**
     * Rows for the query already answered keep the selected HIT where it moved
     * to; rows for a new query select its best match. A slow source landing
     * above the reader's choice used to leave Enter on a different row.
     */
    public function testStreamedRowsKeepTheChosenHitAndANewQueryResets(): void
    {
        $host = $this->host();
        $host->openSearch('S', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $noop = static fn (): null => null;
        $host->setSearchRows([SearchRow::header('Files'), SearchRow::hit($this->hit(SearchCategory::Files, 'a', $noop)), SearchRow::hit($this->hit(SearchCategory::Files, 'b', $noop))]);
        $host->handleSearchInput("\x1b[B", $this->editingKeys()); // b

        $host->setSearchRows([
            SearchRow::header('Classes'),
            SearchRow::hit($this->hit(SearchCategory::Classes, 'K', $noop)),
            SearchRow::header('Files'),
            SearchRow::hit($this->hit(SearchCategory::Files, 'a', $noop)),
            SearchRow::hit($this->hit(SearchCategory::Files, 'b', $noop)),
        ]);
        $this->assertSame(4, Probe::get($host, 'search')->selected, 'still b, three rows further down');

        $host->handleSearchInput('x', $this->editingKeys()); // a new query
        $host->setSearchRows([SearchRow::header('Files'), SearchRow::hit($this->hit(SearchCategory::Files, 'a', $noop)), SearchRow::hit($this->hit(SearchCategory::Files, 'b', $noop))]);
        $this->assertSame(1, Probe::get($host, 'search')->selected, 'a new query starts on its best match');
    }

    /** The box is as wide as the widest row seen since it opened: narrower answers do not shrink it. */
    public function testTheSearchBoxHoldsItsWidthAsTheQueryNarrows(): void
    {
        $host = $this->host();
        $host->openSearch('S', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $noop = static fn (): null => null;
        $host->setSearchRows([SearchRow::header('Files'), SearchRow::hit(new SearchHit(SearchCategory::Files, \str_repeat('w', times: 40), 'dir', $noop))]);
        $wide = $host->layout([0, 0, 24, 100])[0]['width'];

        $host->setSearchRows([SearchRow::header('Files'), SearchRow::hit($this->hit(SearchCategory::Files, 'a', $noop))]);

        $this->assertSame($wide, $host->layout([0, 0, 24, 100])[0]['width']);
        $this->assertGreaterThan(40, $wide);
    }

    /**
     * And placed as if as tall as the most rows seen: a query that finds less
     * shrinks the box from the bottom, its top where it was — nothing moves
     * under the reader, and no blank rows stand in for the missing ones.
     */
    public function testTheSearchBoxKeepsItsTopAsTheQueryNarrows(): void
    {
        $host = $this->host();
        $host->openSearch('S', static fn (): null => null, static fn (): null => null, static fn (): null => null, 'no results');
        $host->setSearchRows($this->searchRows(static fn (): null => null));
        $tall = $host->layout([0, 0, 24, 100])[0];

        $host->setSearchRows([]);
        $short = $host->layout([0, 0, 24, 100])[0];

        $this->assertSame($tall['top'], $short['top'], 'the top stays');
        $this->assertLessThan(\count($tall['rows']), \count($short['rows']), 'the box shrinks');
        $this->assertStringContainsString('no results', AnsiUtils::stripAnsiCodes(\implode("\n", $short['rows'])));
    }

    /** The ▼ and ▲ a clipped list draws are targets: a click pages, and the list stays open. */
    public function testTheFrameCuesPageTheList(): void
    {
        $host = $this->host();
        $items = \array_map(static fn (int $i): array => ['label' => "item {$i}", 'hint' => '', 'value' => (string) $i], \range(0, end: 29));
        $host->openList('T', $items, static fn (): null => null);
        $host->layout([0, 0, 24, 80]);
        $box = Probe::get($host, 'overlayBox');
        $page = $box->count;

        $this->assertTrue($host->clickAt($box->startRow + $box->rows - 2, $box->col + 5), '▼');
        $this->assertSame($page, Probe::get($host, 'overlay')['selected']);
        $this->assertNotNull(Probe::get($host, 'overlay'));
        $host->layout([0, 0, 24, 80]);
        $box = Probe::get($host, 'overlayBox');
        $this->assertTrue($host->clickAt($box->startRow + 1, $box->col + 5), '▲');
        $this->assertSame(0, Probe::get($host, 'overlay')['selected']);
        foreach (\range(1, end: 3) as $_) {
            $host->handleInput("\x1b[B", $this->keybindings());
        }
        $host->layout([0, 0, 24, 80]);
        $box = Probe::get($host, 'overlayBox');
        $host->clickAt($box->startRow + 1, $box->col + 5);
        $this->assertSame(3, Probe::get($host, 'overlay')['selected'], 'nothing above: the padding is only padding');
        $this->assertNotNull(Probe::get($host, 'overlay'));
    }

    public function testTheFrameCuesPageTheSearch(): void
    {
        $host = $this->host();
        $noop = static fn (): null => null;
        $host->openSearch('S', $noop, $noop, $noop);
        $host->setSearchRows([SearchRow::header('Files'), ...\array_map(fn (int $i): SearchRow => SearchRow::hit($this->hit(SearchCategory::Files, "f{$i}", $noop)), \range(0, end: 29))]);
        $host->layout([0, 0, 24, 80]);
        $box = Probe::get($host, 'searchBox');

        $this->assertTrue($host->searchClickAt($box->startRow + $box->rows - 2, $box->col + 5));
        $this->assertSame(1 + $box->count, Probe::get($host, 'search')->selected, 'a page of hits down');
        $host->layout([0, 0, 24, 80]);
        $box = Probe::get($host, 'searchBox');
        $this->assertTrue($host->searchClickAt($box->startRow + 1, $box->col + 5));
        $this->assertSame(1, Probe::get($host, 'search')->selected);
    }

    /**
     * The selection never rests on a disabled row — opening, arrows either
     * way, paging, the wheel — as a menu's never rests on a greyed item.
     */
    public function testTheSelectionSkipsDisabledRows(): void
    {
        $host = $this->host();
        $items = [
            ['label' => 'grey 0', 'hint' => '', 'value' => '0', 'disabled' => true],
            ['label' => 'one', 'hint' => '', 'value' => '1'],
            ['label' => 'grey 2', 'hint' => '', 'value' => '2', 'disabled' => true],
            ['label' => 'three', 'hint' => '', 'value' => '3'],
        ];
        $host->openList('T', $items, static fn (): null => null);
        $selected = static fn (): int => Probe::get($host, 'overlay')['selected'];
        $kb = $this->editingKeys();

        $this->assertSame(1, $selected(), 'opens on the first row that can be picked');
        $host->handleInput("\x1b[B", $kb);
        $this->assertSame(3, $selected(), 'down, past the grey one');
        $host->handleInput("\x1b[B", $kb);
        $this->assertSame(1, $selected(), 'wrapping past the grey first row');
        $host->handleInput("\x1b[A", $kb);
        $this->assertSame(3, $selected(), 'up, wrapping, past the grey one');
        $host->handleInput("\x1b[A", $kb);
        $this->assertSame(1, $selected());
        $host->moveListSelection(1);
        $this->assertSame(3, $selected(), 'the wheel skips it too');
        $host->moveListSelection(-1);
        $this->assertSame(1, $selected());
        $host->layout([0, 0, 24, 80]);
        $host->handleInput("\x1b[5~", $kb);
        $this->assertSame(1, $selected(), 'a page up to the top lands on the first live row');

        $host->closeList();
        $host->openList(
            'T',
            [['label' => 'a', 'hint' => '', 'value' => 'a'], ['label' => 'b', 'hint' => '', 'value' => 'b'], ['label' => 'grey', 'hint' => '', 'value' => 'g', 'disabled' => true]],
            static fn (): null => null,
        );
        $host->layout([0, 0, 24, 80]);
        $host->handleInput("\x1b[6~", $kb);
        $this->assertSame(1, $selected(), 'a page down onto a grey last row stops on the live one before it, not round the corner');
    }

    /** A list whose every row is disabled keeps its selection where it is, rather than looping. */
    public function testAllDisabledKeepsTheSelection(): void
    {
        $host = $this->host();
        $host->openList('T', [['label' => 'a', 'hint' => '', 'value' => 'a', 'disabled' => true], ['label' => 'b', 'hint' => '', 'value' => 'b', 'disabled' => true]], static fn (): null => null);
        $host->handleInput("\x1b[B", $this->editingKeys());

        $this->assertSame(1, Probe::get($host, 'overlay')['selected']);
    }

    /** A list of notes alone (problems with no fix) is read and closed, never run: Enter leaves it open. */
    public function testANoteIsNeverPicked(): void
    {
        $host = $this->host();
        $picked = [];
        $host->openList('Quick Fixes', [['label' => '✗ boom', 'hint' => '', 'value' => '', 'note' => true]], static function (string $value) use (&$picked): void {
            $picked[] = $value;
        });

        $host->handleInput("\r", $this->editingKeys());

        $this->assertSame([], $picked);
        $this->assertNotNull(Probe::get($host, 'overlay'), 'the list stays up to be read');
    }

    /** In the search modal too, the selection starts on — and steps between — hits that can run. */
    public function testTheSearchSelectionSkipsGreyedHits(): void
    {
        $host = $this->host();
        $host->openSearch('S', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $noop = static fn (): null => null;
        $host->setSearchRows([
            SearchRow::header('Actions'),
            SearchRow::hit(new SearchHit(SearchCategory::Actions, 'Step Over', 'F8', $noop, enabled: false)),
            SearchRow::hit(new SearchHit(SearchCategory::Actions, 'Save', '', $noop)),
            SearchRow::hit(new SearchHit(SearchCategory::Actions, 'Step Out', '', $noop, enabled: false)),
            SearchRow::hit(new SearchHit(SearchCategory::Actions, 'Close', '', $noop)),
        ]);
        $selected = static fn (): int => Probe::get($host, 'search')->selected;
        $kb = $this->editingKeys();

        $this->assertSame(2, $selected(), 'Save, not the greyed Step Over');
        $host->handleSearchInput("\x1b[B", $kb);
        $this->assertSame(4, $selected(), 'past Step Out');
        $host->handleSearchInput("\x1b[B", $kb);
        $this->assertSame(2, $selected(), 'wrapping past Step Over');
        $host->layout([0, 0, 24, 80]);
        $host->handleSearchInput("\x1b[6~", $kb);
        $this->assertSame(4, $selected());
    }
}
