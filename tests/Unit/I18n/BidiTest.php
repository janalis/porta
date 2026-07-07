<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
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
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    public function testEmptyLineIsReturnedUnchanged(): void
    {
        self::assertSame('', Bidi::reorder('', true));
    }

    public function testAPureEscapeSequenceLineHasNothingToReorder(): void
    {
        self::assertSame("\x1b[0m", Bidi::reorder("\x1b[0m", true));
    }

    public function testLtrBaseWithoutRtlContentIsReturnedUnchanged(): void
    {
        self::assertSame('hello world', Bidi::reorder('hello world', false));
    }

    public function testLatinUnderRtlBaseStaysReadableButRightAnchored(): void
    {
        self::assertSame('abc', self::strip(Bidi::reorder('abc', true)));
        self::assertSame('   abc', self::strip(Bidi::reorder('abc   ', true)));
    }

    public function testHebrewUnderRtlBaseIsReversedToVisualOrder(): void
    {
        self::assertSame(self::SHALOM_VISUAL, self::strip(Bidi::reorder(self::SHALOM, true)));
    }

    public function testMixedLineOrdersRunsForTheRtlBase(): void
    {
        $line = 'abc ' . self::SHALOM . ' def';
        self::assertSame('def ' . self::SHALOM_VISUAL . ' abc', self::strip(Bidi::reorder($line, true)));
    }

    public function testHebrewRunReversesWhileLatinStaysUnderAnLtrBase(): void
    {
        $line = 'x ' . self::SHALOM . ' y';
        self::assertSame('x ' . self::SHALOM_VISUAL . ' y', self::strip(Bidi::reorder($line, false)));
    }

    public function testDigitsReadLeftToRightUnderAnRtlBase(): void
    {
        self::assertSame(self::SHALOM_VISUAL . ' 12', self::strip(Bidi::reorder('12 ' . self::SHALOM, true)));
    }

    public function testWideCharacterWidthIsPreserved(): void
    {
        $out = self::strip(Bidi::reorder("a\u{65E5}b", true)); // 日 is two columns
        self::assertSame(4, mb_strwidth($out, 'UTF-8'));
    }

    public function testSgrColourSpansTravelWithTheirText(): void
    {
        $out = Bidi::reorder("\x1b[31mred\x1b[0m", true);
        self::assertSame('red', self::strip($out));
        self::assertStringContainsString("\x1b[31m", $out, 'the colour is retained');
    }

    public function testStyleChangesAndAFinalResetAreEmitted(): void
    {
        // "A" is red, "B" is default (after reset); reordered under RTL base.
        $out = Bidi::reorder("\x1b[31mA\x1b[0mB", true);
        self::assertSame('AB', self::strip($out));
        // A styled span still opens with red somewhere in the output.
        self::assertStringContainsString("\x1b[31m", $out);

        // A line that never resets keeps its style active to the end.
        $trailing = Bidi::reorder("\x1b[1mAB", true);
        self::assertStringEndsWith("\x1b[0m", $trailing);
    }

    public function testPairedPunctuationMirrorsInsideARightToLeftRun(): void
    {
        // A parenthesis adjacent to Hebrew resolves rtl and mirrors under L4.
        $out = self::strip(Bidi::reorder('(' . self::SHALOM, true));
        self::assertStringContainsString(')', $out, 'the opening paren mirrors to a closing one');
        self::assertStringNotContainsString('(', $out);
    }

    public function testNeutralsBetweenMatchingRunsJoinThatSide(): void
    {
        // Two Latin words split by a space stay in reading order (space joins L).
        self::assertSame('one two', self::strip(Bidi::reorder('one two', true)));
    }

    public function testACombiningGraphemeClusterIsCarriedThroughIntact(): void
    {
        // "e" + combining acute is one grapheme cluster (classified by its base
        // codepoint); it must stay a single intact cell through the reorder.
        $line = "e\u{0301}";
        self::assertSame($line, self::strip(Bidi::reorder($line, true)));
    }
}
