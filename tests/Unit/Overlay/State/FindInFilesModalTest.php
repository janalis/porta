<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\FindInFilesModal;
use Porta\Overlay\State\FindModalCallbacks;
use Porta\Tests\Support\Probe;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Glyphs;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;

final class FindInFilesModalTest extends TestCase
{
    private Keybindings $kb;

    protected function setUp(): void
    {
        // Kitty protocol stays inactive (the app default), so legacy alt/CSI
        // sequences resolve (\x1bs → alt+s, \x1b\r → alt+enter, \x1b[A → up).
        $this->kb = new Keybindings([]);
    }

    private function modal(): FindInFilesModal
    {
        return new FindInFilesModal(static fn () => null);
    }

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function results(): array
    {
        return [
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => [['start' => 0, 'length' => 3]]],
            ['path' => 'src/A.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ['path' => 'other/B.php', 'line' => 2, 'text' => 'foo three', 'ranges' => []],
        ];
    }

    /**
     * Lay the modal out on a blank $rows×$columns screen and composite its
     * panel where it placed itself — the test-side stand-in for the
     * presenter's span push, keeping every geometry assertion in the same
     * screen coordinates the mouse paths use.
     *
     * @return list<string>
     */
    private function compose(FindInFilesModal $modal, int $columns = 100, int $rows = 24): array
    {
        $lines = array_fill(0, count: $rows, value: str_repeat(' ', times: $columns));
        $placed = $modal->layout([0, 0, $rows, $columns], CaretVisibility::Shown);
        if (null === $placed) {
            return $lines;
        }
        $box = $modal->lastBox();
        \assert(null !== $box, description: 'the modal laid out a box');

        return $this->placeAt($lines, $placed['rows'], $placed['top'], $placed['col'], $box->width, $columns);
    }

    /**
     * Splice the box rows into the screen lines — the test's own composition
     * scaffold over the prod {@see OverlayCompositor::into} splicer.
     *
     * @param list<string> $lines
     * @param list<string> $box
     *
     * @return list<string>
     */
    private function placeAt(array $lines, array $box, int $startRow, int $col, int $width, int $columns): array
    {
        foreach ($box as $i => $boxLine) {
            $row = $startRow + $i;
            if ($row >= \count($lines)) {
                break;
            }
            $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $boxLine);
        }

        return $lines;
    }

    private function screen(FindInFilesModal $modal): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->compose($modal)));
    }

    public function testRendersTitleQueryScopeOptionsAndGroupedPreview(): void
    {
        $modal = $this->modal();
        $this->assertNull($modal->layout([0, 0, 24, 100], CaretVisibility::Shown), 'closed → no layout');

        $modal->open('Find in Files');
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->setOptions(['regex' => false, 'case' => true, 'word' => false, 'preserve' => false, 'tokens' => 'any']);
        $modal->setPreview($this->results());

        $screen = $this->screen($modal);
        $this->assertStringContainsString('Find in Files', $screen);
        $this->assertStringContainsString('3 results', $screen);
        $this->assertStringContainsString('Find:', $screen, 'the query field is labelled');
        $this->assertStringContainsString('Replace:', $screen, 'the replace field is labelled');
        $this->assertStringContainsString('Mask:', $screen, 'the mask field is labelled');
        $this->assertStringContainsString('Scope: Whole Project', $screen);
        $this->assertStringContainsString('Aa', $screen, 'option tags render');
        $this->assertStringContainsString('src/A.php', $screen, 'grouped file header');
        $this->assertStringContainsString('other/B.php', $screen);
        $this->assertStringContainsString('foo one', $screen, 'match rows render');
        $this->assertStringContainsString('Find window · Scope', $screen, 'footer actions');
        $this->assertStringNotContainsString('Close', $screen, 'the Close word is retired — Esc/outside-click dismiss');
        $this->assertStringContainsString('Esc', $screen, 'the title carries the dismiss hint');
    }

    public function testEmptyAndSearchingStates(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->assertStringContainsString('type to search', $this->screen($modal));

        $modal->setSearching();
        $this->assertStringContainsString('searching…', $this->screen($modal));

        // A typed query with no matches shows "no results".
        $modal->handleInput('z', $this->kb);
        $modal->setPreview([]);
        $this->assertStringContainsString('no results', $this->screen($modal));
    }

    public function testQueryTypingFiresOnQuery(): void
    {
        $modal = $this->modal();
        $queries = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onQuery: static function (string $q) use (&$queries): void {
                $queries[] = $q;
            },
        ));
        $modal->open('Find in Files');

        $modal->handleInput('f', $this->kb);
        $modal->handleInput('o', $this->kb);
        $modal->handleInput("\x7f", $this->kb); // backspace
        $modal->handleInput("\x1b[13;2u", $this->kb); // shift+enter → newline in the query
        $modal->handleInput('o', $this->kb);
        $this->assertSame(['f', 'fo', 'f', "f\n", "f\no"], $queries, 'multiline query via shift+enter');
        $this->assertStringContainsString('f⏎o', $this->screen($modal), 'the newline renders as ⏎');
    }

    public function testQueryHistoryRecallCyclesWithAltArrows(): void
    {
        $modal = $this->modal();
        $recalled = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onQuery: static function (string $q) use (&$recalled): void {
                $recalled[] = $q;
            },
        ));
        $modal->open('Find in Files');
        $modal->setHistory(['recent', 'older']); // most-recent-first

        $modal->handleInput("\x1bn", $this->kb); // alt+down at the start: nothing newer → no-op
        $modal->handleInput("\x1bp", $this->kb); // alt+up → 'recent'
        $modal->handleInput("\x1bp", $this->kb); // alt+up → 'older'
        $modal->handleInput("\x1bp", $this->kb); // alt+up past the end → no-op
        $modal->handleInput("\x1bn", $this->kb); // alt+down → 'recent'
        $modal->handleInput("\x1bn", $this->kb); // alt+down → '' (back to a blank query)

        $this->assertSame(['recent', 'older', 'recent', ''], $recalled);
    }

    public function testNavigateAndJump(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // rows: [hdr A, match, match, hdr B, match]; selection on first match

        $modal->handleInput("\r", $this->kb); // enter → jump first match
        $this->assertSame(['src/A.php', 4], $jumped);

        $modal->handleInput("\x1b[B", $this->kb); // down → second match (skips nothing)
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['src/A.php', 9], $jumped);

        $modal->handleInput("\x1b[B", $this->kb); // down → skips the other/B.php header to its match
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['other/B.php', 2], $jumped);

        $modal->handleInput("\x1b[A", $this->kb); // up → back to the second A match
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['src/A.php', 9], $jumped);
    }

    public function testScopeCycleOptionsTabMaskAndActions(): void
    {
        $modal = $this->modal();
        $scopeIdx = [];
        $toggled = [];
        $masks = [];
        $opened = false;
        $closed = false;
        $modal->setCallbacks(new FindModalCallbacks(
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
            onToggleOption: static function (string $n) use (&$toggled): void {
                $toggled[] = $n;
            },
            onMaskChanged: static function (string $m) use (&$masks): void {
                $masks[] = $m;
            },
            onOpenInFindWindow: static function () use (&$opened): void {
                $opened = true;
            },
            onClose: static function () use (&$closed): void {
                $closed = true;
            },
        ));
        $modal->open('Find in Files');
        $modal->setScopes(['Whole Project', 'Directory', 'Current File'], 0);

        $modal->handleInput("\x1bs", $this->kb); // alt+s → scope index 1
        $modal->handleInput("\x1br", $this->kb); // alt+r regex
        $modal->handleInput("\x1bc", $this->kb); // alt+c case
        $modal->handleInput("\x1bw", $this->kb); // alt+w word
        $modal->handleInput("\x1bv", $this->kb); // alt+v preserve
        $modal->handleInput("\x1bt", $this->kb); // alt+t tokens
        $this->assertSame([1], $scopeIdx);
        $this->assertSame(['regex', 'case', 'word', 'preserve', 'tokens'], $toggled);
        $this->assertStringContainsString('Scope: Directory', $this->screen($modal), 'scope label advanced');

        $modal->handleInput("\t", $this->kb); // tab → replace field
        $modal->handleInput("\t", $this->kb); // tab → mask field
        $modal->handleInput('p', $this->kb);
        $this->assertSame(['p'], $masks);

        $modal->handleInput("\t", $this->kb); // tab → wraps back to query
        $modal->handleInput('q', $this->kb);
        $this->assertSame([1], $scopeIdx, 'still no re-scope'); // sanity: the wrap edited the query, not scope

        $modal->handleInput("\x1b\r", $this->kb); // alt+enter → open in find window
        $this->assertTrue($opened);

        $modal->handleInput("\x1b", $this->kb); // esc → close
        $this->assertTrue($closed);
    }

    public function testReplacePreviewExcludeAndReplaceAll(): void
    {
        $modal = $this->modal();
        $replaced = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onReplaceAll: static function (string $r, array $excluded) use (&$replaced): void {
                $replaced = [$r, $excluded];
            },
            previewReplacement: static fn (string $old, string $repl): string => str_replace('foo', $repl, $old),
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // rows: [hdr A, match(4), match(9), hdr B, match(2)]

        // Type a replacement → the preview flips to old → new with checkboxes.
        $modal->handleInput("\t", $this->kb); // query → replace
        foreach (str_split('bar') as $ch) {
            $modal->handleInput($ch, $this->kb);
        }
        $screen = $this->screen($modal);
        $this->assertStringContainsString('→', $screen, 'preview shows old → new');
        $this->assertStringContainsString('[x]', $screen, 'kept matches are checked');
        $this->assertStringContainsString('bar one', $screen, 'the replacement is previewed');

        // Exclude the first match (Del on the selected row).
        $modal->handleInput("\x1b[3~", $this->kb);
        $this->assertStringContainsString('(excluded)', $this->screen($modal));

        // Alt+A replaces the kept matches (index 0 excluded).
        $modal->handleInput("\x1ba", $this->kb);
        $this->assertSame(['bar', [0]], $replaced);
    }

    public function testAReIncludedMatchIsOmittedFromTheExcludedSet(): void
    {
        $modal = $this->modal();
        $replaced = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onReplaceAll: static function (string $r, array $excluded) use (&$replaced): void {
                $replaced = [$r, $excluded];
            },
            previewReplacement: static fn (string $old, string $repl): string => str_replace('foo', $repl, $old),
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results());

        $modal->handleInput("\t", $this->kb); // query → replace
        foreach (str_split('bar') as $ch) {
            $modal->handleInput($ch, $this->kb);
        }

        // Toggle the first match's exclude on, then off again: its `false` entry
        // is skipped when the excluded set is collected for replace-all.
        $modal->handleInput("\x1b[3~", $this->kb); // exclude match 0
        $modal->handleInput("\x1b[3~", $this->kb); // re-include match 0 (excluded[0] = false)

        $modal->handleInput("\x1ba", $this->kb); // replace all
        $this->assertSame(['bar', []], $replaced, 'a re-included match is not reported as excluded');
    }

    public function testEdges(): void
    {
        $modal = $this->modal();

        // Mouse on a closed modal is a no-op.
        $this->assertFalse($modal->clickAt(0, 0));
        $this->assertFalse($modal->hoverAt(0, 0));

        $modal->open('Find in Files');
        $this->assertTrue($modal->isOpen());
        $this->assertFalse($modal->hoverAt(0, 0), 'hover before the first composite (no box yet)');
        $modal->setMaskValue('*.php');
        $this->assertStringContainsString('Mask: *.php', $this->screen($modal));

        $modal->close();
        $this->assertFalse($modal->isOpen());

        // Navigation clamps at the ends (no wrap).
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // selection on the first match (display index 1)
        $modal->handleInput("\x1b[A", $this->kb); // up past the file header → stays put
        $jumped = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
        ));
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['src/A.php', 4], $jumped, 'up at the top kept the first match selected');

        // Many matches scroll the preview window.
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        for ($i = 0; $i < 12; ++$i) {
            $modal->handleInput("\x1b[B", $this->kb); // page down through the matches
        }
        $box = null;
        $screen = (function () use ($modal, &$box): string {
            $out = $this->compose($modal);
            $box = $modal->lastBox();

            return AnsiUtils::stripAnsiCodes(implode("\n", $out));
        })();
        $this->assertNotNull($box);
        $this->assertGreaterThan(0, $box->windowStart, 'the window scrolled to keep the selection visible');
        $this->assertStringContainsString('hit 11', $screen);

        // Clicking the options row off any tag, and hovering headers / outside, are inert.
        $modal->clickAt($box->windowTop() - 3, $box->col + $box->width - 1); // options row, past the tags
        $this->assertFalse($modal->hoverAt($box->windowTop() - 5, $box->col + 2), 'above the preview');
        $this->assertFalse($modal->hoverAt($box->windowTop() + 99, $box->col + 2), 'below the preview');
    }

    public function testScrollPreviewWithTheWheelMovesTheWindow(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);

        $box = function () use ($modal): \Porta\Overlay\Region\ModalBox {
            $this->compose($modal);

            $box = $modal->lastBox();
            \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

            return $box;
        };

        $this->assertSame(0, $box()->windowStart, 'starts at the top');

        $modal->scrollPreview(12); // wheel down past the bottom edge
        $this->assertGreaterThan(0, $box()->windowStart, 'the preview scrolled down');

        $modal->scrollPreview(-20); // wheel up past the top → clamps
        $this->assertSame(0, $box()->windowStart, 'scrolled back to the top');
    }

    public function testHoverSelectsWithoutScrollingTheWindow(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many); // 15 display rows (1 header + 14 matches), 10 visible

        $box = function () use ($modal): \Porta\Overlay\Region\ModalBox {
            $this->compose($modal);

            $box = $modal->lastBox();
            \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

            return $box;
        };

        // Hovering the bottom-most visible row moves the selection but never the window.
        $b = $box();
        $this->assertSame(0, $b->windowStart);
        $this->assertTrue($modal->hoverAt($b->windowTop() + $b->count - 1, $b->col + 3));
        $this->assertSame(0, $box()->windowStart, 'hover at the bottom edge did not scroll');

        // Scroll to the end, then hover the top-most visible row: still no scroll.
        $modal->scrollPreview(20);
        $this->assertSame(5, $box()->windowStart, 'wheel to the last match');
        $b = $box();
        $this->assertTrue($modal->hoverAt($b->windowTop(), $b->col + 3));
        $this->assertSame(5, $box()->windowStart, 'hover at the top edge did not scroll');
    }

    public function testKeyboardScrollsMinimallyIntoView(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many); // selection on the first match (display index 1)

        $box = function () use ($modal): \Porta\Overlay\Region\ModalBox {
            $this->compose($modal);

            $box = $modal->lastBox();
            \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

            return $box;
        };

        $this->assertSame(0, $box()->windowStart);
        for ($i = 0; $i < 9; ++$i) {
            $modal->handleInput("\x1b[B", $this->kb); // down to display index 10, one past the bottom edge
        }
        $this->assertSame(1, $box()->windowStart, 'scrolled by exactly the overflow, no recentering');

        for ($i = 0; $i < 9; ++$i) {
            $modal->handleInput("\x1b[A", $this->kb); // back up to the first match
        }
        $this->assertSame(0, $box()->windowStart, 'the first match reveals its file-header row above');

        // A new search resets the window to the top.
        $modal->scrollPreview(20);
        $this->assertSame(5, $box()->windowStart);
        $modal->setPreview($many);
        $this->assertSame(0, $box()->windowStart, 'new results start at the top');
    }

    public function testTinyTerminalDoesNotOverflow(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        // A 4-row terminal can't fit the box; the layout must clamp its
        // position (and the test-side composite clip), not error.
        $out = $this->compose($modal, columns: 40, rows: 4);
        $this->assertCount(4, $out);
    }

    public function testMouseClickTogglesOptionJumpsAndDismisses(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $toggled = [];
        $closed = false;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
            onToggleOption: static function (string $n) use (&$toggled): void {
                $toggled[] = $n;
            },
            onClose: static function () use (&$closed): void {
                $closed = true;
            },
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $this->screen($modal); // composite → sets the box geometry

        $box = $modal->lastBox();
        $this->assertNotNull($box);

        // Click the first option tag (options row is windowTop() - 3; inner col 1 sits past the rail and padding).
        $modal->clickAt($box->windowTop() - 3, $box->col + 3);
        $this->assertSame(['regex'], $toggled);

        // Hover the second match row (previewRow + 2; the first match is already selected).
        $this->assertTrue($modal->hoverAt($box->windowTop() + 2, $box->col + 3));
        // Click the first match row (previewRow + 1 skips the file header) → jump.
        $modal->clickAt($box->windowTop() + 1, $box->col + 3);
        $this->assertSame(['src/A.php', 4], $jumped);

        // A click outside the box dismisses.
        $modal->clickAt($box->windowTop(), $box->col + $box->width + 5);
        $this->assertTrue($closed);
    }

    /** A corner drag resizes the box: wider/taller, floored at 48 wide × 3 preview rows, reset on open. */
    public function testACornerDragResizesTheBoxAndOpenResets(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $this->assertFalse($modal->continueDragTo(0, 0, release: false), 'nothing armed yet');

        // Press the bottom-right corner, drag right and down, release.
        $this->assertTrue($modal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1));
        $this->assertTrue($modal->continueDragTo($box->startRow + $box->rows + 1, $box->col + $box->width + 9, release: false));
        $this->screen($modal);
        $grown = $modal->lastBox();
        $this->assertNotNull($grown);
        $this->assertSame($box->width + 10, $grown->width, 'the width followed the pointer');
        $this->assertSame(11, $grown->count, 'one more preview row — the 24-row screen budget caps the second');

        // Dragging into the header pins at the floors (48 inner, 3 preview rows).
        $this->assertTrue($modal->continueDragTo($box->startRow, $box->col, release: true));
        $this->screen($modal);
        $floor = $modal->lastBox();
        $this->assertNotNull($floor);
        $this->assertSame(48 + 4, $floor->width, 'inner width floored at 48 (+ chrome)');
        $this->assertSame(3, $floor->count, 'the preview floor');
        $this->assertFalse($modal->continueDragTo(0, 0, release: false), 'the release disarmed the resize');

        // A fresh open re-derives the size — overrides are session-only.
        $modal->open('Find in Files');
        $modal->setPreview($many);
        $this->screen($modal);
        $reopened = $modal->lastBox();
        $this->assertNotNull($reopened);
        $this->assertSame(10, $reopened->count, 'the reopen dropped the user preview rows');
    }

    /** A title-bar drag moves the box; the position clamps, survives typing, and resets on open. */
    public function testATitleBarDragMovesTheBoxAndOpenRecenters(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        // Press the title bar (off the ×), drag down-left, release.
        $this->assertTrue($modal->clickAt($box->startRow, $box->col + 2), 'a title-bar press is consumed');
        $this->assertTrue($modal->isOpen(), 'and does not dismiss');
        $this->assertTrue($modal->continueDragTo($box->startRow + 3, $box->col - 2, release: false));
        $this->screen($modal);
        $moved = $modal->lastBox();
        $this->assertNotNull($moved);
        $this->assertSame($box->startRow + 3, $moved->startRow, 'the box followed the pointer');
        $this->assertSame($box->col - 4, $moved->col, 'grabbed at col+2, pointer at col−2 → the box moved 4 left');

        // Dragging far past the top-left pins at the pane origin.
        $this->assertTrue($modal->continueDragTo(-50, -50, release: true));
        $this->screen($modal);
        $pinned = $modal->lastBox();
        $this->assertNotNull($pinned);
        $this->assertSame(0, $pinned->startRow, 'clamped to the pane top');
        $this->assertSame(0, $pinned->col, 'clamped to the pane left edge');
        $this->assertFalse($modal->continueDragTo(5, 5, release: false), 'the release disarmed the move');

        // A fresh open recenters — the position is session-only.
        $modal->open('Find in Files');
        $this->screen($modal);
        $reopened = $modal->lastBox();
        $this->assertNotNull($reopened);
        $this->assertSame($box->startRow, $reopened->startRow, 'the reopen recentered');
        $this->assertSame($box->col, $reopened->col);
    }

    public function testCancelDragDisarms(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $modal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1); // arm the resize
        $modal->cancelDrag();
        $this->assertFalse($modal->continueDragTo(10, 10, release: false), 'the resize was disarmed');

        $modal->clickAt($box->startRow, $box->col + 2); // arm a move
        $modal->cancelDrag();
        $this->assertFalse($modal->continueDragTo(10, 10, release: false), 'the move was disarmed');
    }

    public function testHoveringTheResizeCornerHeaviesIt(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $this->assertTrue($modal->hoverAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1), 'onto the corner');
        $this->assertStringContainsString(Glyphs::MODAL_RESIZE_GRAB, $this->screen($modal), 'the corner heavied');
        $this->assertTrue($modal->hoverAt($box->startRow + 2, $box->col + 3), 'off it');
        $this->assertStringNotContainsString(Glyphs::MODAL_RESIZE_GRAB, $this->screen($modal));
    }

    public function testHoveringTheCloseBrightensIt(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $normalBar = null;
        $screen = (function () use ($modal, &$normalBar): string {
            $lines = $this->compose($modal);
            $box = $modal->lastBox();
            \assert(null !== $box, description: 'the modal laid out a box');
            $normalBar = $lines[$box->startRow];

            return '';
        })();
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $this->assertTrue($modal->hoverAt($box->startRow, $box->col + $box->width - 2), 'onto the ×');
        $hoveredBar = $this->compose($modal)[$box->startRow];
        $this->assertNotSame($normalBar, $hoveredBar, 'the × restyled under the pointer');

        $this->assertTrue($modal->hoverAt($box->startRow + 2, $box->col + 3), 'off the ×');
    }

    public function testTheCloseButtonCloses(): void
    {
        $closed = false;
        $modal = $this->modal();
        $modal->setCallbacks(new FindModalCallbacks(
            onClose: static function () use (&$closed): void {
                $closed = true;
            },
        ));
        $modal->open('Find in Files');
        $this->screen($modal); // composite → sets the box geometry
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $this->assertTrue($modal->clickAt($box->startRow, $box->col + $box->width - 2), 'the title bar ×');
        $this->assertTrue($closed);
    }

    public function testMouseClickFocusesFieldRows(): void
    {
        $modal = $this->modal();
        $queries = [];
        $masks = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onQuery: static function (string $q) use (&$queries): void {
                $queries[] = $q;
            },
            onMaskChanged: static function (string $m) use (&$masks): void {
                $masks[] = $m;
            },
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;

        // Click the Replace row (optionsRow - 1) → focus it; typing has no callback, observe via render.
        $modal->clickAt($optionsRow - 1, $box->col + 3);
        $modal->handleInput('X', $this->kb);
        $this->assertStringContainsString('Replace: X', $this->screen($modal), 'the replace field took the keystroke');

        // Click the Mask row (optionsRow + 1) → focus it; typing fires onMaskChanged.
        $modal->clickAt($optionsRow + 1, $box->col + 3);
        $modal->handleInput('y', $this->kb);
        $this->assertSame(['y'], $masks);

        // Click the Find row (optionsRow - 2) → focus the query; typing fires onQuery.
        $modal->clickAt($optionsRow - 2, $box->col + 3);
        $modal->handleInput('z', $this->kb);
        $this->assertSame(['z'], $queries);
    }

    public function testMouseClickCyclesScope(): void
    {
        $modal = $this->modal();
        $scopeIdx = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
        ));
        $modal->setScopes(['Whole Project', 'Directory', 'Current File'], 0);
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;
        $optionsLine = AnsiUtils::stripAnsiCodes($lines[$optionsRow]);

        // The two-space gap between the last tag and the scope label hits no zone.
        $scopeCol = mb_strpos($optionsLine, needle: 'Scope:');
        $this->assertIsInt($scopeCol);
        $modal->clickAt($optionsRow, $scopeCol - 1);
        $this->assertSame([], $scopeIdx, 'the gap before the scope label is inert');

        // Clicking the scope label cycles to the next scope.
        $modal->clickAt($optionsRow, $scopeCol + 2);
        $this->assertSame([1], $scopeIdx);
        $this->assertStringContainsString('Scope: Directory', $this->screen($modal));
    }

    public function testMouseHoverHighlightsTagsAndScope(): void
    {
        $modal = $this->modal();
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;

        // Hover the first option tag (inner col 1, past the rail and padding) →
        // highlight changes; the same spot → no change.
        $this->assertTrue($modal->hoverAt($optionsRow, $box->col + 3));
        $this->assertFalse($modal->hoverAt($optionsRow, $box->col + 3));
        $this->assertStringContainsString('.*', $this->screen($modal), 'the hovered tag still renders');

        // A different tag → changes.
        $this->assertTrue($modal->hoverAt($optionsRow, $box->col + 7));

        // Hover the scope label → changes and still renders.
        $optionsLine = AnsiUtils::stripAnsiCodes($lines[$optionsRow]);
        $scopeCol = mb_strpos($optionsLine, needle: 'Scope:');
        $this->assertIsInt($scopeCol);
        $this->assertTrue($modal->hoverAt($optionsRow, $scopeCol + 2));
        $this->assertStringContainsString('Scope: Whole Project', $this->screen($modal));

        // Moving off the options row (onto the Find field row) clears the hover.
        $this->assertTrue($modal->hoverAt($optionsRow - 2, $box->col + 3));
    }

    public function testMouseClickTogglesExcludeCheckbox(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
            previewReplacement: static fn (string $old, string $repl): string => str_replace('foo', $repl, $old),
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $modal->handleInput("\t", $this->kb); // query → replace
        foreach (str_split('bar') as $ch) {
            $modal->handleInput($ch, $this->kb);
        }
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        // Click the [x] checkbox on the first match row (inner cols 2-4,
        // past the rail and padding) → exclude it.
        $modal->clickAt($box->windowTop() + 1, $box->col + 4);
        $this->assertStringContainsString('(excluded)', $this->screen($modal));

        // Click it again → re-include.
        $modal->clickAt($box->windowTop() + 1, $box->col + 4);
        $this->assertStringNotContainsString('(excluded)', $this->screen($modal));

        // Click past the checkbox on the same row → jump to the match.
        $modal->clickAt($box->windowTop() + 1, $box->col + 12);
        $this->assertSame(['src/A.php', 4], $jumped);
    }

    public function testMouseFooterActionsClickAndHover(): void
    {
        $modal = $this->modal();
        $opened = false;
        $closed = false;
        $scopeIdx = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onOpenInFindWindow: static function () use (&$opened): void {
                $opened = true;
            },
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
            onClose: static function () use (&$closed): void {
                $closed = true;
            },
        ));
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $footerRow = Probe::get($modal, 'box')->footerRow ?? -1;
        $footerLine = AnsiUtils::stripAnsiCodes($lines[$footerRow]);

        // Hover the "Find window" action → highlight changes and still renders.
        $fwCol = mb_strpos($footerLine, needle: 'Find window');
        $this->assertIsInt($fwCol);
        $this->assertTrue($modal->hoverAt($footerRow, $fwCol));
        $this->assertStringContainsString('Find window', $this->screen($modal));

        // Click each footer action.
        $modal->clickAt($footerRow, $fwCol);
        $this->assertTrue($opened, 'Find window promotes to the results pane');

        $scopeCol = mb_strpos($footerLine, needle: 'Scope');
        $this->assertIsInt($scopeCol);
        $modal->clickAt($footerRow, $scopeCol);
        $this->assertSame([1], $scopeIdx, 'the footer Scope action cycles the scope');

        // No footer Close word anymore: Esc dismisses instead.
        $this->assertFalse(mb_strpos($footerLine, needle: 'Close'), 'the footer carries no Close action');
        $modal->handleInput("\x1b", new Keybindings([]));
        $this->assertTrue($closed, 'Esc fires the close hook');
    }
}
