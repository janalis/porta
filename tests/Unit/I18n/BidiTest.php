<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Tests\Support\Probe;
use Porta\Ui\Glyphs;

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
        return (string) preg_replace('/\x1b\[[0-9;]*m/', replacement: '', subject: $s);
    }

    public function testEmptyLineIsReturnedUnchanged(): void
    {
        self::assertSame('', Bidi::reorder('', BaseDirection::Rtl));
    }

    public function testAPureEscapeSequenceLineHasNothingToReorder(): void
    {
        self::assertSame("\x1b[0m", Bidi::reorder("\x1b[0m", BaseDirection::Rtl));
    }

    public function testLtrBaseWithoutRtlContentIsReturnedUnchanged(): void
    {
        self::assertSame('hello world', Bidi::reorder('hello world', BaseDirection::Ltr));
    }

    public function testLatinUnderRtlBaseStaysReadableButRightAnchored(): void
    {
        self::assertSame('abc', self::strip(Bidi::reorder('abc', BaseDirection::Rtl)));
        self::assertSame('   abc', self::strip(Bidi::reorder('abc   ', BaseDirection::Rtl)));
    }

    public function testHebrewUnderRtlBaseIsReversedToVisualOrder(): void
    {
        self::assertSame(self::SHALOM_VISUAL, self::strip(Bidi::reorder(self::SHALOM, BaseDirection::Rtl)));
    }

    public function testMixedLineOrdersRunsForTheRtlBase(): void
    {
        $line = 'abc '.self::SHALOM.' def';
        self::assertSame('def '.self::SHALOM_VISUAL.' abc', self::strip(Bidi::reorder($line, BaseDirection::Rtl)));
    }

    public function testHebrewRunReversesWhileLatinStaysUnderAnLtrBase(): void
    {
        $line = 'x '.self::SHALOM.' y';
        self::assertSame('x '.self::SHALOM_VISUAL.' y', self::strip(Bidi::reorder($line, BaseDirection::Ltr)));
    }

    public function testDigitsReadLeftToRightUnderAnRtlBase(): void
    {
        self::assertSame(self::SHALOM_VISUAL.' 12', self::strip(Bidi::reorder('12 '.self::SHALOM, BaseDirection::Rtl)));
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
        self::assertSame('12:5', self::strip(Bidi::reorder('12:5', BaseDirection::Rtl)), 'line:column');
        self::assertSame('3.14', self::strip(Bidi::reorder('3.14', BaseDirection::Rtl)), 'a decimal');
        self::assertSame('1,234', self::strip(Bidi::reorder('1,234', BaseDirection::Rtl)), 'a thousands group');
        self::assertSame('2026-09-11', self::strip(Bidi::reorder('2026-09-11', BaseDirection::Rtl)), 'a date');
    }

    /** The number survives with Hebrew around it, which is the real setting. */
    public function testANumberGroupHoldsTogetherInsideHebrew(): void
    {
        $line = self::SHALOM.' 12:5';

        self::assertStringContainsString('12:5', self::strip(Bidi::reorder($line, BaseDirection::Rtl)));
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
        self::assertSame('5::12', self::strip(Bidi::reorder('12::5', BaseDirection::Rtl)), 'two separators stay neutral');
    }

    public function testWideCharacterWidthIsPreserved(): void
    {
        $out = self::strip(Bidi::reorder("a\u{65E5}b", BaseDirection::Rtl)); // 日 is two columns
        self::assertSame(4, mb_strwidth($out, encoding: 'UTF-8'));
    }

    public function testSgrColourSpansTravelWithTheirText(): void
    {
        $out = Bidi::reorder("\x1b[31mred\x1b[0m", BaseDirection::Rtl);
        self::assertSame('red', self::strip($out));
        self::assertStringContainsString("\x1b[31m", $out, 'the colour is retained');
    }

    public function testStyleChangesAndAFinalResetAreEmitted(): void
    {
        // "A" is red, "B" is default (after reset); reordered under RTL base.
        $out = Bidi::reorder("\x1b[31mA\x1b[0mB", BaseDirection::Rtl);
        self::assertSame('AB', self::strip($out));
        // A styled span still opens with red somewhere in the output.
        self::assertStringContainsString("\x1b[31m", $out);

        // A line that never resets keeps its style active to the end.
        $trailing = Bidi::reorder("\x1b[1mAB", BaseDirection::Rtl);
        self::assertStringEndsWith("\x1b[0m", $trailing);
    }

    public function testPairedPunctuationMirrorsInsideARightToLeftRun(): void
    {
        // A parenthesis adjacent to Hebrew resolves rtl and mirrors under L4.
        $out = self::strip(Bidi::reorder('('.self::SHALOM, BaseDirection::Rtl));
        self::assertStringContainsString(')', $out, 'the opening paren mirrors to a closing one');
        self::assertStringNotContainsString('(', $out);
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
    #[\PHPUnit\Framework\Attributes\DataProvider('directionalGlyphProvider')]
    public function testDirectionalGlyphsMirrorInsideARightToLeftRun(string $glyph, string $mirrored): void
    {
        $out = self::strip(Bidi::reorder($glyph.self::SHALOM, BaseDirection::Rtl));

        self::assertStringContainsString($mirrored, $out, $glyph.' should mirror to '.$mirrored);
        self::assertStringNotContainsString($glyph, $out);
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
            self::assertStringContainsString($glyph, $out, $glyph.' points up or down and has no mirror');
        }
    }

    public function testFrameCornersMirrorInsideARightToLeftRun(): void
    {
        // A corner adjacent to Hebrew resolves rtl and mirrors under L4, the
        // same way the parenthesis above does — a border row caught in an RTL
        // run keeps reading as a box instead of ending in two left corners.
        $out = self::strip(Bidi::reorder('╭'.self::SHALOM, BaseDirection::Rtl));
        self::assertStringContainsString('╮', $out, 'the top-left corner mirrors to a top-right one');
        self::assertStringNotContainsString('╭', $out);

        $bottom = self::strip(Bidi::reorder('╰'.self::SHALOM.'╯', BaseDirection::Rtl));
        self::assertStringContainsString('╰', $bottom);
        self::assertStringContainsString('╯', $bottom, 'both bottom corners survive as a mirrored pair');
    }

    public function testTeesAndSquareCornersMirrorInsideARightToLeftRun(): void
    {
        $tee = self::strip(Bidi::reorder('├'.self::SHALOM, BaseDirection::Rtl));
        self::assertStringContainsString('┤', $tee, 'the left tee mirrors to a right tee');
        self::assertStringNotContainsString('├', $tee);

        $square = self::strip(Bidi::reorder('┌'.self::SHALOM, BaseDirection::Rtl));
        self::assertStringContainsString('┐', $square);
        self::assertStringNotContainsString('┌', $square);

        $bottom = self::strip(Bidi::reorder('└'.self::SHALOM, BaseDirection::Rtl));
        self::assertStringContainsString('┘', $bottom);
        self::assertStringNotContainsString('└', $bottom);
    }

    public function testNeutralsBetweenMatchingRunsJoinThatSide(): void
    {
        // Two Latin words split by a space stay in reading order (space joins L).
        self::assertSame('one two', self::strip(Bidi::reorder('one two', BaseDirection::Rtl)));
    }

    public function testACombiningGraphemeClusterIsCarriedThroughIntact(): void
    {
        // "e" + combining acute is one grapheme cluster (classified by its base
        // codepoint); it must stay a single intact cell through the reorder.
        $line = "e\u{0301}";
        self::assertSame($line, self::strip(Bidi::reorder($line, BaseDirection::Rtl)));
    }

    public function testTheZeroWidthCursorMarkerSurvivesAndTracksTheCaret(): void
    {
        $marker = "\x1b_pi:c;5\x07";
        // Caret before "c" in Latin: the marker stays put (all-LTR run).
        $out = Bidi::reorder("ab{$marker}cd", BaseDirection::Rtl);
        self::assertSame(1, substr_count($out, $marker), 'the marker is not shattered');
        self::assertStringContainsString("ab{$marker}cd", $out);

        // Caret inside Hebrew: the marker rides the reversed run to the matching gap.
        $he = self::SHALOM;
        $withCaret = mb_substr($he, start: 0, length: 2, encoding: 'UTF-8').$marker.mb_substr($he, start: 2, length: null, encoding: 'UTF-8');
        $reordered = Bidi::reorder($withCaret, BaseDirection::Rtl);
        self::assertSame(1, substr_count($reordered, $marker));
    }

    public function testVisualToLogicalColumnInvertsTheHebrewReversal(): void
    {
        // שלום reverses under rtl, so the leftmost screen column shows the last
        // logical character and vice versa.
        self::assertSame(3, Bidi::visualToLogicalColumn(self::SHALOM, 0, BaseDirection::Rtl));
        self::assertSame(2, Bidi::visualToLogicalColumn(self::SHALOM, 1, BaseDirection::Rtl));
        self::assertSame(1, Bidi::visualToLogicalColumn(self::SHALOM, 2, BaseDirection::Rtl));
        self::assertSame(0, Bidi::visualToLogicalColumn(self::SHALOM, 3, BaseDirection::Rtl));
    }

    public function testVisualToLogicalColumnIsIdentityForUnreorderedContent(): void
    {
        self::assertSame(2, Bidi::visualToLogicalColumn('abc', 2, BaseDirection::Ltr)); // ltr, no rtl
        self::assertSame(1, Bidi::visualToLogicalColumn('abc', 1, BaseDirection::Rtl)); // latin under rtl
    }

    public function testVisualToLogicalColumnHandlesEdges(): void
    {
        self::assertSame(0, Bidi::visualToLogicalColumn(self::SHALOM, -3, BaseDirection::Rtl)); // negative clamps
        self::assertSame(4, Bidi::visualToLogicalColumn(self::SHALOM, 99, BaseDirection::Rtl)); // past end → line end
        self::assertSame(5, Bidi::visualToLogicalColumn('', 5, BaseDirection::Rtl)); // empty line: identity
        self::assertSame(2, Bidi::visualToLogicalColumn("\x1b[0m", 2, BaseDirection::Rtl)); // pure SGR: identity
    }

    public function testVisualToLogicalColumnSkipsTheZeroWidthMarker(): void
    {
        $marker = "\x1b_pi:c;5\x07";
        // The marker occupies no column, so mapping still lands on the glyphs.
        self::assertSame(1, Bidi::visualToLogicalColumn("a{$marker}bc", 1, BaseDirection::Rtl));
    }

    public function testVisualToLogicalColumnMapsWithinWideCharacters(): void
    {
        // 日 occupies screen columns 1–2; a click on its first cell maps to its
        // logical column (1), just past the leading "a".
        self::assertSame(1, Bidi::visualToLogicalColumn("a\u{65E5}b", 1, BaseDirection::Rtl));
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
     * Two exemptions, both principled. ASCII letters and digits ARE strong
     * Latin — `CHECKBOX_ON` is `[x]`. And U+E0A0, the branch icon, is a Private
     * Use codepoint, which Unicode assigns to `L`; it always sits beside its
     * Latin branch name, so following the standard costs nothing here.
     */
    public function testNoGlyphTheInterfaceDrawsIsStrongLatinText(): void
    {
        $offenders = [];
        foreach (new \ReflectionClass(Glyphs::class)->getConstants() as $name => $value) {
            $chars = preg_split('//u', (string) $value, flags: \PREG_SPLIT_NO_EMPTY);
            foreach (false === $chars ? [] : $chars as $char) {
                $codepoint = mb_ord($char, encoding: 'UTF-8');
                // ASCII letters and digits ARE strong Latin (CHECKBOX_ON is
                // "[x]"), and U+E000-F8FF is Private Use, which Unicode assigns
                // to L — the branch icon there sits beside its Latin name.
                $exempt = 1 === preg_match('/[A-Za-z0-9]/', $char) || $codepoint >= 0xE000 && $codepoint <= 0xF8FF;
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
        foreach (array_keys(Probe::getStatic(Bidi::class, 'MIRROR')) as $glyph) {
            if ('L' !== Probe::invokeStatic(Bidi::class, 'classify', (string) $glyph)) {
                continue;
            }

            $unreachable[] = (string) $glyph;
        }

        $this->assertSame([], $unreachable, 'these have a mirror entry they can never reach');
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
        $charPos = mb_strpos($plain, needle: $needle, encoding: 'UTF-8');
        $xPos = mb_strpos($plain, needle: 'x', encoding: 'UTF-8');
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

    #[\PHPUnit\Framework\Attributes\DataProvider('classifyBoundaryProvider')]
    public function testClassifyBoundary(int $codepoint, bool $expectedNonL): void
    {
        $char = mb_chr($codepoint, encoding: 'UTF-8');
        \assert(false !== $char, description: 'the data provider only feeds valid codepoints');
        self::assertSame($expectedNonL, self::classifiesAsNonL($char), \sprintf('codepoint 0x%04X', $codepoint));
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
        self::assertSame('R', $classify->invoke(null, mb_chr(0x0600, encoding: 'UTF-8')), 'R2 start');
        self::assertSame('R', $classify->invoke(null, mb_chr(0x08FF, encoding: 'UTF-8')), 'R3 end');
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
        self::assertTrue(self::digitPullsTheFollowingSpaceRtlward('0'), '0x30, the EN range start');
        self::assertTrue(self::digitPullsTheFollowingSpaceRtlward('9'), '0x39, the EN range end');
    }

    public function testALetterDoesNotPullAnAdjacentSpaceToTheRtlSide(): void
    {
        self::assertFalse(self::digitPullsTheFollowingSpaceRtlward('q'), 'a plain L char leaves the space on its own side');
    }

    public function testConsecutiveStyleChangesBothAccumulateIntoTheActiveState(): void
    {
        // Two SGR codes in a row (no reset between) must both be part of the
        // active style carried forward, not just the last one seen.
        $out = Bidi::reorder("\x1b[31m\x1b[1mAB", BaseDirection::Rtl);
        self::assertStringContainsString("\x1b[31m", $out);
        self::assertStringContainsString("\x1b[1m", $out);
        self::assertSame("\x1b[0m\x1b[31m\x1b[1mAB\x1b[0m", $out);
    }

    public function testLtrBaseWithSgrAndNoRtlContentIsReturnedByteIdentical(): void
    {
        // The LTR/no-RTL shortcut must return the ORIGINAL bytes verbatim —
        // routing SGR-styled content through the full reorder pipeline
        // anyway would re-derive a leading reset that was never there.
        $line = "\x1b[31mred\x1b[0m";
        self::assertSame($line, Bidi::reorder($line, BaseDirection::Ltr));
    }

    public function testVisualToLogicalColumnSkipsAnSgrSequenceNotJustTheCursorMarker(): void
    {
        // An SGR code must never be counted as a column-occupying glyph —
        // exercised with real Hebrew content so a broken skip shifts the
        // mapped column, not just the raw token count.
        $line = "\x1b[31m\u{05E9}\u{05DC}"; // styled, 2 Hebrew chars, reversed under RTL
        self::assertSame(1, Bidi::visualToLogicalColumn($line, 0, BaseDirection::Rtl));
        self::assertSame(0, Bidi::visualToLogicalColumn($line, 1, BaseDirection::Rtl));
    }

    public function testVisualToLogicalColumnMarkerWidthBoundaryIsExactlyZero(): void
    {
        // The cursor marker's width must be exactly 0, not merely "not
        // positive" or "not exactly 1" — pinned via a marker sandwiched
        // between two Hebrew chars, where an off-by-one on its own width
        // shifts which side of the marker a given visual column lands on.
        $marker = "\x1b_pi:c;5\x07";
        $line = "\u{05E9}{$marker}\u{05DC}";
        self::assertSame(1, Bidi::visualToLogicalColumn($line, 0, BaseDirection::Rtl));
        self::assertSame(0, Bidi::visualToLogicalColumn($line, 1, BaseDirection::Rtl));
    }

    public function testClassifyTreatsTheCursorMarkerAsNeutralNotLtr(): void
    {
        // Misclassifying the \x1b-prefixed marker as 'L' (instead of 'N')
        // would keep it on the LTR side of a following 'x' instead of
        // letting it join the Hebrew run it's adjacent to.
        $marker = "\x1b_pi:c;5\x07";
        $out = self::strip(Bidi::reorder("\u{05E9}\u{05DC}{$marker}x", BaseDirection::Rtl));
        self::assertSame("x{$marker}\u{05DC}\u{05E9}", $out);
    }

    public function testABaseCodepointFromAMultiCharGraphemeClusterIsTheFirstOne(): void
    {
        // classify() reads the grapheme's BASE codepoint — for a combining
        // sequence that must be the leading character (Hebrew aleph), not
        // the trailing combining mark, or the cluster would misclassify as
        // neutral/L and land on the wrong side of the reorder.
        $cluster = "\u{05D0}\u{0301}"; // aleph + combining acute, one grapheme
        self::assertSame('b'.$cluster, self::strip(Bidi::reorder($cluster.'b', BaseDirection::Rtl)));
    }

    public function testStyleChangeMidRunEmitsResetBeforeTheNewCode(): void
    {
        // Each style transition re-opens with an explicit RESET before the
        // new SGR code, so a reordered run never inherits a stale style from
        // whatever preceded it in visual order.
        $out = Bidi::reorder("\x1b[31mA\x1b[0mB", BaseDirection::Rtl);
        self::assertSame("\x1b[0m\x1b[31mA\x1b[0mB", $out);
    }
}
