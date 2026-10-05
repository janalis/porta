<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\RuleHalves;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\RowBleed;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The halves read off a painted frame: a rule over or under a shaded cell of
 * a strip's edge row takes that cell's shade, a rule meeting the strip's own
 * `│` turns into it, and nothing else is touched — not a rule over a cell
 * with no shade, not a cell past the strip, not one under a floating surface.
 */
final class RuleHalvesTest extends TestCase
{
    private const string SHADED = "\x1b[48;5;31m ab \x1b[49m";

    /** $width of rule, in the rules' tint — only such a `─` is one. */
    private static function rule(int $width): string
    {
        return Styles::dark()->of(Role::Rule)->apply(\str_repeat('─', $width));
    }

    /**
     * @param list<string>         $lines
     * @param list<WidgetRect>     $rects
     * @param list<DropdownRegion> $floating
     *
     * @return array<string, string> "row:col" => segment
     */
    private static function spans(array $lines, array $rects, array $floating = [], ?\Closure $logical = null): array
    {
        $spans = [];
        foreach (RuleHalves::spans($lines, $rects, $floating, $logical ?? static fn (int $row, int $col): int => $col, Styles::dark()->of(Role::Rule), Styles::dark()->of(Role::HeaderBar)) as $span) {
            self::assertSame(1, $span['width']);
            $spans["{$span['row']}:{$span['col']}"] = $span['segment'];
        }

        return $spans;
    }

    public function testARuleOverAShadedCellTakesItsUpperHalfAndOneUnderItItsLowerHalf(): void
    {
        $spans = self::spans([self::rule(8), '  '.self::SHADED.'  ', self::rule(8)], [new WidgetRect(1, 0, 8, 1)]);

        $upper = "\x1b[38;5;31m▄\x1b[39m";
        $lower = "\x1b[38;5;31m▀\x1b[39m";
        $this->assertSame(
            ['0:2' => $upper, '0:3' => $upper, '0:4' => $upper, '0:5' => $upper, '2:2' => $lower, '2:3' => $lower, '2:4' => $lower, '2:5' => $lower],
            $spans,
            'over and under the four shaded cells, and nowhere else',
        );
    }

    public function testTheStripsOwnLineJoinsTheRule(): void
    {
        $spans = self::spans([self::rule(8), '       │', '       │', self::rule(8)], [new WidgetRect(1, 0, 8, 2)]);

        $this->assertSame(['0:7' => Styles::dark()->of(Role::Rule)->apply('┬'), '3:7' => Styles::dark()->of(Role::Rule)->apply('┴')], $spans);
        $this->assertSame(['0:3'], \array_keys(self::spans([self::rule(8), '   │    '], [new WidgetRect(1, 0, 8, 1)])), 'one cell read, wherever the line stands');
    }

    /** The monochrome theme reverses a selection: its half is the terminal's foreground, dim kept. */
    public function testAReversedCellBleedsItsForeground(): void
    {
        $this->assertSame(['0:0' => "▄\x1b[0m"], self::spans([self::rule(1).' ', "\x1b[7mx\x1b[27m "], [new WidgetRect(1, 0, 2, 1)]));
        $this->assertSame(['0:0' => "\x1b[2m▄\x1b[0m"], self::spans([self::rule(1).' ', "\x1b[2;7mx\x1b[0m "], [new WidgetRect(1, 0, 2, 1)]));
        $this->assertSame(
            ['0:0' => "\x1b[38;5;196m\x1b[2m▄\x1b[0m"],
            self::spans([self::rule(1).' ', "\x1b[2m\x1b[38;5;196m\x1b[7mx\x1b[0m "], [new WidgetRect(1, 0, 2, 1)]),
            'a reversed colour shows as the background',
        );
    }

    public function testEverySgrShapeOfABackgroundIsRead(): void
    {
        $this->assertSame(['0:0' => "\x1b[34m▄\x1b[39m"], self::spans([self::rule(1), "\x1b[44mx"], [new WidgetRect(1, 0, 1, 1)]), 'a basic background');
        $this->assertSame(['0:0' => "\x1b[94m▄\x1b[39m"], self::spans([self::rule(1), "\x1b[104mx"], [new WidgetRect(1, 0, 1, 1)]), 'a bright one');
        $this->assertSame(
            ['0:0' => "\x1b[38;2;1;2;3m▄\x1b[39m"],
            self::spans([self::rule(1), "\x1b[38;5;9;48;2;1;2;3mx"], [new WidgetRect(1, 0, 1, 1)]),
            'a true colour, after a foreground in the same sequence',
        );
        $this->assertSame([], self::spans([self::rule(1), "\x1b[48;5;31m\x1b[49mx"], [new WidgetRect(1, 0, 1, 1)]), 'a background reset is none');
        $this->assertSame([], self::spans([self::rule(1), "\x1b[48;5;31m\x1b[0mx"], [new WidgetRect(1, 0, 1, 1)]), 'and so is a full reset');
        $this->assertSame([], self::spans([self::rule(1), "\x1b[48;5;31m\x1b[mx"], [new WidgetRect(1, 0, 1, 1)]), 'an empty one too');
        $this->assertSame([], self::spans([self::rule(1), "\x1b[7m\x1b[27mx"], [new WidgetRect(1, 0, 1, 1)]), 'reverse turned off');
        $this->assertSame([], self::spans([self::rule(1), "\x1b[38;5;31mx"], [new WidgetRect(1, 0, 1, 1)]), 'a foreground alone is no shade');
    }

    public function testNothingButAStripsEdgeRowUnderARuleIsTouched(): void
    {
        $this->assertSame([], self::spans(['abcdefgh', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)]), 'no rule over it, nor a blank');
        $this->assertSame([], self::spans(["\x1b[48;5;235m        \x1b[49m", '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)]), 'a blank on a shade of its own is no gap');
        $this->assertSame([], self::spans([self::rule(8), '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 2, 1)]), 'the shaded cells are past the strip');
        $this->assertSame([], self::spans([self::rule(8), '  '.self::SHADED.'  ', '  '.self::SHADED.'  ', 'abcdefgh'], [new WidgetRect(2, 0, 8, 1)]), 'a row over the strip is not a rule');
        $this->assertSame([], self::spans([self::rule(8)], [new WidgetRect(0, 0, 8, 1)]), 'nothing over or under the frame');
        $this->assertSame([], self::spans([], [new WidgetRect(0, 0, 8, 1)]));
        $this->assertSame(
            ['0:2' => "\x1b[38;5;31m▄\x1b[39m", '0:3' => "\x1b[38;5;31m▄\x1b[39m"],
            self::spans([self::rule(8), '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], [new DropdownRegion(top: 0, col: 4, width: 4, count: 1)]),
            'a cell under a floating surface is left alone',
        );
        $this->assertSame(
            ['0:4', '0:5'],
            \array_keys(self::spans([self::rule(8), '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], [new DropdownRegion(top: 0, col: 2, width: 2, count: 1)])),
            'and the one just past it is not under it',
        );
    }

    /** On a mirrored screen a laid-out column is found where the reorder drew it. */
    public function testAMirroredStripIsFoundWhereItIsDrawn(): void
    {
        $spans = self::spans([self::rule(8), '      '."\x1b[48;5;31mab\x1b[49m"], [new WidgetRect(1, 0, 2, 1)], [], static fn (int $row, int $col): int => 7 - $col);

        $this->assertSame(['0:6', '0:7'], \array_keys($spans));
    }

    /** A `─` of another tint — a toast's border, a modal's — is no rule: the strip's shade stays out of it. */
    public function testALineOfAnotherTintIsNoRule(): void
    {
        $this->assertSame([], self::spans(["\x1b[38;5;110m────────\x1b[39m", '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)]));
        $this->assertSame([], self::spans(['────────', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)]), 'nor one with no tint at all');
    }

    /** A strip at the frame's top has no rule over it, but its bottom edge still meets the one under it. */
    public function testAStripAtTheTopStillMeetsTheRuleUnderIt(): void
    {
        $this->assertSame(['1:2', '1:3', '1:4', '1:5'], \array_keys(self::spans(['  '.self::SHADED.'  ', self::rule(8)], [new WidgetRect(0, 0, 8, 1)])));
    }

    /** A cell that is no rule ends nothing: the rule further along still takes its halves. */
    public function testARuleThatStartsPastTheStripsEdgeStillCounts(): void
    {
        $this->assertSame(['0:2', '0:3', '0:4', '0:5'], \array_keys(self::spans(['  '.self::rule(6), '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)])));
    }

    /** Every SGR the frame's cells carry is read in order, and only the ones that set what the glyph shows count. */
    public function testTheStateTracksEveryCodeThatChangesTheShade(): void
    {
        $rect = [new WidgetRect(1, 0, 1, 1)];
        $this->assertSame(['0:0' => "\x1b[38;2;1;2;3m▄\x1b[39m"], self::spans([self::rule(1), "\x1b[48;2;1;2;3;1mx"], $rect), 'a true colour takes four parameters, no more');
        $this->assertSame(['0:0' => "\x1b[38;5;31m▄\x1b[39m"], self::spans([self::rule(1), "\x1b[1;3;48;5;31mx"], $rect), 'codes that set no shade are passed over');
        $this->assertSame(['0:0' => "▄\x1b[0m"], self::spans([self::rule(1), "\x1b[2;7;22mx"], $rect), 'dim turned off');
        $this->assertSame(['0:0' => "▄\x1b[0m"], self::spans([self::rule(1), "\x1b[2;7m\x1b[0;7mx"], $rect), 'and by a reset');
        $this->assertSame(['0:0' => "▄\x1b[0m"], self::spans([self::rule(1), "\x1b[38;5;196;7;39mx"], $rect), 'a foreground reset');
        $this->assertSame(['0:0' => "\x1b[31m▄\x1b[0m"], self::spans([self::rule(1), "\x1b[31;7mx"], $rect), 'a basic foreground, reversed');
        $this->assertSame(['0:0' => "\x1b[91m▄\x1b[0m"], self::spans([self::rule(1), "\x1b[91;7mx"], $rect), 'a bright one');
    }

    /** @return iterable<string, array{string, string}> a cell's SGR => the half it gives */
    public static function basicColours(): iterable
    {
        foreach ([30, 37, 90, 97] as $code) {
            yield "foreground {$code}, reversed" => ["\x1b[{$code};7m", "\x1b[{$code}m▄\x1b[0m"];
        }
        foreach ([40, 47, 100, 107] as $code) {
            yield "background {$code}" => ["\x1b[{$code}m", "\x1b[".($code - 10).'m▄'."\x1b[39m"];
        }
    }

    #[DataProvider('basicColours')]
    public function testEveryBasicColourIsRead(string $sgr, string $half): void
    {
        $this->assertSame(['0:0' => $half], self::spans([self::rule(1), $sgr.'x'], [new WidgetRect(1, 0, 1, 1)]));
    }

    public function testCodesBesideTheBasicColoursAreNoColour(): void
    {
        foreach ([29, 89, 98, 99, 108] as $code) { // 38 and 48 open an extended colour, read above
            $this->assertSame([], self::spans([self::rule(1), "\x1b[{$code};7;27mx"], [new WidgetRect(1, 0, 1, 1)]), "{$code}");
        }
    }

    /** A blank on nothing takes the half as a rule does — the padding over a view in a window — but no join. */
    public function testABlankOnNothingTakesTheHalfButNoJoin(): void
    {
        $this->assertSame(['0:2', '0:3', '0:4', '0:5'], \array_keys(self::spans(['        ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)])));
        $this->assertSame(['0:2', '0:3', '0:4', '0:5'], \array_keys(self::spans(['ab      ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)])), 'text before the blank ends nothing');
        $this->assertSame([], self::spans(['        ', '   │    '], [new WidgetRect(1, 0, 8, 1)]), 'a line meeting a blank joins nothing');
        $this->assertSame([], self::spans(["\x1b[7m \x1b[27m", "\x1b[48;5;31mx"], [new WidgetRect(1, 0, 1, 1)]), 'a reversed blank is shaded, not blank');
    }

    /** A half stays on the surface its row is on: inside a window, the window's own padding takes it. */
    public function testAViewInAWindowBleedsIntoTheWindowsPadding(): void
    {
        // The window's box, then a region for each pane it holds — the strip's own exactly where it stands.
        $window = [new DropdownRegion(top: 0, col: 0, width: 8, count: 2), new DropdownRegion(top: 1, col: 0, width: 8, count: 1)];

        $this->assertSame(['0:2', '0:3', '0:4', '0:5'], \array_keys(self::spans(['        ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], $window)));
        $this->assertSame([], self::spans(['        ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], [new DropdownRegion(top: 1, col: 0, width: 8, count: 2)]), 'but nothing outside it');
        $this->assertSame(
            [],
            self::spans(['        ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], [new DropdownRegion(top: 1, col: 0, width: 9, count: 1)]),
            'a surface over the strip that is not its own pane is another surface',
        );
        $this->assertSame(
            [],
            self::spans(['        ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], [new DropdownRegion(top: 1, col: 1, width: 8, count: 1)]),
            'even one its size, a column off',
        );
        $this->assertSame(
            ['0:4', '0:5'],
            \array_keys(self::spans(['        ', '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)], [...$window, new DropdownRegion(top: 0, col: 0, width: 4, count: 1)])),
            'nor a toast over its padding',
        );
    }

    /**
     * A zero-width marker between two colour codes hides neither: the editor's
     * APC cursor mark after the scrollbar's shade, then its reset, left the
     * first resting tab beside it reading as shaded, with a half over it.
     */
    public function testAMarkerBeforeTheResetDoesNotHideIt(): void
    {
        $rect = [new WidgetRect(1, 0, 1, 1)];
        $this->assertSame([], self::spans([self::rule(1), "\x1b[48;5;236m\x1b_pi:c;5\x07\x1b[49mx"], $rect), 'an APC ended by BEL');
        $this->assertSame([], self::spans([self::rule(1), "\x1b[48;5;236m\x1b]8;;f\x1b\\\x1b[49mx"], $rect), 'an OSC ended by ST');
        $this->assertSame([], self::spans([self::rule(1), "\x1b[48;5;236m\x1b[?25l\x1b[49mx"], $rect), 'a private CSI');
        $this->assertSame(['0:0' => "\x1b[38;5;31m▄\x1b[39m"], self::spans([self::rule(1), "\x1b[49m\x1b_pi:c;5\x07\x1b[48;5;31mx"], $rect), 'and a shade set after one is read');
    }

    /**
     * The pane divider beside a strip's shaded tab, by "row:col".
     *
     * @param list<string>         $lines
     * @param list<DropdownRegion> $floating
     *
     * @return array<string, string>
     */
    private static function openings(array $lines, WidgetRect $strip, array $floating = []): array
    {
        $spans = [];
        foreach (RuleHalves::openings($lines, $strip, $floating, static fn (int $row, int $col): int => $col, Styles::dark()->of(Role::Rule), Styles::dark()->of(Role::HeaderBar)) as $span) {
            self::assertSame(1, $span['width']);
            $spans["{$span['row']}:{$span['col']}"] = $span['segment'];
        }

        return $spans;
    }

    /** Beside a shaded first tab the divider opens: a blank of the tab's style, its joins the tab's halves. */
    public function testADividerBesideAShadedTabOpens(): void
    {
        $rule = Styles::dark()->of(Role::Rule);
        $lines = [$rule->apply('──┬─────'), 'ab'.$rule->apply('│')."\x1b[48;5;31mxyz  \x1b[49m", $rule->apply('──├─────')];

        $spans = self::openings($lines, new WidgetRect(1, 3, 5, 2));

        $this->assertSame(['1:2', '0:2', '2:2'], \array_keys($spans));
        $this->assertSame(' ', AnsiUtils::stripAnsiCodes($spans['1:2']), 'the divider\'s cell a blank');
        $this->assertStringEndsWith("\x1b[48;5;31m \x1b[0m", $spans['1:2'], 'in the tab\'s own style, set before the blank it shades');
        $this->assertSame("\x1b[38;5;31m▄\x1b[39m", $spans['0:2'], 'the join over it the tab\'s upper half');
        $this->assertSame("\x1b[38;5;31m▀\x1b[39m", $spans['2:2'], 'the one under it the lower');

        $this->assertSame([], self::openings([$rule->apply('──┬─────'), 'ab'.$rule->apply('│').'xyz  ', $rule->apply('──├─────')], new WidgetRect(1, 3, 5, 2)), 'beside a tab at rest it stays shut');
        $this->assertSame([], self::openings([$rule->apply('──┬─────'), 'ab|'."\x1b[48;5;31mxyz  \x1b[49m"], new WidgetRect(1, 3, 5, 1)), 'and beside anything but a divider there is nothing to open');
        $this->assertSame([], self::openings($lines, new WidgetRect(1, 3, 5, 2), [new DropdownRegion(top: 1, col: 2, width: 1, count: 1)]), 'nor under a floating surface');
        $this->assertSame(['1:2'], \array_keys(self::openings(['abc┬─────', $lines[1], 'ab│┤─────'], new WidgetRect(1, 3, 5, 1))), 'a join of another tint is no rule');
        $this->assertSame([], self::openings(['', ''], new WidgetRect(5, 3, 5, 1)), 'a strip off the frame');
        $this->assertSame([], self::openings(['abc'], new WidgetRect(0, 10, 3, 1)), 'or past the end of its row');
    }

    /** A gap between two shaded tabs is shaded too, but is no tab; the divider beside it takes both halves. */
    public function testBetweenTwoShadedTabsTheDividerTakesBothHalves(): void
    {
        $rule = Styles::dark()->of(Role::Rule);
        $lines = [
            $rule->apply('┬────'),
            $rule->apply('│')."\x1b[48;5;31mAAAA\x1b[49m",
            $rule->apply('│')."\x1b[38;5;31m\x1b[48;5;236m▀▀▀▀\x1b[0m",
            $rule->apply('│')."\x1b[48;5;236mBBBB\x1b[49m",
            $rule->apply('│').'    ',
        ];

        $spans = self::openings($lines, new WidgetRect(0, 1, 4, 5));

        $this->assertSame(['1:0', '3:0', '0:0', '2:0', '4:0'], \array_keys($spans), 'the two tabs\' rows open, not the gap\'s');
        $this->assertSame("\x1b[38;5;31;48;5;236m▀\x1b[0m", $spans['2:0'], 'the upper tab\'s colour on the lower\'s');
        $this->assertSame("\x1b[38;5;31m▄\x1b[39m", $spans['0:0']);
        $this->assertSame("\x1b[38;5;236m▀\x1b[39m", $spans['4:0']);

        $reversed = [$rule->apply('│')."\x1b[7mAAAA\x1b[27m", $rule->apply('│').'    ', $rule->apply('│')."\x1b[48;5;236mBBBB\x1b[49m"];
        $this->assertSame(
            "\x1b[38;5;236m▄\x1b[39m",
            self::openings($reversed, new WidgetRect(0, 1, 4, 3))['1:0'] ?? null,
            'a reversed tab above a coloured one: the coloured half is the one a cell can show',
        );
        $basic = [$rule->apply('│')."\x1b[44mAAAA\x1b[49m", $rule->apply('│').'    ', $rule->apply('│')."\x1b[41mBBBB\x1b[49m"];
        $this->assertSame("\x1b[34;41m▀\x1b[0m", self::openings($basic, new WidgetRect(0, 1, 4, 3))['1:0'] ?? null, 'basic colours turned into each other\'s background');
    }

    public function testADividerOnTheStripsOtherSideOpensToo(): void
    {
        $rule = Styles::dark()->of(Role::Rule);

        $spans = self::openings(["\x1b[48;5;31mxyz\x1b[49m".$rule->apply('│')], new WidgetRect(0, 0, 3, 1));

        $this->assertSame(['0:3'], \array_keys($spans));
        $this->assertSame(['0:1'], \array_keys(self::openings(["\x1b[48;5;31mx\x1b[49m".$rule->apply('│')], new WidgetRect(0, 0, 1, 1))), 'a strip one column wide, at the frame\'s first');
    }

    /** A plain rule over the divider — no join drawn there — takes the half as a join does. */
    public function testAPlainRuleOverTheDividerTakesTheHalf(): void
    {
        $rule = Styles::dark()->of(Role::Rule);

        $spans = self::openings([$rule->apply('──────'), $rule->apply('│')."\x1b[48;5;31mxyz\x1b[49m"], new WidgetRect(1, 1, 3, 1));

        $this->assertSame("\x1b[38;5;31m▄\x1b[39m", $spans['0:0'] ?? null);
    }

    /**
     * Where the edge row holds a bar's half, a shaded cell beside it takes the
     * other half on the bar's colour: under the menu bar `▄` in the cell's
     * shade on the bar's, over the status bar `▀`. A cell with no shade leaves
     * the bar's half alone, and the strip's own `│` ends at the bar.
     */
    public function testAShadedCellBesideABarsHalfSharesTheCell(): void
    {
        $bar = Styles::dark()->of(Role::HeaderBar);
        $lines = [RowBleed::under(8, $bar), '  '.self::SHADED.' │', RowBleed::over(8, $bar)];

        $spans = self::spans($lines, [new WidgetRect(1, 0, 8, 1)]);

        $upper = "\x1b[38;5;31;48;5;237m▄\x1b[0m";
        $lower = "\x1b[38;5;31;48;5;237m▀\x1b[0m";
        $this->assertSame(
            ['0:2' => $upper, '0:3' => $upper, '0:4' => $upper, '0:5' => $upper, '2:2' => $lower, '2:3' => $lower, '2:4' => $lower, '2:5' => $lower],
            $spans,
            'the four shaded cells, the bar behind them; neither the blanks nor the `│`',
        );
        $this->assertSame(
            [],
            self::spans([Styles::dark()->of(Role::TabAccent)->apply(\str_repeat('▀', times: 8)), '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)]),
            'a half in another colour is no bar\'s',
        );
        $this->assertSame([], self::spans(["\x1b[38;5;237;48;5;31m▀▀▀▀▀▀▀▀\x1b[0m", '  '.self::SHADED.'  '], [new WidgetRect(1, 0, 8, 1)]), 'nor one already on a shade');
    }

    /** The divider beside a shaded tab opens into the bar's half as it does into a rule. */
    public function testADividerOpensIntoABarsHalf(): void
    {
        $bar = Styles::dark()->of(Role::HeaderBar);
        $rule = Styles::dark()->of(Role::Rule);
        $lines = [RowBleed::under(8, $bar), 'ab'.$rule->apply('│')."\x1b[48;5;31mxyz  \x1b[49m", RowBleed::over(8, $bar)];

        $spans = self::openings($lines, new WidgetRect(1, 3, 5, 1));

        $this->assertSame("\x1b[38;5;31;48;5;237m▄\x1b[0m", $spans['0:2'], 'over it the tab\'s upper half on the bar');
        $this->assertSame("\x1b[38;5;31;48;5;237m▀\x1b[0m", $spans['2:2'], 'under it the lower');
    }
}
