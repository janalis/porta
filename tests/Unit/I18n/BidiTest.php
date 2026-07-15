<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;

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
}
