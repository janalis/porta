<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\OverlayHost;
use Porta\Overlay\State\PickerSurface;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Ui\CursorStyle;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\OverlayCompositor;
use Porta\Widget\Render\TabCloseGlyph;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Tui;

final class OverlayHostTest extends TestCase
{
    private Tui $tui;

    private function host(): OverlayHost
    {
        // A real Tui, because the host asks IT for a frame now rather than
        // calling back into a widget: these boxes float over the workspace and
        // there is no widget below them to invalidate.
        $this->tui = TestTui::create(100, 24);
        $this->clearRepaint();

        return new OverlayHost($this->tui);
    }

    private function clearRepaint(): void
    {
        Probe::set($this->tui, 'renderRequested', false);
    }

    private function repainted(): bool
    {
        return true === Probe::get($this->tui, 'renderRequested');
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
        $lines = \array_fill(0, $rows, \str_repeat(' ', \max(0, $columns)));
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

    public function testConstructorPrefersTheGivenCursorStyleOverANewOne(): void
    {
        $cursorStyle = new CursorStyle(CursorShape::Block);
        $host = new OverlayHost(TestTui::create(10, 10), cursorStyle: $cursorStyle);

        $this->assertSame($cursorStyle, Probe::get($host, 'cursorStyle'));
    }

    public function testInvalidateIsInertWithNoTui(): void
    {
        $host = new OverlayHost();
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $this->assertTrue(null !== Probe::get($host, 'overlay'), 'no crash without a tui');
    }

    public function testOpenPromptDefaultsToNotDanger(): void
    {
        $plain = $this->host();
        $plain->openPrompt('L', '', static fn () => null); // danger omitted → false

        $dangerous = $this->host();
        $dangerous->openPrompt('L', '', static fn () => null, danger: true);

        $this->assertNotSame($this->canvas($plain, 40, 5), $this->canvas($dangerous, 40, 5), 'the danger styling differs from the default');
    }

    public function testEachStateChangeRequestsARender(): void
    {
        $host = $this->host();

        $this->clearRepaint();
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $this->assertTrue($this->repainted(), 'openList');

        $this->clearRepaint();
        $host->openPrompt('P', '', static fn () => null);
        $this->assertTrue($this->repainted(), 'openPrompt');

        $this->clearRepaint();
        $host->promptClickAt(9, 0); // promptBox is still null (layout() never ran) → the dismiss branch
        $this->assertTrue($this->repainted(), 'promptClickAt dismiss');
        $this->assertFalse(null !== Probe::get($host, 'prompt'));

        $this->clearRepaint();
        $host->openSearch('S', static fn () => null, static fn () => null, static fn () => null);
        $this->assertTrue($this->repainted(), 'openSearch');

        $this->clearRepaint();
        $host->setSearchRows($this->searchRows(static fn () => null));
        $this->assertTrue($this->repainted(), 'setSearchRows');

        $this->clearRepaint();
        $host->closeSearch();
        $this->assertTrue($this->repainted(), 'closeSearch');
    }

    public function testOpenListIgnoresAnEmptyList(): void
    {
        $host = $this->host();
        $host->openList('Empty', [], static fn () => null);
        $this->assertFalse(null !== Probe::get($host, 'overlay'));
        $this->assertFalse($this->repainted(), 'nothing to repaint');
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
        $this->assertTrue(null !== Probe::get($host, 'overlay'), 'the list overlay survives the prompt');
    }

    public function testCloseListLeavesThePromptOpen(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);

        $host->closeList();
        $this->assertFalse(null !== Probe::get($host, 'overlay'));
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

        $this->clearRepaint();
        $host->handleInput("\x7f", $kb); // backspace → 'a'
        $this->assertTrue($this->repainted(), 'a successful backspace requests a render');

        $this->clearRepaint();
        $host->handleInput("\x7f", $kb); // backspace → ''
        $this->assertTrue($this->repainted());

        $this->clearRepaint();
        $host->handleInput("\x7f", $kb); // backspace on empty: no-op
        $this->assertFalse($this->repainted(), 'a no-op backspace does not request a render');

        $this->clearRepaint();
        $host->handleInput("\x1b", $kb); // escape cancels without submitting
        $this->assertTrue($this->repainted(), 'cancelling requests a render');
        $this->assertNull($submitted);
        $this->assertFalse($host->handleInput('x', $kb), 'prompt closed, input falls through');
    }

    public function testListOverlayCursorKeysWrapExactlyOneStep(): void
    {
        $host = $this->host();
        $items = [
            ['label' => 'a', 'hint' => '', 'value' => 'A'],
            ['label' => 'b', 'hint' => '', 'value' => 'B'],
            ['label' => 'c', 'hint' => '', 'value' => 'C'],
        ];
        $host->openList('L', $items, static fn () => null);
        $kb = $this->keybindings();

        $this->clearRepaint();
        $host->handleInput("\x1b[A", $kb); // cursor_up: 0 wraps back to 2
        $this->assertSame(2, Probe::get($host, 'overlay')['selected']);
        $this->assertTrue($this->repainted(), 'cursor_up requests a render');

        $this->clearRepaint();
        $host->handleInput("\x1b[B", $kb); // cursor_down: 2 wraps forward to 0
        $this->assertSame(0, Probe::get($host, 'overlay')['selected']);
        $this->assertTrue($this->repainted(), 'cursor_down requests a render');

        $this->clearRepaint();
        $host->handleInput("\x1b[B", $kb); // cursor_down: 0 to 1
        $this->assertSame(1, Probe::get($host, 'overlay')['selected']);

        $this->clearRepaint();
        $host->handleInput("\x05", $kb); // ctrl+e: recent_files cycles forward too: 1 to 2
        $this->assertSame(2, Probe::get($host, 'overlay')['selected']);
        $this->assertTrue($this->repainted(), 'recent_files requests a render');
    }

    public function testListOverlaySelectCancelClosesWithoutPicking(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static function (string $v) use (&$picked): void {
            $picked = $v;
        });

        $this->clearRepaint();
        $this->assertTrue($host->handleInput("\x1b", $this->keybindings()));
        $this->assertFalse(null !== Probe::get($host, 'overlay'), 'select_cancel closed the list');
        $this->assertNull($picked);
        $this->assertTrue($this->repainted(), 'select_cancel requests a render');
    }

    public function testListOverlayAcceptPicksTheSelectedItemAndRequestsARender(): void
    {
        $host = $this->host();
        $items = [
            ['label' => 'a', 'hint' => '', 'value' => 'A'],
            ['label' => 'b', 'hint' => '', 'value' => 'B'],
        ];
        $picked = null;
        $host->openList('L', $items, static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $kb = $this->keybindings();
        $host->handleInput("\x1b[B", $kb); // move to 'b'

        $this->clearRepaint();
        $this->assertTrue($host->handleInput("\r", $kb));
        $this->assertSame('B', $picked, 'the selected item, not the first one');
        $this->assertFalse(null !== Probe::get($host, 'overlay'), 'accept closes the list');
        $this->assertTrue($this->repainted(), 'accept_completion requests a render');
    }

    public function testPromptSubmitAndInsertRequestRendersOnlyWhenSomethingChanged(): void
    {
        $host = $this->host();
        $submitted = null;
        $host->openPrompt('P', '', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $kb = $this->keybindings();

        $this->clearRepaint();
        $host->handleInput('x', $kb); // a printable char: insert() succeeds
        $this->assertTrue($this->repainted(), 'a successful insert requests a render');

        $this->clearRepaint();
        $host->handleInput("\x1b[A", $kb); // an arrow-key sequence: not printable, insert() declines
        $this->assertFalse($this->repainted(), 'a declined insert does not request a render');

        $this->clearRepaint();
        $host->handleInput("\r", $kb); // Enter submits
        $this->assertSame('x', $submitted);
        $this->assertTrue($this->repainted(), 'submitting requests a render');
    }

    public function testPromptClickIsInertWhenClosed(): void
    {
        $host = $this->host();
        $this->assertFalse($host->promptClickAt(0, 0), 'no prompt open → not consumed');
        $this->assertFalse($this->repainted(), 'nothing to repaint');
    }

    public function testPromptClickOnTheBoxIsSwallowedAndClickElsewhereDismisses(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 2, 40]); // framed box: rows 0-4, inner width 30 at col 3

        $this->assertTrue($host->promptClickAt(0, 5), 'a click on the frame title bar is consumed');
        $this->assertTrue(null !== Probe::get($host, 'prompt'), 'and does not dismiss it — the caret stays');
        $this->assertTrue($host->promptClickAt(2, 5), 'a click on the field row is consumed');
        $this->assertTrue(null !== Probe::get($host, 'prompt'));

        $this->assertTrue($host->promptClickAt(5, 5), 'a click below the box is consumed');
        $this->assertFalse(null !== Probe::get($host, 'prompt'), 'and dismisses it, like every sibling overlay');
    }

    public function testPromptClickBeyondTheBoxWidthDismisses(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 2, 40]);

        $this->assertTrue($host->promptClickAt(0, 37), 'row 0 but past the box width is outside');
        $this->assertFalse(null !== Probe::get($host, 'prompt'));
    }

    public function testThePromptCloseButtonDismisses(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->layout([0, 0, 2, 40]); // box [4, 36): × at col 34

        $this->assertTrue($host->promptClickAt(0, 34), 'the title bar ×');
        $this->assertFalse(null !== Probe::get($host, 'prompt'));
    }

    /** Hovering a picker's × brightens it, exactly like the tab strips' close glyph. */
    public function testHoveringTheCloseBrightensItPerSurface(): void
    {
        $host = $this->host();
        $host->openList('Title', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $lines = $this->canvas($host, 80, 20);
        $box = Probe::get($host, 'overlayBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');
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
        $box = Probe::get($host, 'promptBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $this->assertTrue($host->promptHoverAt($box->startRow, $box->col + $box->width - 2), 'onto the ×');
        $this->assertTrue($host->promptHoverAt($box->startRow + 2, $box->col + 2), 'off it');
        $this->assertFalse($host->promptHoverAt($box->startRow + 2, $box->col + 2), 'unchanged');
    }

    public function testPromptHoverAtIsInertOnceThePromptIsDismissed(): void
    {
        // promptClickAt(outside) nulls $prompt but leaves the last-laid-out
        // promptBox in place; promptHoverAt must bail on the prompt guard
        // rather than hover-test that now-stale box.
        $host = $this->host();
        $host->openPrompt('P', '', static fn () => null);
        $host->layout([0, 0, 8, 40]);
        $box = Probe::get($host, 'promptBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');
        $host->promptClickAt($box->startRow, $box->col + $box->width - 2); // the × → dismiss
        $this->assertFalse(null !== Probe::get($host, 'prompt'));

        $this->clearRepaint();
        $this->assertFalse($host->promptHoverAt($box->startRow, $box->col + $box->width - 2), 'nothing open to hover');
        $this->assertFalse($this->repainted(), 'the stale box must not be hover-tested');
    }

    public function testTheListOverlayCloseButtonDismisses(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openList('Title', [['label' => 'a', 'hint' => '', 'value' => 'A']], static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->layout([0, 0, 20, 80]);
        $box = Probe::get($host, 'overlayBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $this->clearRepaint();
        $this->assertTrue($host->clickAt($box->startRow, $box->col + $box->width - 2), 'the title bar ×');
        $this->assertFalse(null !== Probe::get($host, 'overlay'));
        $this->assertNull($picked, 'the close click never picks an item');
        $this->assertTrue($this->repainted(), 'closing via × requests a render');
    }

    public function testListOverlayClickOnAnItemPicksItAndRequestsARender(): void
    {
        $host = $this->host();
        $items = [
            ['label' => 'a', 'hint' => '', 'value' => 'A'],
            ['label' => 'b', 'hint' => '', 'value' => 'B'],
        ];
        $picked = null;
        $host->openList('Title', $items, static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->layout([0, 0, 20, 80]);
        $box = Probe::get($host, 'overlayBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $this->clearRepaint();
        $this->assertTrue($host->clickAt($box->windowTop() + 1, $box->col + 1), 'the second row');
        $this->assertSame('B', $picked, 'the item under the click, not the first one, and not the close path');
        $this->assertFalse(null !== Probe::get($host, 'overlay'), 'picking closes the overlay');
        $this->assertTrue($this->repainted(), 'picking an item requests a render');
    }

    public function testHoverAtOnAnItemRowUpdatesTheHoveredIndexAndRequestsARender(): void
    {
        $host = $this->host();
        $items = [
            ['label' => 'a', 'hint' => '', 'value' => 'A'],
            ['label' => 'b', 'hint' => '', 'value' => 'B'],
        ];
        $host->openList('Title', $items, static fn () => null);
        $host->layout([0, 0, 20, 80]);
        $box = Probe::get($host, 'overlayBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $this->clearRepaint();
        $this->assertTrue($host->hoverAt($box->windowTop() + 1, $box->col + 1), 'the second row');
        $this->assertSame(1, Probe::get($host, 'overlay')['hovered']);
        $this->assertTrue($this->repainted(), 'a changed hover requests a render');
    }

    public function testHoverAtIsInertOnceTheListOverlayIsClosed(): void
    {
        // closeList() nulls $overlay but leaves the last-laid-out overlayBox
        // in place; hoverAt must bail on the overlay guard rather than
        // hover-testing that now-stale box.
        $host = $this->host();
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $host->layout([0, 0, 20, 80]);
        $box = Probe::get($host, 'overlayBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');
        $host->closeList();

        $this->clearRepaint();
        $this->assertFalse($host->hoverAt($box->startRow, $box->col + $box->width - 2), 'nothing open to hover');
        $this->assertFalse($this->repainted(), 'the stale box must not be hover-tested');
    }

    public function testListOverlayClickAndHoverAreSafeBeforeTheFirstLayout(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openList('Title', [['label' => 'a', 'hint' => '', 'value' => 'A']], static function (string $v) use (&$picked): void {
            $picked = $v;
        });

        $this->assertFalse($host->hoverAt(0, 0), 'no box yet — nothing to hover');
        $this->assertTrue($host->clickAt(0, 0), 'no box yet — falls through to "click elsewhere"');
        $this->assertFalse(null !== Probe::get($host, 'overlay'));
        $this->assertNull($picked);
    }

    public function testListOverlayClickAwayFromTheBoxDismissesWithoutPicking(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openList('Title', [['label' => 'a', 'hint' => '', 'value' => 'A']], static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->layout([0, 0, 20, 80]);

        $this->clearRepaint();
        $this->assertTrue($host->clickAt(19, 79), 'far outside the box');
        $this->assertFalse(null !== Probe::get($host, 'overlay'));
        $this->assertNull($picked);
        $this->assertTrue($this->repainted());
    }

    public function testTheSearchModalCloseButtonCloses(): void
    {
        $host = $this->host();
        $closed = 0;
        $host->openSearch('Search', static fn () => null, static fn () => null, static function () use (&$closed): void {
            ++$closed;
        });
        $host->layout([0, 0, 20, 80]);
        $box = Probe::get($host, 'searchBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $this->assertTrue($host->searchClickAt($box->startRow, $box->col + $box->width - 2), 'the title bar ×');
        $this->assertSame(1, $closed, 'exactly once — a removed early return would fall through to the elsewhere-click close too');
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
            SearchRow::header('Files'),
            SearchRow::hit($this->hit(SearchCategory::Files, 'a', static function () use ($onActivate): void {
                $onActivate('a');
            })),
            SearchRow::hit($this->hit(SearchCategory::Files, 'b', static function () use ($onActivate): void {
                $onActivate('b');
            })),
            SearchRow::header('Actions'),
            SearchRow::hit($this->hit(SearchCategory::Actions, 'c', static function () use ($onActivate): void {
                $onActivate('c');
            })),
        ];
    }

    /** @return list<SearchRow> $count consecutive hits, no headers — every step lands on a hit immediately. */
    private function consecutiveHits(int $count = 4): array
    {
        $labels = ['a', 'b', 'c', 'd', 'e', 'f'];

        return \array_map(
            fn (string $label): SearchRow => SearchRow::hit($this->hit(SearchCategory::Files, $label, static fn () => null)),
            \array_slice($labels, offset: 0, length: $count),
        );
    }

    public function testCursorKeysStepExactlyOneHitRow(): void
    {
        // With headers in the row list, stepping by 2 instead of 1 can still
        // land on the same hit a few retries later (the loop keeps trying until
        // it clears a header) — so this needs a header-free fixture where every
        // single step already lands on a hit, making a stray ±2 immediately visible.
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows($this->consecutiveHits());
        $search = Probe::get($host, 'search');
        $search->selected = 1; // 'b'
        $kb = $this->keybindings();

        $this->clearRepaint();
        $host->handleSearchInput("\x1b[B", $kb); // down: exactly one step to 'c' (index 2)
        $this->assertSame(2, Probe::get($host, 'search')->selected);
        $this->assertTrue($this->repainted(), 'cursor_down requests a render');

        $this->clearRepaint();
        $host->handleSearchInput("\x1b[A", $kb); // up: exactly one step back to 'b' (index 1)
        $this->assertSame(1, Probe::get($host, 'search')->selected);
        $this->assertTrue($this->repainted(), 'cursor_up requests a render');
    }

    public function testSearchModalIsInertWhenClosed(): void
    {
        $host = $this->host();
        $host->handleSearchInput('x', $this->keybindings()); // no crash, no callbacks
        $host->setSearchRows([SearchRow::header('Files')]); // ignored while closed
        $this->assertFalse($host->isSearchOpen());
        $this->assertFalse($host->searchClickAt(0, 0));
        $this->assertFalse($host->searchHoverAt(0, 0));
        $this->assertFalse($this->repainted(), 'nothing to repaint');
    }

    /** Before the first layout() call, searchBox is still null — every read of it must stay nullsafe. */
    public function testSearchClickAndHoverAreSafeBeforeTheFirstLayout(): void
    {
        $host = $this->host();
        $closed = 0;
        $host->openSearch('Search', static fn () => null, static fn () => null, static function () use (&$closed): void {
            ++$closed;
        });

        $this->assertTrue($host->searchClickAt(0, 0), 'no box yet — falls through to "click elsewhere"');
        $this->assertSame(1, $closed);
        $this->assertFalse($host->searchHoverAt(0, 0), 'no box, no item under the pointer — nothing changed');
    }

    public function testSearchInputBranchesDoNotFallThroughToTheQueryField(): void
    {
        $host = $this->host();
        $queries = [];
        $picked = null;
        $closed = 0;
        $host->openSearch(
            'Search',
            static function (string $q) use (&$queries): void {
                $queries[] = $q;
            },
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static function () use (&$closed): void {
                ++$closed;
            },
        );
        $host->setSearchRows($this->searchRows(static fn () => null));
        $kb = $this->keybindings();

        // select_cancel: closes, and must not also fall through into the query field.
        $host->handleSearchInput("\x1b", $kb);
        $this->assertSame(1, $closed);
        $this->assertSame([], $queries, 'the escape byte was not inserted into the query');

        // cursor_down then cursor_up then accept: exact single-step moves, no fallthrough.
        $host->handleSearchInput("\x1b[B", $kb); // down: a → b
        $host->handleSearchInput("\x1b[A", $kb); // up: b → a
        $host->handleSearchInput("\r", $kb); // accept_completion: picks 'a', no fallthrough
        $this->assertSame('a', $picked);
        $this->assertSame([], $queries, 'none of the navigation/accept keys leaked into the query');
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
        $joined = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));
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
        $host->setSearchRows([SearchRow::header('Files')]);
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
            SearchRow::header('Text'),
            SearchRow::hit(new SearchHit(
                SearchCategory::Text,
                \str_repeat('"ext-simplexml": "*", ', times: 8),
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

        $this->clearRepaint();
        $host->moveSearchSelection(1);
        $this->assertTrue($this->repainted(), 'the wheel moved the search selection');
    }

    public function testWheelMovesTheSearchSelectionByExactlyDeltaSteps(): void
    {
        // A 5-hit, header-free fixture (odd count, delta=2 != count/2): both
        // an off-by-one loop bound AND a flipped step direction land on a
        // different final index than the correct one, so this one fixture
        // separates every loop-bound/direction mutant on this method.
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows($this->consecutiveHits(5));
        $search = Probe::get($host, 'search');
        $search->selected = 0;

        $host->moveSearchSelection(2); // two steps forward: 0 -> 1 -> 2
        $this->assertSame(2, Probe::get($host, 'search')->selected);

        $host->moveSearchSelection(-2); // two steps back: 2 -> 1 -> 0
        $this->assertSame(0, Probe::get($host, 'search')->selected);
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

    public function testWheelMovesTheListOverlaySelectionByExactlyDeltaAndRequestsARender(): void
    {
        // A 3-item fixture, because with only 2 items a wrong sign on $delta
        // still lands on the same index modulo 2 — the collision the
        // stepHit() cursor-key test above ran into with headers.
        $host = $this->host();
        $items = [
            ['label' => 'a', 'hint' => '', 'value' => 'A'],
            ['label' => 'b', 'hint' => '', 'value' => 'B'],
            ['label' => 'c', 'hint' => '', 'value' => 'C'],
        ];
        $host->openList('L', $items, static fn () => null);

        $this->clearRepaint();
        $host->moveListSelection(1); // 0 -> 1
        $this->assertSame(1, Probe::get($host, 'overlay')['selected']);
        $this->assertTrue($this->repainted(), 'moveListSelection requests a render');

        $this->clearRepaint();
        $host->moveListSelection(-1); // 1 -> 0
        $this->assertSame(0, Probe::get($host, 'overlay')['selected']);

        $host->moveListSelection(-1); // 0 wraps back to 2
        $this->assertSame(2, Probe::get($host, 'overlay')['selected']);
    }

    public function testThePromptKeepsItsCaretVisibleAndShowsTheEscHint(): void
    {
        $host = $this->host();
        // A value far longer than the box: a plain truncate would clip the
        // caret marker off the right edge and lose the hardware cursor.
        $host->openPrompt('Rename to', \str_repeat('x', times: 80), static fn () => null);

        $lines = $this->canvas($host, 60, 24);
        $joined = \implode("\n", $lines);

        $this->assertStringContainsString(AnsiUtils::CURSOR_MARKER_PREFIX, $joined, 'the caret marker survived the truncation');
        $this->assertStringContainsString('Esc', AnsiUtils::stripAnsiCodes($joined), 'the dismiss hint shows');
        $this->assertSame(\str_repeat(' ', times: 60), $lines[0], 'the prompt is centered, not on the top row');
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

        // The two floating boxes can share screen rows, so a composited
        // canvas's marker count is proven by whichever box paints last —
        // not by the caret logic. Read each box's OWN rows straight from
        // layout()'s return instead.
        [$searchPlaced, $promptPlaced] = $host->layout([0, 0, 24, 60]);
        $this->assertStringNotContainsString(AnsiUtils::CURSOR_MARKER_PREFIX, \implode("\n", $searchPlaced['rows']), 'the prompt wins: the search query embeds no marker');
        $this->assertStringContainsString(AnsiUtils::CURSOR_MARKER_PREFIX, \implode("\n", $promptPlaced['rows']), 'the prompt embeds its own marker');

        $lines = $this->canvas($host, 60, 24);
        $joined = \implode("\n", $lines);
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
        $this->clearRepaint();
        for ($row = 0; $row < 20; ++$row) {
            if (!$host->searchHoverAt($row, 40)) {
                continue;
            }

            $hitRow = $row;
            break;
        }
        $this->assertGreaterThanOrEqual(0, $hitRow, 'a hit row tinted');
        $this->assertTrue($this->repainted(), 'a changed hover requests a render (the return value alone does not prove this)');
        $body = \implode("\n", $this->canvas($host, 80, 20));
        $this->assertStringContainsString("\x1b[48;5;31m", $body, 'the selected hit keeps its background');
        $this->assertStringContainsString("\x1b[48;5;236m", $body, 'the hovered hit takes the tint, not the selection');
    }

    public function testTopmostSurfaceRanksPromptOverSearchOverList(): void
    {
        $host = $this->host();
        $this->assertNull($host->topmostSurface());
        $this->assertFalse($host->anyOpen());
        $this->assertFalse($host->pickerClickAt(0, 0));
        $this->assertFalse($host->pickerHoverAt(0, 0));

        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $this->assertSame(PickerSurface::ListOverlay, $host->topmostSurface());
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $this->assertSame(PickerSurface::Search, $host->topmostSurface());
        $host->openPrompt('Go to line', '', static fn () => null);
        $this->assertSame(PickerSurface::Prompt, $host->topmostSurface());
        $this->assertTrue($host->anyOpen());
    }

    public function testPickerClickAndHoverGoToTheTopmostSurface(): void
    {
        $host = $this->host();
        $picked = null;
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->openPrompt('Rename to', 'old', static fn () => null);
        $host->layout([0, 0, 24, 80]);

        // The prompt owns the pointer: an outside click dismisses IT, not the list.
        $this->assertTrue($host->pickerClickAt(23, 0));
        $this->assertFalse(null !== Probe::get($host, 'prompt'));
        $this->assertTrue(null !== Probe::get($host, 'overlay'), 'the click never reached the list overlay');
        $this->assertNull($picked);

        // With the prompt gone the list is topmost; hover routes to its rows.
        $host->layout([0, 0, 24, 80]);
        $this->assertSame(PickerSurface::ListOverlay, $host->topmostSurface());
        $host->pickerHoverAt(0, 0);
        $this->assertTrue($host->pickerClickAt(23, 0), 'an outside click dismisses the list');
        $this->assertFalse(null !== Probe::get($host, 'overlay'));
    }

    public function testPickerClickAndHoverRouteToTheSearchModalWhenItIsTopmost(): void
    {
        $host = $this->host();
        $closed = 0;
        $host->openSearch('Search', static fn () => null, static fn () => null, static function () use (&$closed): void {
            ++$closed;
        });
        $host->setSearchRows($this->searchRows(static fn () => null));
        $host->layout([0, 0, 20, 80]);
        $this->assertSame(PickerSurface::Search, $host->topmostSurface());
        $box = Probe::get($host, 'searchBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the search modal laid out a modal box');

        $this->assertTrue($host->pickerHoverAt($box->windowTop() + 1, $box->col + 1), 'routes to searchHoverAt, not the list/prompt path');
        $this->assertTrue($host->pickerClickAt($box->startRow, $box->col + $box->width - 2), 'routes to searchClickAt: the × closes it');
        $this->assertSame(1, $closed);
    }

    public function testWheelSelectionSkipsThePromptAndMovesTheListUnderneath(): void
    {
        $host = $this->host();
        $items = [
            ['label' => 'a', 'hint' => '', 'value' => 'A'],
            ['label' => 'b', 'hint' => '', 'value' => 'B'],
        ];
        $picked = null;
        $host->openList('L', $items, static function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->openPrompt('Go to line', '', static fn () => null);

        // The prompt has no selection: the wheel falls through to the list.
        $this->assertTrue($host->moveSelection(1));
        $host->handleSearchInput('', $this->keybindings()); // inert: search is closed
        $this->assertTrue($host->handleInput("\r", $this->keybindings()), 'Enter still submits the prompt');
        $this->assertTrue($host->handleInput("\r", $this->keybindings()), 'then picks the wheel-moved list row');
        $this->assertSame('B', $picked);

        $this->assertFalse($host->moveSelection(1), 'nothing open consumes nothing');
    }

    public function testWheelSelectionPrefersTheSearchModal(): void
    {
        $host = $this->host();
        $host->openList(
            'L',
            [
                ['label' => 'a', 'hint' => '', 'value' => 'A'],
                ['label' => 'b', 'hint' => '', 'value' => 'B'],
            ],
            static fn () => null,
        );
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows($this->searchRows(static fn () => null));
        $this->clearRepaint();

        $this->assertTrue($host->moveSelection(1));
        $this->assertTrue($this->repainted(), 'the search selection moved');
        $this->assertSame(0, Probe::get($host, 'overlay')['selected'], 'the list underneath must not also move');
    }

    public function testCloseHoverStylingIsPerSurfaceNotGlobal(): void
    {
        // layout() compares $this->closeHovered against each surface's OWN
        // PickerSurface case; a before/after-hover diff alone can't tell a
        // correct per-surface flip from an inverted one (both differ from a
        // baseline), so this asserts the exact active/inactive glyph on each
        // of the three surfaces at once, with only the list hovered. Read
        // each box's own title row straight from layout()'s return (rather
        // than a composited canvas) since the three floating boxes can share
        // screen rows and would otherwise paint over one another.
        $styles = Styles::dark();
        $host = new OverlayHost(TestTui::create(100, 24), styles: $styles);
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->openPrompt('P', '', static fn () => null);
        $host->layout([0, 0, 24, 100]);
        $listBox = Probe::get($host, 'overlayBox');
        \assert($listBox instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $host->hoverAt($listBox->startRow, $listBox->col + $listBox->width - 2); // only the list's ×
        [$listPlaced, $searchPlaced, $promptPlaced] = $host->layout([0, 0, 24, 100]);

        $active = TabCloseGlyph::active($styles);
        $inactive = TabCloseGlyph::inactive($styles);
        $this->assertStringContainsString($active, $listPlaced['rows'][0], 'the hovered surface shows the active ×');
        $this->assertStringContainsString($inactive, $searchPlaced['rows'][0], 'search must stay inactive');
        $this->assertStringContainsString($inactive, $promptPlaced['rows'][0], 'the prompt must stay inactive');
    }

    public function testListOverlaySelectedAndHoveredRowsAreStyledIndependently(): void
    {
        // 20 items forces a scroll window (windowStart > 0 once selected is
        // pushed down), which is what actually distinguishes "windowStart + i"
        // from "windowStart - i" in the row-index math below.
        $items = [];
        for ($i = 0; $i < 20; ++$i) {
            $items[] = ['label' => "item{$i}", 'hint' => '', 'value' => (string) $i];
        }
        $host = $this->host();
        $host->openList('L', $items, static fn () => null);
        Probe::set($host, 'overlay', ['title' => 'L', 'items' => $items, 'selected' => 15, 'hovered' => 10, 'filter' => new TextField(), 'onPick' => static fn () => null]);

        $lines = $this->canvas($host, 80, 20);
        $selectedRow = null;
        $hoveredRow = null;
        foreach ($lines as $row => $line) {
            if (\str_contains($line, 'item15')) {
                $selectedRow = $row;
            }
            if (\str_contains($line, 'item10')) {
                $hoveredRow = $row;
            }
        }
        $this->assertNotNull($selectedRow, 'item15 (selected) scrolled into view');
        $this->assertNotNull($hoveredRow, 'item10 (hovered) scrolled into view');
        $this->assertStringContainsString("\x1b[48;5;31m", $lines[$selectedRow], 'the selected row carries the selection background');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $lines[$hoveredRow], 'the hovered row is NOT also styled as selected');
        // PopupRow (plain, unselected/unhovered) ALSO carries background 236 —
        // it's RowHighlightBg's foreground-less variant of the same color that
        // actually distinguishes "genuinely hovered" from "just a plain row".
        $this->assertStringContainsString("\x1b[48;5;236m", $lines[$hoveredRow], 'the hovered row carries the hover tint');
        $this->assertStringNotContainsString("\x1b[38;5;250m", $lines[$hoveredRow], 'and it is NOT styled as a plain PopupRow');
    }

    public function testSearchSelectedAndHoveredRowsAreStyledIndependentlyWhenScrolled(): void
    {
        $rows = \array_map(
            fn (int $i): SearchRow => SearchRow::hit($this->hit(SearchCategory::Files, "hit{$i}", static fn () => null)),
            \range(0, end: 19),
        );
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows($rows);
        $search = Probe::get($host, 'search');
        $search->selected = 15;
        $search->hovered = 10;

        $lines = $this->canvas($host, 80, 20);
        $selectedRow = null;
        $hoveredRow = null;
        foreach ($lines as $row => $line) {
            if (\str_contains($line, 'hit15')) {
                $selectedRow = $row;
            }
            if (\str_contains($line, 'hit10')) {
                $hoveredRow = $row;
            }
        }
        $this->assertNotNull($selectedRow, 'the selected hit scrolled into view');
        $this->assertNotNull($hoveredRow, 'the hovered hit scrolled into view');
        $this->assertStringContainsString("\x1b[48;5;31m", $lines[$selectedRow], 'the selected row carries the selection background');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $lines[$hoveredRow], 'the hovered row is NOT also styled as selected');
        $this->assertStringContainsString("\x1b[48;5;236m", $lines[$hoveredRow], 'the hovered row carries the hover tint');
        // PopupRow (plain) shares that same background 236 with a color:250
        // foreground it doesn't have — that foreground is what actually tells
        // "genuinely hovered" apart from "just an ordinary unselected row".
        $this->assertStringNotContainsString("\x1b[38;5;250m", $lines[$hoveredRow], 'and it is NOT styled as a plain row');
    }

    public function testNoRowsAndNoEmptyMessageAddsNoFillerRow(): void
    {
        // The filler row is only for a configured empty-state message — a
        // zero-result search with no message configured (the default '')
        // must not grow the frame by a blank row.
        $host = $this->host();
        $host->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows([]);

        [$box] = $host->layout([0, 0, 20, 80]);
        $this->assertCount(5, $box['rows'], 'title bar + query row + 3 frame chrome rows, no filler');
    }

    /** @return int how many of the 20 distinct "itemN" labels are visible on the canvas */
    private function countVisibleListItems(OverlayHost $host, int $rows): int
    {
        $lines = $this->canvas($host, 80, $rows);
        $count = 0;
        foreach ($lines as $line) {
            for ($n = 0; $n < 20; ++$n) {
                if (!\str_contains($line, "item{$n} ")) {
                    continue;
                }
                ++$count;
            }
        }

        return $count;
    }

    public function testListOverlayVisibleItemCountRespectsTheRowBudgetFormula(): void
    {
        $items = [];
        for ($i = 0; $i < 20; ++$i) {
            $items[] = ['label' => "item{$i}", 'hint' => '', 'value' => (string) $i];
        }
        $host = $this->host();
        $host->openList('L', $items, static fn () => null);

        // rows=5: rows-5=0, so max(1, min(20, 0, 12)) floors to exactly 1 item —
        // a floor of 0 here would show none at all despite 20 real items.
        $this->assertSame(1, $this->countVisibleListItems($host, rows: 5));

        // rows=9: rows-5=4, distinct from both an off-by-one (3) and a sign
        // flip (rows+5=14, capped at 12) — exactly 4 pins the real formula.
        $this->assertSame(4, $this->countVisibleListItems($host, rows: 9));
    }

    public function testListOverlayWidthGrowsToFitTheWidestLabelPlusHint(): void
    {
        // A short title so the item's own label+hint dominates the width
        // formula (label(30) + hint(10) + 6 = 46), with columns generous
        // enough (100) to stay clear of the centered-picker clamps.
        $host = $this->host();
        $host->openList('X', [['label' => \str_repeat('A', times: 30), 'hint' => \str_repeat('B', times: 10), 'value' => 'v']], static fn () => null);

        [$box] = $host->layout([0, 0, 20, 100]);
        $this->assertSame(50, $box['width'], '46 (label+hint+6) + 4 chrome columns');
    }

    public function testSearchWidthGrowsToFitTheWidestLabelPlusDetail(): void
    {
        $host = $this->host();
        $host->openSearch('X', static fn () => null, static fn () => null, static fn () => null);
        $host->setSearchRows([SearchRow::hit(new SearchHit(SearchCategory::Files, \str_repeat('A', times: 30), \str_repeat('B', times: 10), static fn () => null))]);

        [$box] = $host->layout([0, 0, 20, 100]);
        $this->assertSame(50, $box['width'], '46 (label+detail+6) + 4 chrome columns');
    }
}
