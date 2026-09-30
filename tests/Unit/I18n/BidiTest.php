<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Tests\Support\Probe;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Glyphs;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The UAX#9-subset line reorderer: directional runs, neutral resolution, SGR
 * preservation, mirroring and the ltr/rtl base cases.
 */
final class BidiTest extends TestCase
{
    private const string SHALOM = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}"; // שלום
    private const string SHALOM_VISUAL = "\u{05DD}\u{05D5}\u{05DC}\u{05E9}"; // reversed

    private static function strip(string $s): string
    {
        return (string) \preg_replace('/\x1b\[[0-9;]*m/', replacement: '', subject: $s);
    }

    public function testEmptyLineIsReturnedUnchanged(): void
    {
        $this->assertSame('', Bidi::reorder('', BaseDirection::Rtl));
    }

    public function testAPureEscapeSequenceLineHasNothingToReorder(): void
    {
        $this->assertSame("\x1b[0m", Bidi::reorder("\x1b[0m", BaseDirection::Rtl));
    }

    public function testLtrBaseWithoutRtlContentIsReturnedUnchanged(): void
    {
        $this->assertSame('hello world', Bidi::reorder('hello world', BaseDirection::Ltr));
    }

    public function testLatinUnderRtlBaseStaysReadableButRightAnchored(): void
    {
        $this->assertSame('abc', self::strip(Bidi::reorder('abc', BaseDirection::Rtl)));
        $this->assertSame('   abc', self::strip(Bidi::reorder('abc   ', BaseDirection::Rtl)));
    }

    public function testHebrewUnderRtlBaseIsReversedToVisualOrder(): void
    {
        $this->assertSame(self::SHALOM_VISUAL, self::strip(Bidi::reorder(self::SHALOM, BaseDirection::Rtl)));
    }

    public function testMixedLineOrdersRunsForTheRtlBase(): void
    {
        $line = 'abc '.self::SHALOM.' def';
        $this->assertSame('def '.self::SHALOM_VISUAL.' abc', self::strip(Bidi::reorder($line, BaseDirection::Rtl)));
    }

    public function testHebrewRunReversesWhileLatinStaysUnderAnLtrBase(): void
    {
        $line = 'x '.self::SHALOM.' y';
        $this->assertSame('x '.self::SHALOM_VISUAL.' y', self::strip(Bidi::reorder($line, BaseDirection::Ltr)));
    }

    public function testDigitsReadLeftToRightUnderAnRtlBase(): void
    {
        $this->assertSame(self::SHALOM_VISUAL.' 12', self::strip(Bidi::reorder('12 '.self::SHALOM, BaseDirection::Rtl)));
    }

    /**
     * A separator between two numbers belongs to the number (UAX#9 W4).
     *
     * Without it the `:` resolves to the surrounding right-to-left direction,
     * splits the two digit runs and swaps them — so the status bar's caret
     * indicator read `5:12` while the caret sat at line 12, column 5. It is the
     * most-looked-at number in the editor and it was backwards in Hebrew.
     */
    public function testASeparatorBetweenTwoNumbersHoldsThemTogether(): void
    {
        $this->assertSame('12:5', self::strip(Bidi::reorder('12:5', BaseDirection::Rtl)), 'line:column');
        $this->assertSame('3.14', self::strip(Bidi::reorder('3.14', BaseDirection::Rtl)), 'a decimal');
        $this->assertSame('1,234', self::strip(Bidi::reorder('1,234', BaseDirection::Rtl)), 'a thousands group');
        $this->assertSame('2026-09-11', self::strip(Bidi::reorder('2026-09-11', BaseDirection::Rtl)), 'a date');
        $this->assertSame('1+2', self::strip(Bidi::reorder('1+2', BaseDirection::Rtl)), 'a European separator');
        $this->assertSame('1/2', self::strip(Bidi::reorder('1/2', BaseDirection::Rtl)), 'a ratio');
    }

    /**
     * A number attached to Latin text reads as Latin (UAX#9 W7).
     *
     * W7 searches back from each European number to the first strong type; an
     * L there makes the number L too. Without it a digit stays a number, and
     * numbers act as right-to-left for neutral resolution — so the separator
     * before it takes the paragraph direction and splits the token in half.
     *
     * Measured against the shipped Hebrew catalog: three of its 757 strings
     * contain a Latin token the mirror shattered, and all three are this shape.
     */
    public function testANumberAttachedToLatinTextStaysWithIt(): void
    {
        foreach (['UTF-8', 'autoload.psr-4', 'PHP 8.4', 'v1.2'] as $token) {
            $out = self::strip(Bidi::reorder(self::SHALOM.' '.$token, BaseDirection::Rtl));

            $this->assertStringContainsString($token, $out, $token.' is one token, not two');
        }
    }

    /**
     * And a path with a line number is one reference, not two.
     *
     * This was recorded as a limit that only a bidi isolate could lift — the
     * colon sitting between Latin and a number, taking the paragraph direction
     * by N1. That reading applied N1 without W7, which runs first: the line
     * number is preceded by Latin, so it IS Latin by the time N1 looks, and the
     * colon has L on both sides. The standard gives the intact answer.
     */
    public function testAPathWithALineNumberSurvives(): void
    {
        $this->assertSame(
            'src/Foo.php:42',
            self::strip(Bidi::reorder('src/Foo.php:42', BaseDirection::Rtl)),
            'a file reference is one token',
        );
    }

    /** A number with no Latin before it is still a number — the floor for W7. */
    public function testANumberAfterHebrewIsStillANumber(): void
    {
        // The status bar's caret indicator: nothing Latin precedes it, so W7
        // leaves it alone and W4 keeps its separator. Were W7 to fire here the
        // digits would read as Latin text and the row would lay out differently.
        $this->assertSame('12:5', self::strip(Bidi::reorder('12:5', BaseDirection::Rtl)));
        $this->assertStringContainsString('12:5', self::strip(Bidi::reorder(self::SHALOM.' 12:5', BaseDirection::Rtl)));
    }

    /**
     * A separator with a number on only ONE side is an ordinary neutral.
     *
     * The ends of the line are where the W4 walk can run off: a separator in
     * the first or last cell has no neighbour on one side, and reading past it
     * would both join the wrong thing and index outside the array. Every case
     * above puts the separator safely in the middle.
     */
    public function testASeparatorAtEitherEndDoesNotJoinAnything(): void
    {
        // Neutral, so it takes the base direction and moves to the far side.
        $this->assertSame('12:', self::strip(Bidi::reorder(':12', BaseDirection::Rtl)), 'nothing to its left');
        $this->assertSame(':12', self::strip(Bidi::reorder('12:', BaseDirection::Rtl)), 'nothing to its right');
    }

    /** The number survives with Hebrew around it, which is the real setting. */
    public function testANumberGroupHoldsTogetherInsideHebrew(): void
    {
        $line = self::SHALOM.' 12:5';

        $this->assertStringContainsString('12:5', self::strip(Bidi::reorder($line, BaseDirection::Rtl)));
    }

    /**
     * The floor: W4 joins a SINGLE separator, not a run of them.
     *
     * Without this, "merge any neutral flanked by digits" would read as the
     * same rule and pass every case above, while quietly swallowing the
     * neutrals UAX#9 leaves alone.
     */
    public function testOnlyASingleSeparatorJoinsTwoNumbers(): void
    {
        $this->assertSame('5::12', self::strip(Bidi::reorder('12::5', BaseDirection::Rtl)), 'two separators stay neutral');
    }

    public function testWideCharacterWidthIsPreserved(): void
    {
        $out = self::strip(Bidi::reorder("a\u{65E5}b", BaseDirection::Rtl)); // 日 is two columns
        $this->assertSame(4, \mb_strwidth($out, encoding: 'UTF-8'));
    }

    public function testSgrColourSpansTravelWithTheirText(): void
    {
        $out = Bidi::reorder("\x1b[31mred\x1b[0m", BaseDirection::Rtl);
        $this->assertSame('red', self::strip($out));
        $this->assertStringContainsString("\x1b[31m", $out, 'the colour is retained');
    }

    public function testStyleChangesAndAFinalResetAreEmitted(): void
    {
        // "A" is red, "B" is default (after reset); reordered under RTL base.
        $out = Bidi::reorder("\x1b[31mA\x1b[0mB", BaseDirection::Rtl);
        $this->assertSame('AB', self::strip($out));
        // A styled span still opens with red somewhere in the output.
        $this->assertStringContainsString("\x1b[31m", $out);

        // A line that never resets keeps its style active to the end.
        $trailing = Bidi::reorder("\x1b[1mAB", BaseDirection::Rtl);
        $this->assertStringEndsWith("\x1b[0m", $trailing);
    }

    public function testPairedPunctuationMirrorsInsideARightToLeftRun(): void
    {
        // A parenthesis adjacent to Hebrew resolves rtl and mirrors under L4.
        $out = self::strip(Bidi::reorder('('.self::SHALOM, BaseDirection::Rtl));
        $this->assertStringContainsString(')', $out, 'the opening paren mirrors to a closing one');
        $this->assertStringNotContainsString('(', $out);
    }

    /**
     * The glyphs that POINT mirror too — an arrow that keeps pointing right in
     * a right-to-left run points away from the thing it introduces.
     *
     * `‹` and `›` were already in the table; the arrows and triangles beside
     * them in the same chrome were not, so a tree's disclosure marker and a
     * rename's arrow kept their left-to-right sense in a mirrored pane.
     *
     * @param non-empty-string $glyph
     * @param non-empty-string $mirrored
     */
    #[DataProvider('directionalGlyphProvider')]
    public function testDirectionalGlyphsMirrorInsideARightToLeftRun(string $glyph, string $mirrored): void
    {
        $out = self::strip(Bidi::reorder($glyph.self::SHALOM, BaseDirection::Rtl));

        $this->assertStringContainsString($mirrored, $out, $glyph.' should mirror to '.$mirrored);
        $this->assertStringNotContainsString($glyph, $out);
    }

    /** @return iterable<string, array{string, string}> */
    public static function directionalGlyphProvider(): iterable
    {
        yield 'rename arrow' => ['←', '→'];
        yield 'nav arrow' => ['→', '←'];
        yield 'tree disclosure' => ['▸', '◂'];
        yield 'stopped line' => ['▶', '◀'];
        yield 'card accent' => ['▌', '▐'];
        yield 'vcs bar' => ['▎', '▕'];
        yield 'modal resize grab' => ['┛', '┗'];
    }

    /**
     * The floor: a glyph that points UP or DOWN must NOT mirror.
     *
     * Mirroring is horizontal. "Add every pointing glyph to the table" reads
     * like the same rule as the case above and would quietly turn the scroll
     * indicators upside down.
     */
    public function testVerticalGlyphsAreLeftAlone(): void
    {
        foreach (['▲', '▼', '▾'] as $glyph) {
            $out = self::strip(Bidi::reorder($glyph.self::SHALOM, BaseDirection::Rtl));
            $this->assertStringContainsString($glyph, $out, $glyph.' points up or down and has no mirror');
        }
    }

    public function testFrameCornersMirrorInsideARightToLeftRun(): void
    {
        // A corner adjacent to Hebrew resolves rtl and mirrors under L4, the
        // same way the parenthesis above does — a border row caught in an RTL
        // run keeps reading as a box instead of ending in two left corners.
        $out = self::strip(Bidi::reorder('╭'.self::SHALOM, BaseDirection::Rtl));
        $this->assertStringContainsString('╮', $out, 'the top-left corner mirrors to a top-right one');
        $this->assertStringNotContainsString('╭', $out);

        $bottom = self::strip(Bidi::reorder('╰'.self::SHALOM.'╯', BaseDirection::Rtl));
        $this->assertStringContainsString('╰', $bottom);
        $this->assertStringContainsString('╯', $bottom, 'both bottom corners survive as a mirrored pair');
    }

    public function testTeesAndSquareCornersMirrorInsideARightToLeftRun(): void
    {
        $tee = self::strip(Bidi::reorder('├'.self::SHALOM, BaseDirection::Rtl));
        $this->assertStringContainsString('┤', $tee, 'the left tee mirrors to a right tee');
        $this->assertStringNotContainsString('├', $tee);

        $square = self::strip(Bidi::reorder('┌'.self::SHALOM, BaseDirection::Rtl));
        $this->assertStringContainsString('┐', $square);
        $this->assertStringNotContainsString('┌', $square);

        $bottom = self::strip(Bidi::reorder('└'.self::SHALOM, BaseDirection::Rtl));
        $this->assertStringContainsString('┘', $bottom);
        $this->assertStringNotContainsString('└', $bottom);
    }

    /**
     * The text fields' focused (heavy) and danger (double) corners mirror too:
     * a field's border shape IS its state, and a focused box in the mirrored
     * git sidebar drew `┓━━┏` — two corners facing out — before these joined.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function fieldCorners(): iterable
    {
        yield 'heavy top-left' => ['┏', '┓'];
        yield 'heavy top-right' => ['┓', '┏'];
        yield 'double top-left' => ['╔', '╗'];
        yield 'double top-right' => ['╗', '╔'];
        yield 'double bottom-left' => ['╚', '╝'];
        yield 'double bottom-right' => ['╝', '╚'];
        yield 'heavy left tee' => ['┣', '┫'];
        yield 'heavy right tee' => ['┫', '┣'];
        yield 'tab lid top left' => ['┍', '┑'];
        yield 'tab lid top right' => ['┑', '┍'];
        yield 'tab lid bottom left' => ['┕', '┙'];
        yield 'tab lid bottom right' => ['┙', '┕'];
        yield 'focused tab foot up left' => ['┚', '┖'];
        yield 'focused tab foot up right' => ['┖', '┚'];
        yield 'focused tab foot down left' => ['┒', '┎'];
        yield 'focused tab foot down right' => ['┎', '┒'];
    }

    #[DataProvider('fieldCorners')]
    public function testFieldCornersMirrorInsideARightToLeftRun(string $corner, string $mirrored): void
    {
        $out = self::strip(Bidi::reorder($corner.self::SHALOM, BaseDirection::Rtl));

        $this->assertStringContainsString($mirrored, $out);
        $this->assertStringNotContainsString($corner, $out);
    }

    /** A whole focused and a whole danger box, Hebrew inside, still read as boxes once the row is reordered. */
    public function testABoxedFieldKeepsItsShapeUnderARightToLeftBase(): void
    {
        foreach ([FieldState::Focused, FieldState::Danger] as $state) {
            $field = new TextField();
            $field->setValue(self::SHALOM);
            $rows = FieldBox::render(new FieldSpec(self::SHALOM), $field, $state, CaretVisibility::Hidden, 16, Styles::dark());
            [$topLeft, $topRight, $bottomLeft, $bottomRight] = $state->glyphs();

            $top = \trim(self::strip(Bidi::reorder($rows[0], BaseDirection::Rtl)));
            $bottom = \trim(self::strip(Bidi::reorder($rows[2], BaseDirection::Rtl)));
            $this->assertStringStartsWith($topLeft, $top, $state->name.': the top row opens on a left corner');
            $this->assertStringEndsWith($topRight, $top, $state->name.': and closes on a right one');
            $this->assertStringStartsWith($bottomLeft, $bottom, $state->name);
            $this->assertStringEndsWith($bottomRight, $bottom, $state->name);
        }
    }

    public function testNeutralsBetweenMatchingRunsJoinThatSide(): void
    {
        // Two Latin words split by a space stay in reading order (space joins L).
        $this->assertSame('one two', self::strip(Bidi::reorder('one two', BaseDirection::Rtl)));
    }

    public function testACombiningGraphemeClusterIsCarriedThroughIntact(): void
    {
        // "e" + combining acute is one grapheme cluster (classified by its base
        // codepoint); it must stay a single intact cell through the reorder.
        $line = "e\u{0301}";
        $this->assertSame($line, self::strip(Bidi::reorder($line, BaseDirection::Rtl)));
    }

    public function testTheZeroWidthCursorMarkerSurvivesAndTracksTheCaret(): void
    {
        $marker = "\x1b_pi:c;5\x07";
        // Caret before "c" in Latin: the marker stays put (all-LTR run).
        $out = Bidi::reorder("ab{$marker}cd", BaseDirection::Rtl);
        $this->assertSame(1, \substr_count($out, $marker), 'the marker is not shattered');
        $this->assertStringContainsString("ab{$marker}cd", $out);

        // Caret inside Hebrew: the marker rides the reversed run to the matching gap.
        $he = self::SHALOM;
        $withCaret = \mb_substr($he, start: 0, length: 2, encoding: 'UTF-8').$marker.\mb_substr($he, start: 2, encoding: 'UTF-8');
        $reordered = Bidi::reorder($withCaret, BaseDirection::Rtl);
        $this->assertSame(1, \substr_count($reordered, $marker));
    }

    public function testVisualToLogicalColumnInvertsTheHebrewReversal(): void
    {
        // שלום reverses under rtl, so the leftmost screen column shows the last
        // logical character and vice versa.
        $this->assertSame(3, Bidi::visualToLogicalColumn(self::SHALOM, 0, BaseDirection::Rtl));
        $this->assertSame(2, Bidi::visualToLogicalColumn(self::SHALOM, 1, BaseDirection::Rtl));
        $this->assertSame(1, Bidi::visualToLogicalColumn(self::SHALOM, 2, BaseDirection::Rtl));
        $this->assertSame(0, Bidi::visualToLogicalColumn(self::SHALOM, 3, BaseDirection::Rtl));
    }

    public function testVisualToLogicalColumnIsIdentityForUnreorderedContent(): void
    {
        $this->assertSame(2, Bidi::visualToLogicalColumn('abc', 2, BaseDirection::Ltr)); // ltr, no rtl
        $this->assertSame(1, Bidi::visualToLogicalColumn('abc', 1, BaseDirection::Rtl)); // latin under rtl
    }

    public function testVisualToLogicalColumnHandlesEdges(): void
    {
        $this->assertSame(0, Bidi::visualToLogicalColumn(self::SHALOM, -3, BaseDirection::Rtl)); // negative clamps
        $this->assertSame(4, Bidi::visualToLogicalColumn(self::SHALOM, 99, BaseDirection::Rtl)); // past end → line end
        $this->assertSame(5, Bidi::visualToLogicalColumn('', 5, BaseDirection::Rtl)); // empty line: identity
        $this->assertSame(2, Bidi::visualToLogicalColumn("\x1b[0m", 2, BaseDirection::Rtl)); // pure SGR: identity
    }

    public function testVisualToLogicalColumnSkipsTheZeroWidthMarker(): void
    {
        $marker = "\x1b_pi:c;5\x07";
        // The marker occupies no column, so mapping still lands on the glyphs.
        $this->assertSame(1, Bidi::visualToLogicalColumn("a{$marker}bc", 1, BaseDirection::Rtl));
    }

    public function testVisualToLogicalColumnMapsWithinWideCharacters(): void
    {
        // 日 occupies screen columns 1–2; a click on its first cell maps to its
        // logical column (1), just past the leading "a".
        $this->assertSame(1, Bidi::visualToLogicalColumn("a\u{65E5}b", 1, BaseDirection::Rtl));
    }

    /**
     * No glyph the interface draws is strong LATIN text.
     *
     * `classify()` is an allow-list of neutral ranges with `return 'L'` at the
     * bottom, so every codepoint nobody thought about is declared Latin. A
     * marker classified `L` glues itself into the adjacent Latin run instead of
     * taking the direction of the text around it, and lands on the wrong side
     * of the name it belongs to: ` ▸ src/ ` came out `      /▸ src `.
     *
     * Derived from {@see Glyphs} rather than listed here, because a list of
     * glyphs is wrong the first time someone adds one and nothing says so. The
     * next arrow added to that class fails this the moment it appears.
     *
     * One exemption, principled: U+E0A0, the branch icon, is a Private Use
     * codepoint, which Unicode assigns to `L`; it always sits beside its Latin
     * branch name, so following the standard costs nothing here. (ASCII
     * letters were exempt too while `CHECKBOX_ON` was `[x]`; it is `[✓]` now
     * and no glyph spells a letter, so a new one that does fails here.)
     */
    public function testNoGlyphTheInterfaceDrawsIsStrongLatinText(): void
    {
        $offenders = [];
        foreach (new \ReflectionClass(Glyphs::class)->getConstants() as $name => $value) {
            $chars = \preg_split('//u', (string) $value, flags: \PREG_SPLIT_NO_EMPTY);
            foreach (false === $chars ? [] : $chars as $char) {
                $codepoint = \mb_ord($char, encoding: 'UTF-8');
                // U+E000-F8FF is Private Use, which Unicode assigns to L — the
                // branch icon there sits beside its Latin name.
                $exempt = $codepoint >= 0xE000 && $codepoint <= 0xF8FF;
                if ($exempt || 'L' !== Probe::invokeStatic(Bidi::class, 'classify', $char)) {
                    continue;
                }

                $offenders[] = $name.' ('.$char.')';
            }
        }

        $this->assertSame([], $offenders, 'these glyphs would be reordered as if they were Latin words');
    }

    /**
     * Every glyph in the mirror table can actually reach the mirror.
     *
     * L4 mirroring only applies to a cell that resolved to an ODD embedding
     * level, i.e. one inside a right-to-left run. A glyph classified as strong
     * Latin never gets there, so its entry in `MIRROR` is dead code that reads
     * like a working feature — and `«` and `»` were exactly that.
     *
     * Derived from the table itself, so an entry added without a matching
     * classification fails here rather than silently doing nothing.
     */
    public function testEveryMirroredGlyphCanReachTheMirror(): void
    {
        $unreachable = [];
        foreach (\array_keys(Probe::getStatic(Bidi::class, 'MIRROR')) as $glyph) {
            if ('L' !== Probe::invokeStatic(Bidi::class, 'classify', (string) $glyph)) {
                continue;
            }

            $unreachable[] = (string) $glyph;
        }

        $this->assertSame([], $unreachable, 'these have a mirror entry they can never reach');
    }

    /**
     * The directional marks are STRONG, which is the whole reason they exist.
     *
     * U+200E and U+200F carry no glyph and no width; their only job is to give
     * the neutral text beside them a direction. Classified as neutral they do
     * precisely nothing — and the Hebrew catalog already ships nine strings
     * using them in the belief that they work.
     */
    public function testTheDirectionalMarksAreStrong(): void
    {
        $this->assertSame('L', Probe::invokeStatic(Bidi::class, 'classify', "\u{200E}"), 'left-to-right mark');
        $this->assertSame('R', Probe::invokeStatic(Bidi::class, 'classify', "\u{200F}"), 'right-to-left mark');
        // The arabic letter mark needs no branch of its own — the Arabic block
        // already classifies it R. It is asserted here so that staying true is
        // deliberate, and spent below because it is zero-width all the same.
        $this->assertSame('R', Probe::invokeStatic(Bidi::class, 'classify', "\u{061C}"), 'arabic letter mark');
    }

    /**
     * A mark binds the run beside it instead of letting it shatter.
     *
     * This is the settings form's Choice stepper: a Hebrew label, then a Latin
     * value wrapped in `<` and `>`. Without a mark the opening `<` sits between
     * strong R and strong L, takes the paragraph direction, and leaves for the
     * far side of the group — mirroring on the way, so the row shows two `>`
     * and the value is no longer bracketed.
     */
    public function testAMarkBindsALatinGroupThatWouldOtherwiseShatter(): void
    {
        $shattered = self::strip(Bidi::reorder(self::SHALOM.'  < dark > · light', BaseDirection::Rtl));
        $this->assertStringNotContainsString('< dark >', $shattered, 'the defect this mark exists to fix');

        $bound = self::strip(Bidi::reorder(self::SHALOM.'  '."\u{200E}".'< dark > · light', BaseDirection::Rtl));

        $this->assertStringContainsString('< dark > · light', $bound, 'the value keeps its brackets and its order');
    }

    /**
     * A mark costs a column going in and gives it back coming out.
     *
     * It measures no column and the terminal draws none, but it has already
     * steered Porta's reorder; a terminal running UAX#9 would steer with it
     * again. So it is dropped during ordering, and the row keeps its width.
     */
    public function testAMarkIsDroppedAndTheRowKeepsItsWidth(): void
    {
        $line = self::SHALOM."\u{200E}".'abc';

        $out = Bidi::reorder($line, BaseDirection::Rtl);

        $this->assertStringNotContainsString("\u{200E}", $out, 'the mark is spent, not shipped to the terminal');
        $this->assertStringNotContainsString('  ', AnsiUtils::stripAnsiCodes($out), 'and leaves no space behind');
        $this->assertSame(AnsiUtils::visibleWidth($line), AnsiUtils::visibleWidth($out), 'and the row is exactly as wide as before');
    }

    /**
     * N0: a Hebrew label ending in a Latin parenthetical keeps both brackets
     * round the Latin. Each half used to resolve alone, and the row read
     * `LSP))` — the settings form's "format on save (LSP)", on screen.
     */
    public function testABracketPairHoldingLatinInHebrewTakesTheParagraphSide(): void
    {
        $out = self::strip(Bidi::reorder(self::SHALOM.' (LSP)', BaseDirection::Rtl));

        $this->assertSame('(LSP) '.self::SHALOM_VISUAL, $out);
    }

    /** A Latin label ending in a parenthetical stays one left-to-right run, brackets and all. */
    public function testABracketPairAfterLatinTakesTheLatinSide(): void
    {
        $out = self::strip(Bidi::reorder('demo (project)', BaseDirection::Rtl));

        $this->assertSame('demo (project)', $out);
    }

    /** Under a left-to-right base, Hebrew in brackets after Hebrew is one right-to-left run with them. */
    public function testABracketPairHoldingHebrewAfterHebrewMirrorsWithIt(): void
    {
        $out = self::strip(Bidi::reorder(self::SHALOM.' ('.self::SHALOM.')', BaseDirection::Ltr));

        $this->assertSame('('.self::SHALOM_VISUAL.') '.self::SHALOM_VISUAL, $out);
    }

    /** A pair holding nothing strong is left to the neutral rules. */
    public function testAnEmptyBracketPairStaysNeutral(): void
    {
        $out = self::strip(Bidi::reorder(self::SHALOM.' ()', BaseDirection::Rtl));

        // Both halves resolve with the Hebrew and mirror, as they did before N0.
        $this->assertSame('() '.self::SHALOM_VISUAL, $out);
    }

    /**
     * BD16: a closing bracket pairs with the nearest open one of its kind and
     * closes everything opened after it; one with no partner pairs nothing.
     * Here `]` closes `[` and takes the stray `(` with it, so `)` is alone:
     * the pair holds Latin after Hebrew and takes the paragraph side, and the
     * lone `)` resolves by N1 like any neutral, mirroring at the far end.
     */
    public function testACrossedBracketPairsByTheRulesStack(): void
    {
        $out = self::strip(Bidi::reorder(self::SHALOM.' [a (b] c)', BaseDirection::Rtl));

        $this->assertSame('(c [a (b] '.self::SHALOM_VISUAL, $out);
    }

    /**
     * The strong text right against the opening bracket is what N0 looks back
     * to, even at the very start of the line: `a(x)` stays whole.
     */
    public function testABracketPairLooksBackToTheCharacterBesideIt(): void
    {
        $this->assertSame('a(x)', self::strip(Bidi::reorder('a(x)', BaseDirection::Rtl)));
    }

    /** Any strong text of the paragraph direction inside the pair wins it, not only the first. */
    public function testABracketPairHoldingBothDirectionsTakesTheParagraphs(): void
    {
        $out = self::strip(Bidi::reorder('demo (x '.self::SHALOM.')', BaseDirection::Rtl));

        $this->assertSame('('.self::SHALOM_VISUAL.' x) demo', $out);
    }

    /** A pair with nothing strong in it is skipped, and the pairs after it still resolve. */
    public function testAnEmptyPairDoesNotStopThePairsAfterIt(): void
    {
        $this->assertSame('a(x) ()', self::strip(Bidi::reorder('() a(x)', BaseDirection::Rtl)));
    }

    /**
     * BD16 as a table: nested pairs come back in the order they OPEN, a
     * closing bracket pairs once, with the nearest opener of its kind, and a
     * crossing closer drops the openers above its partner.
     *
     * @param list<string>          $glyphs
     * @param list<array{int, int}> $pairs
     */
    #[DataProvider('bracketPairProvider')]
    public function testBracketsPairByTheRulesStack(array $glyphs, array $pairs): void
    {
        $this->assertSame($pairs, Probe::invokeStatic(Bidi::class, 'bracketPairs', $glyphs));
    }

    /** @return iterable<string, array{list<string>, list<array{int, int}>}> */
    public static function bracketPairProvider(): iterable
    {
        yield 'nested' => [['(', '[', 'a', ']', ')'], [[0, 4], [1, 3]]];
        yield 'an unclosed opener under a pair' => [['(', '(', 'a', ')'], [[1, 3]]];
        yield 'crossed' => [['[', 'a', '(', 'b', ']', 'c', ')'], [[0, 4]]];
        yield 'a lone closer' => [[')', 'a', '(', ')'], [[2, 3]]];
    }

    /**
     * W5: a terminator touching a number is part of it, on either side and in
     * a run. `מוריד %42` was symfony-lsp's download progress. Every expected
     * row here is fribidi's output for the same input.
     */
    #[DataProvider('numberTerminatorProvider')]
    public function testATerminatorStaysWithItsNumber(string $logical, string $visual): void
    {
        $this->assertSame($visual, self::strip(Bidi::reorder($logical, BaseDirection::Rtl)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function numberTerminatorProvider(): iterable
    {
        yield 'after' => [self::SHALOM.' 42%', '42% '.self::SHALOM_VISUAL];
        yield 'before' => [self::SHALOM.' $5', '$5 '.self::SHALOM_VISUAL];
        yield 'a run of them' => [self::SHALOM.' 5%%', '5%% '.self::SHALOM_VISUAL];
        yield 'degree and plus-minus' => [self::SHALOM." 20\u{B0} \u{B1}3", "\u{B1}3 20\u{B0} ".self::SHALOM_VISUAL];
        yield 'first on the line' => ['%5 '.self::SHALOM, self::SHALOM_VISUAL.' %5'];
        // Between a number and Hebrew, a plain neutral would take the Hebrew's
        // side and cross the number; each terminator, on each side, must not.
        foreach (['#', '$', '%', "\u{B0}", "\u{B1}", "\u{2030}"] as $terminator) {
            yield $terminator.' after' => [self::SHALOM.' 5'.$terminator.' '.self::SHALOM, self::SHALOM_VISUAL.' 5'.$terminator.' '.self::SHALOM_VISUAL];
            yield $terminator.' before' => [self::SHALOM.' '.$terminator.'5 '.self::SHALOM, self::SHALOM_VISUAL.' '.$terminator.'5 '.self::SHALOM_VISUAL];
        }
        // W6: nothing to touch, so an ordinary neutral.
        yield 'alone' => [self::SHALOM.' %', '% '.self::SHALOM_VISUAL];
        yield 'a space away' => [self::SHALOM.' % 5', '5 % '.self::SHALOM_VISUAL];
        yield 'between latin and hebrew' => ['abc % '.self::SHALOM, self::SHALOM_VISUAL.' % abc'];
        // Neutral, not numeric and not Latin: between two Latin words it joins
        // their run, and before Latin after Hebrew it takes the Hebrew's side.
        yield 'between latin words' => [self::SHALOM.' abc % def', 'abc % def '.self::SHALOM_VISUAL];
        yield 'plus-minus before latin' => [self::SHALOM." \u{B1} abc", "abc \u{B1} ".self::SHALOM_VISUAL];
    }

    /** Every zero-width mark is spent, not only the one the settings form uses. */
    public function testEveryDirectionalMarkIsSpent(): void
    {
        foreach (["\u{200E}", "\u{200F}", "\u{061C}"] as $mark) {
            $out = Bidi::reorder(self::SHALOM.$mark.'abc', BaseDirection::Rtl);

            $this->assertStringNotContainsString($mark, $out, 'a mark reaching the terminal costs the row a column');
        }
    }

    /**
     * The neutral ranges begin and end exactly where they say.
     *
     * The glyph sweep only sees codepoints the interface actually draws, so a
     * range whose edge no glyph happens to occupy can be off by one and nothing
     * notices — and the ranges added for arrows, shapes and symbols are mostly
     * edge-free in that sense.
     *
     * @param int<0, 1114111> $codepoint
     */
    #[DataProvider('neutralRangeEdgeProvider')]
    public function testTheNeutralRangesEndWhereTheySay(int $codepoint, bool $neutral): void
    {
        $class = Probe::invokeStatic(Bidi::class, 'classify', \mb_chr($codepoint, encoding: 'UTF-8'));

        $this->assertSame($neutral, 'N' === $class, \sprintf('U+%04X', $codepoint));
    }

    /** @return iterable<string, array{int, bool}> codepoint => is it neutral */
    public static function neutralRangeEdgeProvider(): iterable
    {
        yield 'before arrows (0x218F)' => [0x218F, false];
        yield 'arrows start (0x2190)' => [0x2190, true];
        yield 'arrows end (0x21FF)' => [0x21FF, true];
        // The arrows run straight on into the mathematical operators, then
        // misc technical: 0x2190-0x23FF is one neutral span, and the edges
        // that matter are its two ends.
        yield 'operators start (0x2200)' => [0x2200, true];
        yield 'operators end (0x22FF)' => [0x22FF, true];
        yield 'misc technical start (0x2300)' => [0x2300, true];
        yield 'misc technical end (0x23FF)' => [0x23FF, true];
        yield 'after misc technical (0x2400)' => [0x2400, false];
        // Box drawing, blocks, shapes and symbols run together from 0x2500 to
        // 0x27BF, so only the outer edges move the answer — but each internal
        // end is still its own bound and can slip on its own.
        yield 'before box drawing (0x24FF)' => [0x24FF, false];
        yield 'box drawing start (0x2500)' => [0x2500, true];
        yield 'box drawing end (0x257F)' => [0x257F, true];
        yield 'block elements end (0x259F)' => [0x259F, true];
        yield 'geometric shapes end (0x25FF)' => [0x25FF, true];
        yield 'dingbats end (0x27BF)' => [0x27BF, true];
        yield 'after dingbats (0x27C0)' => [0x27C0, false];
    }

    /**
     * classify()'s codepoint boundaries, probed indirectly: a Hebrew prefix
     * plus the probe char plus a trailing 'x', under an RTL base. A
     * strong-or-neutral char (R or N) ends up grouped with the Hebrew side
     * ("x CHAR שלום"-shaped); a plain L char stays put ahead of it
     * ("CHAR x שלום"-shaped) — reordering only fully cancels out for a
     * uniformly-L line, so this construction reliably tells the two apart.
     */
    private const array MIRROR_PAIRS = ['[' => ']', '{' => '}'];

    private static function classifiesAsNonL(string $char): bool
    {
        $plain = self::strip(Bidi::reorder("\u{05E9}{$char}x", BaseDirection::Rtl));
        // A neutral char resolving 'R' also mirrors under L4 (self::MIRROR_PAIRS
        // covers the two boundary chars this provider probes that are mirror
        // pairs), so look for whichever form actually landed in the output.
        $needle = self::MIRROR_PAIRS[$char] ?? $char;
        $charPos = \mb_strpos($plain, needle: $needle, encoding: 'UTF-8');
        $xPos = \mb_strpos($plain, needle: 'x', encoding: 'UTF-8');
        \assert(false !== $charPos && false !== $xPos, description: 'both the probe char and the x marker must appear in the output');

        return $charPos > $xPos;
    }

    /** @return iterable<string, array{int, bool}> codepoint => expected classifiesAsNonL() */
    public static function classifyBoundaryProvider(): iterable
    {
        yield 'R1 start-1 (0x058F)' => [0x058F, false];
        yield 'R1 start (0x0590)' => [0x0590, true];
        yield 'R1/R2 boundary (0x05FF)' => [0x05FF, true];
        // 0x0600 itself is skipped: it's a Unicode "prepended concatenation
        // mark" (ARABIC NUMBER SIGN) that this probe's own 3-cell
        // construction can't isolate cleanly. R2's coverage comes from its
        // own end boundary below plus R1's end just above.
        yield 'R2/R3 boundary end (0x06FF)' => [0x06FF, true];
        yield 'R3 start (0x0700)' => [0x0700, true];
        yield 'R3 end (0x08FF)' => [0x08FF, true];
        yield 'R3 end+1 (0x0980)' => [0x0980, false];
        yield 'R4 start-1 (0xFB1C)' => [0xFB1C, false];
        yield 'R4 start (0xFB1D)' => [0xFB1D, true];
        yield 'R4 end (0xFDFF)' => [0xFDFF, true];
        yield 'R4 end+1 (0xFE10)' => [0xFE10, false];
        yield 'R5 start-1 (0xFE6F)' => [0xFE6F, false];
        yield 'R5 start (0xFE70)' => [0xFE70, true];
        yield 'R5 end (0xFEFF)' => [0xFEFF, true];
        yield 'R5 end+1 (0xFF10)' => [0xFF10, false];
        yield 'space exact (0x20)' => [0x20, true];
        yield 'nbsp exact (0xA0)' => [0xA0, true];
        yield 'N1 start ! (0x21)' => [0x21, true];
        yield 'N1 end / (0x2F)' => [0x2F, true];
        yield 'N2 start : (0x3A)' => [0x3A, true];
        yield 'N2 end @ (0x40)' => [0x40, true];
        yield 'N3 start [ (0x5B)' => [0x5B, true];
        yield 'N3 end ` (0x60)' => [0x60, true];
        yield 'N4 start { (0x7B)' => [0x7B, true];
        yield 'N4 end ~ (0x7E)' => [0x7E, true];
        yield 'N5 start (0x2000)' => [0x2000, true];
        yield 'N5 end (0x206F)' => [0x206F, true];
        yield 'N6 start (0x2500)' => [0x2500, true];
        yield 'N6 end (0x257F)' => [0x257F, true];
    }

    #[DataProvider('classifyBoundaryProvider')]
    public function testClassifyBoundary(int $codepoint, bool $expectedNonL): void
    {
        $char = \mb_chr($codepoint, encoding: 'UTF-8');
        \assert(false !== $char, description: 'the data provider only feeds valid codepoints');
        $this->assertSame($expectedNonL, self::classifiesAsNonL($char), \sprintf('codepoint 0x%04X', $codepoint));
    }

    /**
     * 0x0600 (R2's start) and 0x08FF (R3's end) are themselves Unicode
     * combining/format marks, so the 3-cell indirect probe above can't
     * isolate them (they attach to whatever base character precedes them
     * instead of standing as their own cell). classify() is reflected
     * directly here instead — the one pair this file needs it for.
     */
    public function testClassifyBoundaryForCombiningMarkCodepoints(): void
    {
        $classify = new \ReflectionMethod(Bidi::class, 'classify');
        $this->assertSame('R', $classify->invoke(null, \mb_chr(0x0600, encoding: 'UTF-8')), 'R2 start');
        $this->assertSame('R', $classify->invoke(null, \mb_chr(0x08FF, encoding: 'UTF-8')), 'R3 end');
    }

    /**
     * The EN (digit) boundary can't use the R/N probe above — a digit embeds
     * at the SAME level as plain text, so misclassifying it as L doesn't
     * change ITS OWN grouping. But EN is treated as 'R' when resolving an
     * ADJACENT neutral run: Hebrew + digit + space + 'x' pulls the space to
     * the Hebrew side only if the digit is really EN.
     */
    private static function digitPullsTheFollowingSpaceRtlward(string $char): bool
    {
        return 'x '.$char."\u{05E9}" === self::strip(Bidi::reorder("\u{05E9}{$char} x", BaseDirection::Rtl));
    }

    public function testEnBoundaryDigitsPullAnAdjacentSpaceToTheRtlSide(): void
    {
        $this->assertTrue(self::digitPullsTheFollowingSpaceRtlward('0'), '0x30, the EN range start');
        $this->assertTrue(self::digitPullsTheFollowingSpaceRtlward('9'), '0x39, the EN range end');
    }

    public function testALetterDoesNotPullAnAdjacentSpaceToTheRtlSide(): void
    {
        $this->assertFalse(self::digitPullsTheFollowingSpaceRtlward('q'), 'a plain L char leaves the space on its own side');
    }

    public function testConsecutiveStyleChangesBothAccumulateIntoTheActiveState(): void
    {
        // Two SGR codes in a row (no reset between) must both be part of the
        // active style carried forward, not just the last one seen.
        $out = Bidi::reorder("\x1b[31m\x1b[1mAB", BaseDirection::Rtl);
        $this->assertStringContainsString("\x1b[31m", $out);
        $this->assertStringContainsString("\x1b[1m", $out);
        $this->assertSame("\x1b[0m\x1b[31m\x1b[1mAB\x1b[0m", $out);
    }

    public function testLtrBaseWithSgrAndNoRtlContentIsReturnedByteIdentical(): void
    {
        // The LTR/no-RTL shortcut must return the ORIGINAL bytes verbatim —
        // routing SGR-styled content through the full reorder pipeline
        // anyway would re-derive a leading reset that was never there.
        $line = "\x1b[31mred\x1b[0m";
        $this->assertSame($line, Bidi::reorder($line, BaseDirection::Ltr));
    }

    public function testVisualToLogicalColumnSkipsAnSgrSequenceNotJustTheCursorMarker(): void
    {
        // An SGR code must never be counted as a column-occupying glyph —
        // exercised with real Hebrew content so a broken skip shifts the
        // mapped column, not just the raw token count.
        $line = "\x1b[31m\u{05E9}\u{05DC}"; // styled, 2 Hebrew chars, reversed under RTL
        $this->assertSame(1, Bidi::visualToLogicalColumn($line, 0, BaseDirection::Rtl));
        $this->assertSame(0, Bidi::visualToLogicalColumn($line, 1, BaseDirection::Rtl));
    }

    public function testVisualToLogicalColumnMarkerWidthBoundaryIsExactlyZero(): void
    {
        // The cursor marker's width must be exactly 0, not merely "not
        // positive" or "not exactly 1" — pinned via a marker sandwiched
        // between two Hebrew chars, where an off-by-one on its own width
        // shifts which side of the marker a given visual column lands on.
        $marker = "\x1b_pi:c;5\x07";
        $line = "\u{05E9}{$marker}\u{05DC}";
        $this->assertSame(1, Bidi::visualToLogicalColumn($line, 0, BaseDirection::Rtl));
        $this->assertSame(0, Bidi::visualToLogicalColumn($line, 1, BaseDirection::Rtl));
    }

    public function testClassifyTreatsTheCursorMarkerAsNeutralNotLtr(): void
    {
        // Misclassifying the \x1b-prefixed marker as 'L' (instead of 'N')
        // would keep it on the LTR side of a following 'x' instead of
        // letting it join the Hebrew run it's adjacent to.
        $marker = "\x1b_pi:c;5\x07";
        $out = self::strip(Bidi::reorder("\u{05E9}\u{05DC}{$marker}x", BaseDirection::Rtl));
        $this->assertSame("x{$marker}\u{05DC}\u{05E9}", $out);
    }

    public function testABaseCodepointFromAMultiCharGraphemeClusterIsTheFirstOne(): void
    {
        // classify() reads the grapheme's BASE codepoint — for a combining
        // sequence that must be the leading character (Hebrew aleph), not
        // the trailing combining mark, or the cluster would misclassify as
        // neutral/L and land on the wrong side of the reorder.
        $cluster = "\u{05D0}\u{0301}"; // aleph + combining acute, one grapheme
        $this->assertSame('b'.$cluster, self::strip(Bidi::reorder($cluster.'b', BaseDirection::Rtl)));
    }

    public function testStyleChangeMidRunEmitsResetBeforeTheNewCode(): void
    {
        // Each style transition re-opens with an explicit RESET before the
        // new SGR code, so a reordered run never inherits a stale style from
        // whatever preceded it in visual order.
        $out = Bidi::reorder("\x1b[31mA\x1b[0mB", BaseDirection::Rtl);
        $this->assertSame("\x1b[0m\x1b[31mA\x1b[0mB", $out);
    }
}
