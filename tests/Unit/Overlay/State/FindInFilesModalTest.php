<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Overlay\State\FindInFilesModal;
use Porta\Overlay\State\FindModalCallbacks;
use Porta\Tests\Support\Probe;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FindModalRows;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Render\OverlayCompositor;
use Porta\Widget\Render\PopupMetrics;
use Porta\Widget\Render\TabCloseGlyph;
use Porta\Widget\Support\OptionTagState;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;

final class FindInFilesModalTest extends TestCase
{
    private Keybindings $kb;
    private int $invalidateCount = 0;

    protected function setUp(): void
    {
        // Kitty protocol stays inactive (the app default), so legacy alt/CSI
        // sequences resolve (\x1bs → alt+s, \x1b\r → alt+enter, \x1b[A → up).
        $this->kb = new Keybindings([]);
        $this->invalidateCount = 0;
    }

    /**
     * A modal over {@see results()} with replace-all wired, already switched to
     * the replace field with "bar" typed in — where every excluded-set case
     * starts, because the excluded set only means anything once there is a
     * replacement to apply it to.
     *
     * `$replaced` is by reference rather than returned because the callback
     * fires later, when the test presses the key: what the caller needs is the
     * SLOT the callback will write into.
     */
    private function modalTypedForReplaceAll(?array &$replaced): FindInFilesModal
    {
        $modal = $this->modal();
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

        return $modal;
    }

    private function modal(): FindInFilesModal
    {
        return new FindInFilesModal(static fn () => null);
    }

    /** Counts every ($this->invalidate)() call via {@see $this->invalidateCount}. */
    private function modalCountingInvalidate(): FindInFilesModal
    {
        return new FindInFilesModal(function (): void {
            ++$this->invalidateCount;
        });
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

    /**
     * Like {@see compose()}, but with an explicit caret-focus state — compose()
     * hardcodes {@see CaretVisibility::Shown}, which can't exercise the Hidden half
     * of the header rows' caret ternaries.
     *
     * @return list<string>
     */
    private function composeWithFocus(FindInFilesModal $modal, CaretVisibility $focused, int $columns = 100, int $rows = 24): array
    {
        $lines = array_fill(0, count: $rows, value: str_repeat(' ', times: $columns));
        $placed = $modal->layout([0, 0, $rows, $columns], $focused);
        if (null === $placed) {
            return $lines;
        }
        $box = $modal->lastBox();
        \assert(null !== $box, description: 'the modal laid out a box');

        return $this->placeAt($lines, $placed['rows'], $placed['top'], $placed['col'], $box->width, $columns);
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
        $this->assertStringContainsString('(Tab)', $screen, 'the mask field carries its hint, translated');
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
        $replaced = null;
        $modal = $this->modalTypedForReplaceAll($replaced);

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
        $this->assertTrue($modal->clickAt($box->windowTop() - 3, $box->col + 3));
        $this->assertSame(['regex'], $toggled);

        // Hover the second match row (previewRow + 2; the first match is already selected).
        $this->assertTrue($modal->hoverAt($box->windowTop() + 2, $box->col + 3));
        // Click the first match row (previewRow + 1 skips the file header) → jump.
        $this->assertTrue($modal->clickAt($box->windowTop() + 1, $box->col + 3));
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
        $jumped = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
        ));
        $this->assertTrue($modal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1));
        $this->assertNull($jumped, 'the resize-corner press did not also fall through to a preview-row jump');
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

        // The direction matters, not just "it changed": the × starts dim and
        // only the active variant is bright, never the reverse.
        $styles = Styles::dark();
        $this->assertStringContainsString(TabCloseGlyph::inactive($styles), $normalBar, 'the × starts dim, not hot');
        $this->assertStringNotContainsString(TabCloseGlyph::active($styles), $normalBar);

        $this->assertTrue($modal->hoverAt($box->startRow, $box->col + $box->width - 2), 'onto the ×');
        $hoveredBar = $this->compose($modal)[$box->startRow];
        $this->assertNotSame($normalBar, $hoveredBar, 'the × restyled under the pointer');
        $this->assertStringContainsString(TabCloseGlyph::active($styles), $hoveredBar, 'hovering the × brightens it');
        $this->assertStringNotContainsString(TabCloseGlyph::inactive($styles), $hoveredBar);

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
        // The × press must not ALSO fall through into the title-bar-drag
        // press on the same row: nothing should be armed afterward.
        $this->assertFalse($modal->continueDragTo(10, 10, release: false), 'the × click armed no drag');
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
        $this->assertTrue($modal->clickAt($optionsRow - 1, $box->col + 3));
        $modal->handleInput('X', $this->kb);
        $this->assertStringContainsString('Replace: X', $this->screen($modal), 'the replace field took the keystroke');

        // Click the Mask row (optionsRow + 1) → focus it; typing fires onMaskChanged.
        $this->assertTrue($modal->clickAt($optionsRow + 1, $box->col + 3));
        $modal->handleInput('y', $this->kb);
        $this->assertSame(['y'], $masks);

        // Click the Find row (optionsRow - 2) → focus the query; typing fires onQuery.
        $this->assertTrue($modal->clickAt($optionsRow - 2, $box->col + 3));
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
        $this->assertTrue($modal->clickAt($optionsRow, $scopeCol - 1));
        $this->assertSame([], $scopeIdx, 'the gap before the scope label is inert');

        // Clicking the scope label cycles to the next scope.
        $this->assertTrue($modal->clickAt($optionsRow, $scopeCol + 2));
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
        // past the rail and padding) → exclude it, and ONLY exclude it — not
        // also jump (toggleExcludeSelected returns early; falling through
        // would additionally call jumpSelected()).
        $this->assertTrue($modal->clickAt($box->windowTop() + 1, $box->col + 4));
        $this->assertStringContainsString('(excluded)', $this->screen($modal));
        $this->assertNull($jumped, 'excluding the checkbox did not also jump to the match');

        // Click it again → re-include.
        $this->assertTrue($modal->clickAt($box->windowTop() + 1, $box->col + 4));
        $this->assertStringNotContainsString('(excluded)', $this->screen($modal));

        // Click past the checkbox on the same row → jump to the match.
        $this->assertTrue($modal->clickAt($box->windowTop() + 1, $box->col + 12));
        $this->assertSame(['src/A.php', 4], $jumped);
    }

    public function testExcludeCheckboxGuardRequiresPreviewModeAndTheRightColumns(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
        ));
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // replace field empty: NOT in preview mode
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        // innerCol 2 is inside the checkbox range, but inPreview() is false:
        // must still jump, not toggle an invisible exclude flag.
        $this->assertTrue($modal->clickAt($box->windowTop() + 1, $box->col + 4));
        $this->assertSame(['src/A.php', 4], $jumped, 'outside preview mode, the checkbox range is inert and the click jumps');

        // Now enter preview mode and check the column upper boundary: innerCol
        // 5 is one past the checkbox range and must jump, not toggle.
        $jumped = null;
        $modal->handleInput("\t", $this->kb);
        foreach (str_split('bar') as $ch) {
            $modal->handleInput($ch, $this->kb);
        }
        $this->screen($modal);
        $this->assertTrue($modal->clickAt($box->windowTop() + 1, $box->col + 7)); // innerCol = 7 - 2 = 5
        $this->assertSame(['src/A.php', 4], $jumped, 'innerCol 5 is one past the checkbox range and jumps instead of toggling');
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
        $this->assertTrue($modal->clickAt($footerRow, $fwCol));
        $this->assertTrue($opened, 'Find window promotes to the results pane');

        $scopeCol = mb_strpos($footerLine, needle: 'Scope');
        $this->assertIsInt($scopeCol);
        $this->assertTrue($modal->clickAt($footerRow, $scopeCol));
        $this->assertSame([1], $scopeIdx, 'the footer Scope action cycles the scope');

        // No footer Close word anymore: Esc dismisses instead.
        $this->assertFalse(mb_strpos($footerLine, needle: 'Close'), 'the footer carries no Close action');
        $modal->handleInput("\x1b", new Keybindings([]));
        $this->assertTrue($closed, 'Esc fires the close hook');
    }

    // --- Mutation-sweep gap-closing tests below ------------------------

    public function testCustomTranslatorIsUsedNotTheEnglishDefault(): void
    {
        $modal = new FindInFilesModal(static fn () => null, translator: new Translator('fr'));
        $modal->open('Find in Files');

        $this->assertStringContainsString('Projet entier', $this->screen($modal), 'the injected translator (fr), not the English default, seeded the scope label');
    }

    public function testReopenClearsAllFieldsSelectionAndSearchingState(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->handleInput('f', $this->kb);
        $modal->handleInput("\t", $this->kb);
        $modal->handleInput('r', $this->kb);
        $modal->handleInput("\t", $this->kb);
        $modal->handleInput('m', $this->kb);
        $modal->setSearching();
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        $modal->scrollPreview(12);
        $this->compose($modal); // windowStart only updates inside layout()
        $this->assertGreaterThan(0, Probe::get($modal, 'windowStart'), 'sanity: windowStart genuinely moved before reopening');

        $modal->open('Find in Files');

        // Read BEFORE any further layout(): windowStart's early-return (when
        // the (now-empty) list already fits) would otherwise recompute it to
        // 0 regardless, masking open()'s own reset.
        $this->assertSame(0, Probe::get($modal, 'selected'), 'selection reset to 0, not -1');
        $this->assertSame(0, Probe::get($modal, 'windowStart'), 'window reset to 0, not -1');

        $screen = $this->screen($modal);
        $this->assertStringNotContainsString('Find: f', $screen, 'the query cleared');
        $this->assertStringNotContainsString('Replace: r', $screen, 'the replace field cleared');
        $this->assertStringNotContainsString('Mask: m', $screen, 'the mask field cleared');
        $this->assertStringNotContainsString('searching…', $screen, 'searching reset to false');
    }

    public function testInvalidateFiresOnEveryMutatingAction(): void
    {
        $modal = $this->modalCountingInvalidate();
        $expected = 0;

        $modal->open('Find in Files');
        $this->assertSame(++$expected, $this->invalidateCount, 'open()');

        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $this->assertSame(++$expected, $this->invalidateCount, 'setScopes()');

        $modal->setOptions(['regex' => true, 'case' => false, 'word' => false, 'preserve' => false, 'tokens' => 'any']);
        $this->assertSame(++$expected, $this->invalidateCount, 'setOptions()');

        $modal->setMaskValue('*.php');
        $this->assertSame(++$expected, $this->invalidateCount, 'setMaskValue()');

        $modal->setSearching();
        $this->assertSame(++$expected, $this->invalidateCount, 'setSearching()');

        $modal->setPreview($this->results());
        $this->assertSame(++$expected, $this->invalidateCount, 'setPreview()');

        $modal->handleInput("\t", $this->kb); // switch_field
        $this->assertSame(++$expected, $this->invalidateCount, 'switch_field key handler');

        $modal->handleInput("\x1bs", $this->kb); // alt+s → cycleScope
        $this->assertSame(++$expected, $this->invalidateCount, 'cycleScope()');

        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;
        $modal->clickAt($optionsRow - 2, $box->col + 3); // setActiveField('query') via a click
        $this->assertSame(++$expected, $this->invalidateCount, 'setActiveField()');

        $modal->handleInput('x', $this->kb); // notifyField() via typing
        $this->assertSame(++$expected, $this->invalidateCount, 'notifyField()');

        $modal->setHistory(['old-query']);
        $modal->handleInput("\x1bp", $this->kb); // alt+up → recallHistory()
        $this->assertSame(++$expected, $this->invalidateCount, 'recallHistory()');

        $modal->handleInput("\t", $this->kb); // query → replace
        $modal->handleInput('y', $this->kb); // non-empty replace → inPreview()
        $expected = $this->invalidateCount; // switch_field + notifyField already counted above
        $modal->handleInput("\x1b[3~", $this->kb); // Delete → toggleExcludeSelected()
        $this->assertSame($expected + 1, $this->invalidateCount, 'toggleExcludeSelected()');
        $expected = $this->invalidateCount;

        $modal->handleInput("\x1b[B", $this->kb); // cursor_down → step()
        $this->assertSame($expected + 1, $this->invalidateCount, 'step()');
        $expected = $this->invalidateCount;

        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $modal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1); // arm resize
        $modal->continueDragTo($box->startRow + $box->rows + 1, $box->col + $box->width + 1, release: true);
        $this->assertSame($expected + 1, $this->invalidateCount, 'continueDragTo() resizing branch');
        $expected = $this->invalidateCount;

        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $modal->clickAt($box->startRow, $box->col + 2); // arm a title-bar move
        $modal->continueDragTo($box->startRow + 1, $box->col + 1, release: true);
        $this->assertSame($expected + 1, $this->invalidateCount, 'continueDragTo() moving branch');
        $expected = $this->invalidateCount;

        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $modal->hoverAt($box->startRow, $box->col + $box->width - 2); // onto the × → hotspot changes
        $this->assertSame($expected + 1, $this->invalidateCount, 'hoverAt() hotspot change');
        $expected = $this->invalidateCount;

        // Off the × first, onto a row hoverAt() doesn't specially handle: this
        // alone resets the hotspot to null, isolating the NEXT call's hoveredRow
        // change from also re-firing the hotspot-changed invalidate.
        $modal->hoverAt($box->startRow, $box->col + 2);
        $expected = $this->invalidateCount;

        $modal->hoverAt($box->windowTop() + 2, $box->col + 3); // a preview row → hoveredRow changes
        $this->assertSame($expected + 1, $this->invalidateCount, 'hoverAt() hoveredRow change');
        $expected = $this->invalidateCount;

        $optionsRow = $box->windowTop() - 3;
        $modal->hoverAt($optionsRow, $box->col + 3); // an option tag → hoverZone() changes
        $this->assertSame($expected + 1, $this->invalidateCount, 'hoverZone()');

        $expected = $this->invalidateCount;
        $modal->close();
        $this->assertSame($expected + 1, $this->invalidateCount, 'close()');
    }

    public function testSetPreviewResetsSelectionToTheTopEvenWhenThereAreNoMatches(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        $modal->scrollPreview(12);
        $this->compose($modal); // windowStart only updates inside layout()
        $this->assertGreaterThan(0, Probe::get($modal, 'windowStart'), 'sanity: windowStart genuinely moved before the reset');

        $modal->setPreview([]);

        $this->assertSame(0, Probe::get($modal, 'selected'), 'selected reset to 0, not -1');
        $this->assertSame(0, Probe::get($modal, 'windowStart'), 'windowStart reset to 0, not -1');
    }

    public function testCycleScopeWithASingleLabelStaysAtZero(): void
    {
        $modal = $this->modal();
        $scopeIdx = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
        ));
        $modal->open('Find in Files'); // default scopeLabels: exactly one entry

        $modal->handleInput("\x1bs", $this->kb);
        $modal->handleInput("\x1bs", $this->kb);

        $this->assertSame([0, 0], $scopeIdx, 'a single-label carousel never advances past index 0');
    }

    public function testTypingResetsHistoryBrowsingSoTheNextRecallStartsFromTheTop(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setHistory(['recent', 'older']);

        $modal->handleInput("\x1bp", $this->kb); // alt+up → 'recent' (historyCursor=0)
        $modal->handleInput('z', $this->kb); // typing must reset historyCursor to -1

        $modal->handleInput("\x1bp", $this->kb); // alt+up again → should recall history[0] = 'recent'

        $this->assertStringContainsString('Find: recent', $this->screen($modal), 'typing left history-browsing at exactly -1, so the next alt+up recalls the newest entry');
    }

    public function testExcludedIndicesCollectsEveryExcludedMatchNotJustTheFirst(): void
    {
        $replaced = null;
        $modal = $this->modalTypedForReplaceAll($replaced);
        // rows: [hdrA, match0, match1, hdrB, match2]; selected starts on match0

        $modal->handleInput("\x1b[3~", $this->kb); // exclude match0 (selected)
        $modal->handleInput("\x1b[B", $this->kb); // → match1
        $modal->handleInput("\x1b[3~", $this->kb); // exclude match1 then re-include: excluded[1]=false stays in the middle
        $modal->handleInput("\x1b[3~", $this->kb);
        $modal->handleInput("\x1b[B", $this->kb); // → match2 (skips hdrB)
        $modal->handleInput("\x1b[3~", $this->kb); // exclude match2

        $modal->handleInput("\x1ba", $this->kb); // replace all
        $this->assertSame(['bar', [0, 2]], $replaced, 'both excluded matches are reported, not truncated to the first');
    }

    public function testToggleExcludeWithNoSelectedRowIsANoOp(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview([]); // no display rows at all → the selected row is null
        $modal->handleInput("\t", $this->kb);
        $modal->handleInput('x', $this->kb); // non-empty replace → inPreview()

        $modal->handleInput("\x1b[3~", $this->kb); // Delete: must not crash on a null row

        $this->addToAssertionCount(1);
    }

    public function testScrollPreviewMovesExactlyByTheGivenDelta(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many); // selected starts at display index 1

        $modal->scrollPreview(3);
        $this->assertSame(4, Probe::get($modal, 'selected'), 'moved exactly 3 rows down, not 4');

        $modal->scrollPreview(1);
        $this->assertSame(5, Probe::get($modal, 'selected'), 'moved exactly 1 row down');

        $modal->scrollPreview(-1);
        $this->assertSame(4, Probe::get($modal, 'selected'), 'moved exactly 1 row up');
    }

    public function testJumpSelectedWithNoRowIsANoOpEvenWithAJumpCallbackWired(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $modal->setCallbacks(new FindModalCallbacks(
            onJump: static function (string $p, int $l) use (&$jumped): void {
                $jumped = [$p, $l];
            },
        ));
        $modal->open('Find in Files');
        $modal->setPreview([]); // selected row is null

        $modal->handleInput("\r", $this->kb); // accept_completion → jumpSelected(); must not crash

        $this->assertNull($jumped);
    }

    public function testLayoutUsesTheBoundSpanNotTheSum(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files'); // no setPreview: displayRows=[] keeps maxPreview content-invariant

        $modal->layout([0, 10, 24, 100], CaretVisibility::Shown); // columns = 90
        $boxA = $modal->lastBox();
        $this->assertNotNull($boxA);
        $modal->layout([0, 20, 24, 110], CaretVisibility::Shown); // columns = 90 too
        $boxB = $modal->lastBox();
        $this->assertNotNull($boxB);
        $this->assertSame($boxA->width, $boxB->width, 'width is driven by columns (right-left), independent of absolute position');
        $this->assertSame($boxA->col + 10, $boxB->col, 'the box shifted by exactly the pane-left delta');

        $modal->layout([10, 0, 34, 100], CaretVisibility::Shown); // rows = 24
        $boxC = $modal->lastBox();
        $this->assertNotNull($boxC);
        $modal->layout([20, 0, 44, 100], CaretVisibility::Shown); // rows = 24 too
        $boxD = $modal->lastBox();
        $this->assertNotNull($boxD);
        $this->assertSame($boxC->rows, $boxD->rows, 'popup row count is driven by rows (bottom-top), independent of absolute position');
        $this->assertSame($boxC->startRow + 10, $boxD->startRow, 'the box shifted by exactly the pane-top delta');
    }

    public function testLayoutCentersUsingTheDocumentedFormula(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $this->screen($modal); // default 100×24 bounds; box width comfortably unclamped

        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $innerWidth = $box->width - ModalFrame::CHROME_COLS;
        $expectedCol = max(0, intdiv(100 - $innerWidth - ModalFrame::CHROME_COLS, num2: 2));
        $this->assertSame($expectedCol, $box->col, 'centering follows (columns - width - CHROME_COLS) / 2 exactly');
    }

    public function testMaxPreviewFloorAndDefaultRowCountAreExact(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // 5 display rows

        $modal->layout([0, 0, 13, 100], CaretVisibility::Shown); // rows=13 → rows-13=0
        $boxFloor = $modal->lastBox();
        $this->assertNotNull($boxFloor);
        $this->assertSame(1, $boxFloor->count, 'the max(1, ...) floor kept exactly one preview row, not zero');

        $modal->layout([0, 0, 14, 100], CaretVisibility::Shown); // rows=14 → rows-13=1
        $boxOne = $modal->lastBox();
        $this->assertNotNull($boxOne);
        $this->assertSame(1, $boxOne->count, 'the natural minimum of 1 stayed 1, not 2');
    }

    public function testTitleBarSpansTheFullBoxWidth(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $placed = $modal->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $this->assertNotNull($placed);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $titleBar = AnsiUtils::stripAnsiCodes($placed['rows'][0]);
        $this->assertSame($box->width, mb_strwidth($titleBar), 'the title bar spans width + CHROME_COLS, matching the rest of the box');
    }

    public function testTitleBarTextHasTheDocumentedGapAndOrder(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview([]); // '0 results'

        $screen = $this->screen($modal);
        $this->assertStringContainsString('Find in Files  0 results', $screen, 'title, a two-space gap, then the status — in that order');
    }

    public function testPreviewRowHighlightsTrackSelectionAndHoverPrecisely(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many); // displayRows: [hdr, hit0..hit13]; selected starts at index 1 ("hit 0")

        $box = function () use ($modal): \Porta\Overlay\Region\ModalBox {
            $this->compose($modal);
            $box = $modal->lastBox();
            \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

            return $box;
        };
        $b = $box();
        $selectedLine = $this->compose($modal)[$b->windowTop() + 1]; // local offset 1 = display index 1 = selected

        $modal->handleInput("\x1b[B", $this->kb); // selection moves to index 2 ("hit 1")
        $noLongerSelectedLine = $this->compose($modal)[$box()->windowTop() + 1]; // same screen row, still "hit 0" text
        $this->assertNotSame($selectedLine, $noLongerSelectedLine, 'the selected-row highlight moved off once the selection moved away');

        // Hover: a specific row highlights, an untouched sibling does not.
        $b = $box();
        $untouchedBefore = $this->compose($modal)[$b->windowTop() + 4];
        $this->assertTrue($modal->hoverAt($b->windowTop() + 4, $b->col + 3));
        $hoveredLine = $this->compose($modal)[$box()->windowTop() + 4];
        $this->assertNotSame($untouchedBefore, $hoveredLine, 'the hovered row highlighted at its own (windowStart + i) position');
    }

    public function testStartRowFloorAndDivisorAreExact(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files'); // no preview: content-invariant popup size

        $modal->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $expectedStartRow = max(0, intdiv(24 - $box->rows, num2: 3));
        $this->assertSame($expectedStartRow, $box->startRow, 'startRow follows (rows - popupRows) / 3 exactly');
    }

    public function testStartRowFloorBindsOnATooShortTerminal(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');

        // The popup needs well over 5 rows to render at all: rows - popupRows
        // is deeply negative here, so the max(0, ...) floor must bind.
        $modal->layout([0, 0, 5, 100], CaretVisibility::Shown);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $this->assertGreaterThan(5, $box->rows, 'sanity: the popup genuinely does not fit in 5 rows');
        $this->assertSame(0, $box->startRow, 'the floor kept startRow at 0, not negative');
    }

    public function testUserPositionOverrideRequiresBothCoordinates(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $centeredRow = $box->startRow;
        $centeredCol = $box->col;

        // Arm a move, drag, but release with only ONE coordinate surviving by
        // resetting the other via a fresh open — a half-set override must not
        // freeze the position; the modal should recenter.
        Probe::set($modal, 'userRow', 5);
        Probe::set($modal, 'userCol', null);
        $this->screen($modal);
        $half = $modal->lastBox();
        $this->assertNotNull($half);
        $this->assertSame($centeredRow, $half->startRow, 'a null userCol keeps the layout centered, ignoring a stray userRow');
        $this->assertSame($centeredCol, $half->col);
    }

    public function testTitleBarDragClampsAtTheBottomRightPaneEdgeToo(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $modal->clickAt($box->startRow, $box->col + 2);
        $modal->continueDragTo(1000, 1000, release: true);
        $this->screen($modal);
        $pinned = $modal->lastBox();
        $this->assertNotNull($pinned);
        $this->assertSame(24 - $pinned->rows, $pinned->startRow, 'clamped so the box still ends within the 24-row pane');
        $this->assertSame(100 - $pinned->width, $pinned->col, 'clamped so the box still ends within the 100-column pane');
    }

    public function testCornerDragUserRowsFormulaIsExact(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $modal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1);
        $relRow = $box->startRow + 20; // comfortably above the max(3, ...) floor
        $modal->continueDragTo($relRow, $box->col, release: false);

        $this->assertSame(max(3, $relRow - $box->startRow + 1 - 11), Probe::get($modal, 'userRows'), 'userRows follows relRow - startRow + 1 - 11 exactly');
    }

    public function testBoxWidthBaselineFormulaWithNoPreviewContent(): void
    {
        $modal = $this->modal();
        $translator = new Translator('en');
        $title = str_repeat('A', times: 50);
        $modal->open($title);
        // No setPreview(): displayRows=[] keeps the per-row loop from contributing.

        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $statusW = mb_strwidth($translator->trans('%count% results', ['%count%' => 0]));
        $expectedInner = max(mb_strwidth($title) + $statusW + 10, mb_strwidth('') + 9, PopupMetrics::FIND_MIN_WIDTH);
        $this->assertSame($expectedInner, $box->width - ModalFrame::CHROME_COLS, 'title_w + status_w + 10, exactly');
    }

    public function testBoxWidthIncorporatesTheQueryTextWidthExactly(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files'); // short title, so the query dominates
        $query = str_repeat('Q', times: 60);
        foreach (str_split($query) as $ch) {
            $modal->handleInput($ch, $this->kb);
        }

        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $this->assertSame(mb_strwidth($query) + 9, $box->width - ModalFrame::CHROME_COLS, 'a long query widens the box by query_w + 9');
    }

    public function testBoxWidthIncorporatesTheWidestVisibleRowTextExactly(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files'); // short title, so the row text dominates
        $text = str_repeat('B', times: 60);
        $modal->setPreview([['path' => 'f.php', 'line' => 0, 'text' => $text, 'ranges' => []]]);

        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $this->assertSame(mb_strwidth($text) + 8, $box->width - ModalFrame::CHROME_COLS, 'a long match line widens the box by text_w + 8');
    }

    public function testBoxWidthClampsToThePaneMarginWhenContentIsWide(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview([['path' => 'f.php', 'line' => 0, 'text' => str_repeat('C', times: 200), 'ranges' => []]]);

        $this->compose($modal, columns: 60, rows: 24);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $this->assertSame(60 - PopupMetrics::CENTERED_MARGIN, $box->width - ModalFrame::CHROME_COLS, 'clamped to columns - CENTERED_MARGIN, not beyond');
    }

    public function testBoxWidthNeverExceedsTheEightyColumnComfortCap(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview([['path' => 'f.php', 'line' => 0, 'text' => str_repeat('D', times: 200), 'ranges' => []]]);

        $this->compose($modal, columns: 200, rows: 24);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $this->assertSame(80, $box->width - ModalFrame::CHROME_COLS, 'capped at exactly 80, not 79 or 81');
    }

    public function testCornerDragWidthClampsToThePaneMarginNotBeyond(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $modal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1);
        $modal->continueDragTo($box->startRow + $box->rows, $box->col + 1000, release: true);
        $this->screen($modal);
        $grown = $modal->lastBox();
        $this->assertNotNull($grown);
        $this->assertSame(100 - PopupMetrics::CENTERED_MARGIN, $grown->width - ModalFrame::CHROME_COLS, 'a huge user width still clamps to columns - CENTERED_MARGIN');
    }

    public function testReplaceHintTextMatchesThePreviewStateExactly(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // 3 matches, none excluded

        $notInPreview = $this->screen($modal);
        $this->assertStringContainsString('  (Tab · Alt+A)', $notInPreview, 'the non-preview hint, with its leading gap');
        $this->assertStringNotContainsString('(Del exclude', $notInPreview);

        $modal->handleInput("\t", $this->kb);
        $modal->handleInput('x', $this->kb);
        $inPreview = $this->screen($modal);
        $this->assertStringContainsString('  (Del exclude · Alt+A replace 3)', $inPreview, 'the preview hint, with its leading gap and the substituted count');
        $this->assertStringNotContainsString('(Tab · Alt+A)', $inPreview);
    }

    public function testCaretVisibilityTracksFocusAndActiveFieldExactly(): void
    {
        // Ground truth: only THIS exact byte sequence means "the caret is
        // showing here" — a relative same/different comparison between states
        // can't tell a genuine flip from the ternary's two branches simply
        // swapping which state shows it.
        $marker = new \Porta\Ui\CursorStyle()->marker();

        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;
        $rows = ['query' => $optionsRow - 2, 'replace' => $optionsRow - 1, 'mask' => $optionsRow + 1];
        $order = ['query', 'replace', 'mask'];

        foreach ($order as $field) {
            $row = $rows[$field];
            $shownActive = $this->composeWithFocus($modal, CaretVisibility::Shown)[$row];
            $hiddenActive = $this->composeWithFocus($modal, CaretVisibility::Hidden)[$row];
            $modal->handleInput("\t", $this->kb); // move activeField off this field
            $shownInactive = $this->composeWithFocus($modal, CaretVisibility::Shown)[$row];

            $this->assertStringContainsString($marker, $shownActive, "{$field}: focused AND active shows the caret");
            $this->assertStringNotContainsString($marker, $hiddenActive, "{$field}: not focused hides the caret, even while active");
            $this->assertStringNotContainsString($marker, $shownInactive, "{$field}: not active hides the caret, even while focused");
        }
    }

    public function testMaskHintHasTheDocumentedGap(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');

        $this->assertStringContainsString('  (Tab)', $this->screen($modal), 'the mask hint keeps its leading two-space gap');
    }

    public function testKeptCountReflectsExclusionsExactly(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // 3 matches
        $modal->handleInput("\t", $this->kb);
        $modal->handleInput('x', $this->kb);

        $this->assertStringContainsString('replace 3)', $this->screen($modal), 'nothing excluded yet: all 3 are kept');

        $modal->handleInput("\x1b[3~", $this->kb); // exclude the selected (first) match

        $this->assertStringContainsString('replace 2)', $this->screen($modal), 'one excluded: exactly 2 kept, not 0 or -2');
    }

    public function testOptionZonesClearBetweenRendersSoAStaleWideZoneDoesNotLinger(): void
    {
        $modal = $this->modal();
        $scopeIdx = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
        ));
        $modal->setScopes(['Whole Project Extra Padding Text Here', 'A'], 0);
        $modal->open('Find in Files');
        $modal->setPreview([...$this->results(), ['path' => 'w.php', 'line' => 0, 'text' => str_repeat('W', times: 80), 'ranges' => []]]);
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;
        $optionsLine = AnsiUtils::stripAnsiCodes($lines[$optionsRow]);
        $scopeCol = mb_strpos($optionsLine, needle: 'Scope:');
        $this->assertIsInt($scopeCol);
        $farCol = $scopeCol + 30; // within the long label's old zone, past where 'A' now renders

        $modal->clickAt($optionsRow, $farCol);
        $this->assertSame([1], $scopeIdx, 'the long-label zone cycled to the short scope');

        $this->compose($modal); // re-render with the now-short 'A' label
        $modal->clickAt($optionsRow, $farCol);
        $this->assertSame([1], $scopeIdx, 'clicking past the now-short label is inert once the zones are freshly cleared');
    }

    public function testOptionTagStartsUnhoveredNotHovered(): void
    {
        $styles = Styles::dark();
        $offRegex = FindModalRows::optionTag('.*', OptionTagState::of(on: false, hovered: false), $styles);
        $hoveredRegex = FindModalRows::optionTag('.*', OptionTagState::of(on: false, hovered: true), $styles);

        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;

        $this->assertStringContainsString($offRegex, $lines[$optionsRow], 'the regex tag starts off, not hovered');
        $this->assertStringNotContainsString($hoveredRegex, $lines[$optionsRow]);
    }

    public function testScopeLabelStylingDirectionIsCorrect(): void
    {
        $styles = Styles::dark();
        $label = 'Whole Project';
        $normalLabel = $styles->of(Role::TabAccent)->apply($label);
        $hoveredLabel = $styles->merge(Role::RowHighlightBg, Role::Bright)->apply($label);

        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;

        $this->assertStringContainsString($normalLabel, $lines[$optionsRow], 'the scope label starts in its plain accent styling');
        $this->assertStringNotContainsString($hoveredLabel, $lines[$optionsRow]);
    }

    public function testScopeTextHasItsDocumentedGapAndHint(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview([...$this->results(), ['path' => 'w.php', 'line' => 0, 'text' => str_repeat('W', times: 80), 'ranges' => []]]);

        $screen = $this->screen($modal);
        $this->assertStringContainsString('  Scope: Whole Project', $screen, 'the scope block keeps its leading two-space gap');
        $this->assertStringContainsString('(Alt+S)', $screen, 'the scope hint is not dropped');
    }

    public function testScopeClickZoneBoundariesMatchTheRenderedLabelExactly(): void
    {
        $modal = $this->modal();
        $translator = new Translator('en');
        $scopeIdx = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
        ));
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->open('Find in Files');
        // A wide box (via a long match line) so the zone's end is driven by
        // the label's own width, not clamped short by the box's own $width.
        $modal->setPreview([...$this->results(), ['path' => 'w.php', 'line' => 0, 'text' => str_repeat('W', times: 150), 'ranges' => []]]);
        $lines = $this->compose($modal, columns: 200);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;
        $optionsLine = AnsiUtils::stripAnsiCodes($lines[$optionsRow]);
        $scopeCol = mb_strpos($optionsLine, needle: 'Scope:');
        $this->assertIsInt($scopeCol);

        // Start boundary: exactly at "Scope:" cycles; one column before does not.
        $modal->clickAt($optionsRow, $scopeCol - 1);
        $this->assertSame([], $scopeIdx, 'one column before the label is inert');
        $modal->clickAt($optionsRow, $scopeCol);
        $this->assertSame([1], $scopeIdx, 'exactly at the label start cycles');

        // End boundary: computed from whatever label is active NOW — re-render
        // first, since the click above only updated state, not the zones. Check
        // the non-firing side before the firing one, since firing changes the
        // label (and so the zone) for any click after it.
        $this->compose($modal, columns: 200);
        $label = 'Directory';
        $scopeText = $translator->trans('Scope:').' '.$label.' (Alt+S)';
        $endCol = $scopeCol + mb_strwidth($scopeText) - 1;
        $modal->clickAt($optionsRow, $endCol + 1);
        $this->assertSame([1], $scopeIdx, 'one column past the zone is inert');
        $modal->clickAt($optionsRow, $endCol);
        $this->assertSame([1, 0], $scopeIdx, 'the last column of the zone still cycles');
    }

    public function testOptionsRowLeadingGapAndFullWidthPadding(): void
    {
        $modal = $this->modal();
        // A long title forces a box much wider than the options row's own
        // (short) natural content, so padding to $width is actually exercised.
        $modal->open(str_repeat('T', times: 80));
        $modal->setPreview($this->results());
        $placed = $modal->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $this->assertNotNull($placed);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        // rows: title(0), pad(1), find(2), replace(3), options(4), mask(5), ...
        $optionsRawRow = $placed['rows'][4];

        $this->assertStringContainsString('   .*', AnsiUtils::stripAnsiCodes($optionsRawRow), "the row's own leading space precedes the first tag's own padding");
        $this->assertSame(
            $box->width, // the full wrapped row: rail + pad + content($width) + pad + rail
            mb_strwidth(AnsiUtils::stripAnsiCodes($optionsRawRow)),
            'the options row pads out to the full inner width, not left short',
        );
    }

    public function testFooterOpenHintHasTheDocumentedGap(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());

        // The wrapper (rail + its own pad space) already contributes one
        // leading space regardless of the content — the content's OWN leading
        // space is the second one, right before "⏎".
        $this->assertStringContainsString('  ⏎ open · ', $this->screen($modal), 'the wrapper pad plus the hint\'s own leading space, then the translated hint and separator, in order');
    }

    public function testFooterActionHoverStylingDirectionIsCorrect(): void
    {
        $styles = Styles::dark();
        $label = 'Find window';
        // Only the OPENING of the highlighted variant is checked: the exact
        // trailing restore sequence depends on the surrounding truncate/pad
        // machinery, but "highlighted style opened, then the text" is a
        // stable, unambiguous marker of the hovered rendering.
        $hoveredOpening = $styles->merge(Role::RowHighlightBg, Role::Bright)->apply($label);
        $hoveredOpening = mb_substr($hoveredOpening, start: 0, length: mb_strpos($hoveredOpening, $label) + mb_strlen($label));

        $modal = $this->modal();
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $footerRow = Probe::get($modal, 'box')->footerRow ?? -1;
        $footerLine = AnsiUtils::stripAnsiCodes($lines[$footerRow]);
        $fwCol = mb_strpos($footerLine, needle: 'Find window');
        $this->assertIsInt($fwCol);

        $before = $this->compose($modal)[$footerRow];
        $this->assertStringNotContainsString($hoveredOpening, $before, 'the action starts plain, not highlighted');

        $this->assertTrue($modal->hoverAt($footerRow, $fwCol));
        $after = $this->compose($modal)[$footerRow];
        $labelEnd = mb_strpos($after, $label) + mb_strlen($label);
        $this->assertIsInt($labelEnd);
        $scopeAt = mb_strpos($after, needle: 'Scope', offset: $labelEnd);
        $this->assertIsInt($scopeAt);
        $gap = mb_substr($after, $labelEnd, $scopeAt - $labelEnd);
        $this->assertStringContainsString($hoveredOpening, $after, 'hovering the action highlights it');
        $this->assertStringContainsString(
            $styles->of(Role::Dim)->getAnsiRestore(),
            $gap,
            'the dim pen is re-opened somewhere after the label, before the next text',
        );
    }

    public function testFooterRowPadsToTheFullBoxWidth(): void
    {
        $modal = $this->modal();
        // A long title forces a box much wider than the footer's own (short)
        // natural content, so padding to $width is actually exercised.
        $modal->open(str_repeat('T', times: 80));
        $modal->setPreview($this->results());
        $placed = $modal->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $this->assertNotNull($placed);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        // wrapSections ends every popup with [..., footerRow, pad, bottomBorder].
        $footerRawRow = $placed['rows'][\count($placed['rows']) - 3];

        $this->assertSame(
            $box->width,
            mb_strwidth(AnsiUtils::stripAnsiCodes($footerRawRow)),
            'the footer row pads out to the full box width, not left short',
        );
    }

    public function testClickOutsideBoundsBoundaryChecksOnEachEdge(): void
    {
        $modal = $this->modal();
        $closed = false;
        $modal->setCallbacks(new FindModalCallbacks(
            onClose: static function () use (&$closed): void {
                $closed = true;
            },
        ));

        // No box laid out yet at all: the null-box branch must still close, not crash.
        $modal->open('Find in Files');
        $this->assertTrue($modal->clickAt(0, 0));
        $this->assertTrue($closed);

        $closed = false;
        $modal->open('Find in Files'); // reopen: box survives from the prior layout() call below
        $modal->setPreview($this->results());
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $queryRow = $box->windowTop() - 3 - 2;

        // Left edge: exactly at box->col is inside (focuses the query field, does not close).
        $modal->clickAt($queryRow, $box->col);
        $this->assertFalse($closed, 'exactly at the left edge is inside the box');

        // Right edge: exactly at box->col + box->width is one past the last column, outside.
        $modal->clickAt($queryRow, $box->col + $box->width);
        $this->assertTrue($closed, 'exactly one past the right edge is outside the box');
    }

    public function testHoverAtOnAClosedModalIsANoOpEvenWithAStaleBox(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files'); // lays out a box (below), then closes without clearing it
        $this->screen($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $modal->close();

        $this->assertFalse($modal->hoverAt($box->startRow, $box->col + $box->width - 2), 'closed: the × hover-hotspot must not compute against a stale box');
    }

    public function testFooterHoverReturnValueTracksEitherSubExpression(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $footerLine = AnsiUtils::stripAnsiCodes($lines[$box->footerRow]);
        $fwCol = mb_strpos($footerLine, needle: 'Find window');
        $this->assertIsInt($fwCol);

        // hoverZone(...) alone changes (true), closeChanged is false (never
        // touched the × or corner) → the OR must still report true.
        $this->assertTrue($modal->hoverAt($box->footerRow, $fwCol), 'a genuine zone change reports true even with closeChanged false');

        // Same spot again: hoverZone(...) is now a no-op (false), closeChanged
        // is still false → the OR must report false, not true.
        $this->assertFalse($modal->hoverAt($box->footerRow, $fwCol), 'no zone change and no hotspot change reports false');
    }

    public function testScopeZoneCycleDoesNotAlsoFireAToggle(): void
    {
        $modal = $this->modal();
        $scopeIdx = [];
        $toggled = [];
        $modal->setCallbacks(new FindModalCallbacks(
            onScopeCycle: static function (int $i) use (&$scopeIdx): void {
                $scopeIdx[] = $i;
            },
            onToggleOption: static function (string $n) use (&$toggled): void {
                $toggled[] = $n;
            },
        ));
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $lines = $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $optionsRow = $box->windowTop() - 3;
        $optionsLine = AnsiUtils::stripAnsiCodes($lines[$optionsRow]);
        $scopeCol = mb_strpos($optionsLine, needle: 'Scope:');
        $this->assertIsInt($scopeCol);

        $modal->clickAt($optionsRow, $scopeCol);

        $this->assertSame([1], $scopeIdx);
        $this->assertSame([], $toggled, 'cycling the scope must not also fire a bogus "scope" toggle');
    }

    public function testPreviewIndexAtOnePastTheLastVisibleRowIsInvalid(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);

        $this->assertFalse($modal->hoverAt($box->windowTop() + $box->count, $box->col + 3), 'exactly one row past the last visible row is outside the preview area');
    }

    public function testPreviewIndexAtTheFirstVisibleRowIsValid(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        $modal->scrollPreview(20); // scroll past the header, so windowStart's row (i=0) is a genuine match
        $this->compose($modal);
        $box = $modal->lastBox();
        $this->assertNotNull($box);
        $this->assertGreaterThan(0, $box->windowStart, 'sanity: scrolled past the file header');
        // Prime hoveredRow with a DIFFERENT row first, so a wrongly-null result
        // at i=0 still shows up as a change (a boolean return alone can't tell
        // "became the right index" from "became null instead").
        $modal->hoverAt($box->windowTop() + 3, $box->col + 3);

        $modal->hoverAt($box->windowTop(), $box->col + 3); // i = 0, exactly the top edge

        $this->assertSame($box->windowStart, Probe::get($modal, 'hoveredRow'), 'the top-most visible row (i=0) resolves to a real index, not null');
    }

    public function testWindowStartTernaryAndMinMaxTermsAreExact(): void
    {
        // 22 display rows: hdr(0) a0..a4(1-5) hdr(6) b0..b14(7-21).
        $results = [];
        for ($i = 0; $i < 5; ++$i) {
            $results[] = ['path' => 'a.php', 'line' => $i, 'text' => "a{$i}", 'ranges' => []];
        }
        for ($i = 0; $i < 15; ++$i) {
            $results[] = ['path' => 'b.php', 'line' => $i, 'text' => "b{$i}", 'ranges' => []];
        }

        // Scenario A: selected is the first match of a LATER group (predecessor
        // is a header → the ternary's true branch), with windowStart and
        // count-maxPreview both larger, so `top` alone drives the result.
        $modalA = $this->modal();
        $modalA->open('Find in Files');
        $modalA->setPreview($results);
        Probe::set($modalA, 'selected', 7);
        Probe::set($modalA, 'windowStart', 10);
        $modalA->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $this->assertSame(6, $modalA->lastBox()?->windowStart, 'true branch: top = selected - 1 = 6');

        // Scenario B: selected is deep with no header immediately before it
        // (the ternary's false branch), and count-maxPreview is deliberately
        // the smallest term.
        $modalB = $this->modal();
        $modalB->open('Find in Files');
        $modalB->setPreview($results);
        Probe::set($modalB, 'selected', 20);
        Probe::set($modalB, 'windowStart', 999);
        $modalB->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $this->assertSame(12, $modalB->lastBox()?->windowStart, 'count(22) - maxPreview(10) = 12 wins the min()');

        // Scenario C: same false branch, but with `top` deliberately the
        // smallest term instead.
        $modalC = $this->modal();
        $modalC->open('Find in Files');
        $modalC->setPreview($results);
        Probe::set($modalC, 'selected', 8);
        Probe::set($modalC, 'windowStart', 15);
        $modalC->layout([0, 0, 24, 100], CaretVisibility::Shown);
        $this->assertSame(8, $modalC->lastBox()?->windowStart, 'false branch: top = selected - 0 = 8 wins the min()');
    }
}
