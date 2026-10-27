<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\OverlayHost;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Type-to-filter in the list overlays — PhpStorm's speed search.
 *
 * Round 8 gave the results PANEL a filter and recorded that the OVERLAYS were
 * left out: `Ctrl+F12` on a forty-method class opened a list you could only
 * walk, four keys deep (up, down, Enter, Esc), and every printable keystroke
 * fell on the floor.
 *
 * The tests below are mostly about the INDEX SPACE, because that is where a
 * filtered picker goes wrong: `selected`, `hovered` and the mouse hit-test all
 * count rows, and a list that is narrowed for the eye but not for one of those
 * three opens the item you did not look at.
 */
final class ListOverlayFilterTest extends TestCase
{
    /** @return list<array{label: string, hint: string, value: string}> */
    private static function symbols(): array
    {
        return [
            ['label' => 'Service', 'hint' => 'Class', 'value' => 'C'],
            ['label' => '  handle', 'hint' => 'Method', 'value' => 'H'],
            ['label' => '  hydrate', 'hint' => 'Method', 'value' => 'Y'],
            ['label' => '  $logger', 'hint' => 'Property', 'value' => 'L'],
        ];
    }

    private function host(): OverlayHost
    {
        return new OverlayHost(TestTui::create(100, 24));
    }

    private function keys(): Keybindings
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

    private function type(OverlayHost $host, string $text): void
    {
        foreach (\mb_str_split($text) as $char) {
            $host->handleInput($char, $this->keys());
        }
    }

    /**
     * The composited frame with its ANSI intact.
     *
     * {@see canvas()} strips it, which is right for asking what the rows SAY
     * and blind to how far a highlight runs — and "how far" is the whole
     * question for a padded row: the padding happens BEFORE the style is
     * applied, so dropping it leaves a background bar that stops under the
     * text instead of crossing the box.
     *
     * @return list<string>
     */
    private function rawCanvas(OverlayHost $host): array
    {
        $lines = \array_fill(start_index: 0, count: 24, value: \str_repeat(' ', times: 100));
        foreach ($host->layout([0, 0, 24, 100]) as $box) {
            foreach ($box['rows'] as $i => $rowText) {
                $lines[$box['top'] + $i] = OverlayCompositor::into($lines[$box['top'] + $i], $box['col'], $box['width'], 100, $rowText);
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function canvas(OverlayHost $host): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $this->rawCanvas($host));
    }

    public function testTypingNarrowsTheListAndEnterPicksFromWhatIsLeft(): void
    {
        $picked = null;
        $host = $this->host();
        $host->openList('File Structure', self::symbols(), static function (string $v) use (&$picked): void {
            $picked = $v;
        });

        $this->type($host, 'hyd');
        $host->handleInput("\r", $this->keys());

        $this->assertSame('Y', $picked, 'the only surviving row, not the first row of the FULL list');
    }

    /**
     * The hint is matched too, which is the same pair the results panel uses
     * (a match's text and its path). In File Structure the hint is the symbol
     * KIND, so this is how you ask a class for its methods.
     */
    public function testTheHintIsMatchedAsWellAsTheLabel(): void
    {
        $host = $this->host();
        $host->openList('File Structure', self::symbols(), static fn (): null => null);

        $this->type($host, 'method');

        $plain = \implode("\n", $this->canvas($host));
        $this->assertStringContainsString('handle', $plain);
        $this->assertStringContainsString('hydrate', $plain);
        $this->assertStringNotContainsString('$logger', $plain, 'a property is not a method');
        $this->assertStringNotContainsString('Service', $plain, 'nor is the class');
    }

    /**
     * The selection returns to the top on every filter edit. Left where it was,
     * the highlighted row is a DIFFERENT item after one keystroke, and Enter
     * opens whatever slid under the old index.
     */
    public function testAFilterEditPutsTheSelectionBackOnTheFirstSurvivor(): void
    {
        $picked = null;
        $host = $this->host();
        $host->openList('L', self::symbols(), static function (string $v) use (&$picked): void {
            $picked = $v;
        });

        $host->handleInput("\x1b[B", $this->keys()); // down: 'handle'
        $host->handleInput("\x1b[B", $this->keys()); // down: 'hydrate'
        $this->type($host, 'h'); // Service and $logger go
        $host->handleInput("\r", $this->keys());

        $this->assertSame('H', $picked, 'the first row of the NARROWED list');
    }

    /**
     * Esc clears the filter before it closes the surface. A typo is a
     * keystroke; losing the list you were reading to one is a punishment.
     */
    public function testEscClearsTheFilterFirstAndClosesOnTheSecondPress(): void
    {
        $host = $this->host();
        $host->openList('L', self::symbols(), static fn (): null => null);

        $this->type($host, 'zz');
        $host->handleInput("\x1b", $this->keys());
        $this->assertNotNull(Probe::get($host, 'overlay'), 'still open, filter cleared');
        $this->assertStringContainsString('$logger', \implode("\n", $this->canvas($host)), 'the whole list is back');

        $host->handleInput("\x1b", $this->keys());
        $this->assertNull(Probe::get($host, 'overlay'), 'the second Esc closes it');
    }

    public function testBackspaceWidensTheFilterAgain(): void
    {
        $host = $this->host();
        $host->openList('L', self::symbols(), static fn (): null => null);

        $this->type($host, 'hyd');
        $this->assertStringNotContainsString('handle', \implode("\n", $this->canvas($host)));

        $host->handleInput("\x7f", $this->keys());
        $host->handleInput("\x7f", $this->keys());
        $this->assertStringContainsString('handle', \implode("\n", $this->canvas($host)), 'back to `h`');
    }

    /**
     * A filter that matches nothing leaves the surface open, saying so.
     *
     * And it must not crash: every movement key steps the selection modulo the
     * row count, so an empty result is a division by zero one keypress away —
     * `Ctrl+F12`, three letters, arrow down.
     */
    public function testAFilterThatMatchesNothingSaysSoAndSurvivesEveryKey(): void
    {
        $picked = null;
        $host = $this->host();
        $host->openList('L', self::symbols(), static function (string $v) use (&$picked): void {
            $picked = $v;
        });

        $this->type($host, 'zzz');
        $this->assertStringContainsString('no matches', \implode("\n", $this->canvas($host)));

        $host->handleInput("\x1b[B", $this->keys());
        $host->handleInput("\x1b[A", $this->keys());
        $host->handleInput("\x05", $this->keys()); // ctrl+e
        $host->handleInput("\r", $this->keys());

        $this->assertNotNull(Probe::get($host, 'overlay'), 'still open');
        $this->assertNull($picked, 'and picked nothing');
    }

    /**
     * The filter row, pinned whole.
     *
     * Round 7's completion type column taught this: a placement assertion that
     * checks the row merely CONTAINS what it expects passes for a cell that has
     * lost a space, gained one, or put its glyph on the wrong side. Nine
     * separate mutants lived in this one line's string arithmetic, and every
     * `assertStringContainsString` in this file left all nine alive.
     */
    public function testTheFilterRowReadsExactlyAsWritten(): void
    {
        $host = $this->host();
        $host->openList('File Structure', self::symbols(), static fn (): null => null);

        $this->type($host, 'hyd');

        $rows = $this->canvas($host);
        $legend = null;
        foreach ($rows as $i => $row) {
            if (!\str_contains($row, '≡')) {
                continue;
            }
            $legend = $i;
        }
        $this->assertNotNull($legend, 'the filter box is drawn');
        // The glyph is the box's legend; the value row below it is the frame's
        // `│ `, the field's heavy rail and a space, the text, padding, and the
        // closing rail — it takes the keys, so its border is the focused one.
        // The exact count is the point: it is what the concat mutants move.
        $this->assertMatchesRegularExpression('/^ *│ ┏ ≡ ━+┓ │ *$/u', $rows[$legend]);
        $this->assertMatchesRegularExpression('/^ *│ ┃ hyd +┃ │ *$/u', $rows[$legend + 1]);

        // And the highlight reaches the far side. `pad: true` pads the text
        // before the style wraps it, so without it the toolbar bar stops after
        // `hyd` — a half-drawn row that the stripped text above reads as
        // perfect.
        $this->assertMatchesRegularExpression(
            '/hyd +(?:\x1b\[[0-9;]*m)*┃(?:\x1b\[[0-9;]*m)*\x1b\[49m/u',
            \implode("\n", $this->rawCanvas($host)),
            'the filter row is highlighted across the box, not just under its text',
        );
    }

    /**
     * The empty-state row too, and for the same reason: `no matches` shifted
     * one column left reads as correct to any `assertStringContainsString`.
     */
    public function testTheEmptyStateRowReadsExactlyAsWritten(): void
    {
        $host = $this->host();
        $host->openList('File Structure', self::symbols(), static fn (): null => null);

        $this->type($host, 'zz');

        $rows = $this->canvas($host);
        $emptyRow = null;
        foreach ($rows as $row) {
            if (!\str_contains($row, 'no matches')) {
                continue;
            }
            $emptyRow = $row;
        }
        $this->assertNotNull($emptyRow, 'the empty state is drawn');
        $this->assertMatchesRegularExpression('/^ *│  no matches +│ *$/u', $emptyRow);
        $this->assertMatchesRegularExpression(
            '/no matches {2,}\x1b\[49m/u',
            \implode("\n", $this->rawCanvas($host)),
            'and so is the empty-state row',
        );
    }

    /**
     * Case folding is MULTIBYTE on both sides.
     *
     * `strtolower()` is byte-wise: it leaves `É` alone, so a needle and a label
     * that differ only in case stop matching the moment either carries an
     * accent. Three separate calls do this folding — the needle, the label and
     * the hint — and an ASCII-only fixture cannot tell any of them from the
     * broken version.
     */
    public function testAccentedTextFoldsCaseOnEverySideOfTheMatch(): void
    {
        $items = [
            ['label' => 'Élan', 'hint' => 'Öffentlich', 'value' => 'E'],
            ['label' => 'plain', 'hint' => 'ascii', 'value' => 'P'],
        ];

        $host = $this->host();
        $host->openList('L', $items, static fn (): null => null);
        $this->type($host, 'éla'); // lowercase needle, uppercase label
        $this->assertStringContainsString('Élan', \implode('
', $this->canvas($host)), 'the label folded');

        $host = $this->host();
        $host->openList('L', $items, static fn (): null => null);
        $this->type($host, 'ÉLA'); // uppercase needle
        $this->assertStringContainsString('Élan', \implode('
', $this->canvas($host)), 'the needle folded');

        $host = $this->host();
        $host->openList('L', $items, static fn (): null => null);
        // The HINT, and its uppercase letter has to be the NON-ASCII one:
        // `strtolower('Größe')` already lowercases the ASCII `G` and leaves the
        // rest, which is indistinguishable from the multibyte fold.
        $this->type($host, 'öffent');
        $plain = \implode('
', $this->canvas($host));
        $this->assertStringContainsString('Élan', $plain, 'the hint folded');
        $this->assertStringNotContainsString('plain', $plain);
    }

    /**
     * Clearing the filter — by Esc or by Backspace — puts the selection back at
     * the top, because the rows under it changed.
     *
     * Both paths are asserted through what ENTER PICKS rather than through the
     * screen: the list looks identical either way, and the whole failure is
     * that the highlighted row is not the one you are looking at.
     */
    public function testClearingTheFilterAlsoResetsTheSelection(): void
    {
        foreach (['esc' => "\x1b", 'backspace' => "\x7f"] as $how => $key) {
            $picked = null;
            $host = $this->host();
            $host->openList('L', self::symbols(), static function (string $v) use (&$picked): void {
                $picked = $v;
            });

            $this->type($host, 'e'); // every row carries an `e`, so nothing is hidden
            $host->handleInput("\x1b[B", $this->keys());
            $host->handleInput("\x1b[B", $this->keys()); // selected: hydrate

            $host->handleInput($key, $this->keys()); // one char, so both clear it
            $host->handleInput("\r", $this->keys());

            $this->assertSame('C', $picked, "{$how} put the selection back on the first row");
        }
    }

    /**
     * Typing continues while nothing matches.
     *
     * The empty-list guard has to return BEFORE the movement keys and still
     * let a character through, or the filter becomes a trap: one keystroke too
     * many and the only way out is Esc.
     */
    public function testYouCanKeepTypingAfterTheFilterStopsMatching(): void
    {
        $host = $this->host();
        $host->openList('L', self::symbols(), static fn (): null => null);

        $this->type($host, 'zx'); // `z` already matches nothing; `x` must still land

        $host->handleInput("\x7f", $this->keys()); // one backspace leaves `z`
        $this->assertStringContainsString('no matches', \implode("\n", $this->canvas($host)), 'still filtered by `z`');

        $host->handleInput("\x7f", $this->keys());
        $this->assertStringContainsString('$logger', \implode("\n", $this->canvas($host)), 'and now the filter is empty');
    }

    /**
     * The mouse counts the narrowed list too.
     *
     * This is the assertion the feature would most plausibly ship without: the
     * keyboard path and the click path index separately, and a click reading
     * the FULL list opens the row three places above the one under the pointer.
     */
    public function testAClickPicksTheRowItIsOverAndNotTheUnfilteredOne(): void
    {
        $picked = null;
        $host = $this->host();
        $host->openList('L', self::symbols(), static function (string $v) use (&$picked): void {
            $picked = $v;
        });

        $this->type($host, 'method');
        $this->canvas($host); // a box to hit-test against
        $box = Probe::get($host, 'overlayBox');
        Assert::assertInstanceOf(ModalBox::class, $box, 'the layout recorded a box to hit-test');

        // The SECOND row of what is drawn: `hydrate`, which is item index 2 of
        // the full list and index 1 of the filtered one.
        $host->clickAt($box->startRow + $box->headerRows + 1, $box->col + 3);

        $this->assertSame('Y', $picked);
    }

    /**
     * The box keeps its width and its column while the filter narrows it.
     *
     * Sized from the surviving rows it shrinks on every keystroke, and being
     * centered it re-centres too — so the popup crawls left-to-right under the
     * eye that is reading it. The width is a property of the LIST, not of this
     * frame's slice of it.
     */
    public function testTheBoxDoesNotWalkAcrossTheScreenAsYouType(): void
    {
        $host = $this->host();
        $host->openList(
            'File Structure',
            [
                ['label' => 'AVeryLongSymbolNameIndeed', 'hint' => 'Class', 'value' => 'C'],
                ['label' => 'hy', 'hint' => 'Method', 'value' => 'Y'],
            ],
            static fn (): null => null,
        );

        $host->layout([0, 0, 24, 100]);
        $before = Probe::get($host, 'overlayBox');
        $this->assertInstanceOf(ModalBox::class, $before, 'a box before typing');

        $this->type($host, 'hy');
        $host->layout([0, 0, 24, 100]);
        $after = Probe::get($host, 'overlayBox');
        $this->assertInstanceOf(ModalBox::class, $after, 'and one after');

        $this->assertSame($before->width, $after->width, 'same width');
        $this->assertSame($before->col, $after->col, 'and the same place');
    }

    /**
     * The filter field is there before anything is typed, a combobox's input,
     * saying what typing does — so the box never grows three rows and
     * re-centres on the first keystroke.
     */
    public function testAnUnfilteredListShowsItsFilterWithAPlaceholder(): void
    {
        $host = $this->host();
        $host->openList('L', self::symbols(), static fn (): null => null);
        $host->layout([0, 0, 24, 100]);
        $before = Probe::get($host, 'overlayBox');

        $rows = \array_values(\array_filter($this->canvas($host), static fn (string $l): bool => '' !== \trim($l)));
        $this->assertStringContainsString('L', $rows[0], 'the title bar');
        $this->assertStringContainsString('≡', $rows[2], 'the filter field, from the start');
        $this->assertStringContainsString('Type to filter', $rows[3]);

        $this->type($host, 'h');
        $host->layout([0, 0, 24, 100]);
        $this->assertInstanceOf(ModalBox::class, $before);
        $this->assertSame($before->startRow, Probe::get($host, 'overlayBox')->startRow, 'the first keystroke moves nothing');
    }
}
