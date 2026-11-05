<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\TextDiff;

final class TextDiffTest extends TestCase
{
    public function testSingleCharacterInsertion(): void
    {
        // "ab" -> "aXb": insert "X" at offset 1 (line 0, char 1).
        $change = TextDiff::incrementalChange('ab', 'aXb');

        $this->assertSame(['line' => 0, 'character' => 1], $change['range']['start']);
        $this->assertSame(['line' => 0, 'character' => 1], $change['range']['end']);
        $this->assertSame(0, $change['rangeLength']);
        $this->assertSame('X', $change['text']);
    }

    public function testDeletionAcrossLines(): void
    {
        // Delete "b\nc" leaving "a\nd".
        $old = "a\nbXc\nd";
        $new = "a\nc\nd";
        $change = TextDiff::incrementalChange($old, $new);

        // Common prefix "a\n" (offset 2 -> line 1, char 0), suffix "c\nd".
        $this->assertSame(['line' => 1, 'character' => 0], $change['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 2], $change['range']['end']);
        $this->assertSame(2, $change['rangeLength']);
        $this->assertSame('', $change['text']);
    }

    public function testReplacementInTheMiddleOfALaterLine(): void
    {
        $old = "one\ntwo\nthree";
        $new = "one\nTWO\nthree";
        $change = TextDiff::incrementalChange($old, $new);

        $this->assertSame(['line' => 1, 'character' => 0], $change['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 3], $change['range']['end']);
        $this->assertSame('TWO', $change['text']);
    }

    public function testAppendAtEnd(): void
    {
        $change = TextDiff::incrementalChange("a\nb", "a\nbc");
        $this->assertSame(['line' => 1, 'character' => 1], $change['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 1], $change['range']['end']);
        $this->assertSame('c', $change['text']);
    }

    public function testTheRangeLengthIsInUtf16UnitsNotBytes(): void
    {
        $this->assertSame(1, TextDiff::incrementalChange('café', 'caf')['rangeLength'], 'é: two bytes, one unit');
        $this->assertSame(2, TextDiff::incrementalChange('a😀', 'a')['rangeLength'], 'an emoji: four bytes, two units');
    }

    public function testIdenticalTextProducesEmptyChangeAtEnd(): void
    {
        $change = TextDiff::incrementalChange('same', 'same');
        $this->assertSame('', $change['text']);
        $this->assertSame(0, $change['rangeLength']);
        // Whole string is common prefix; the empty range sits at the end.
        $this->assertSame($change['range']['start'], $change['range']['end']);
    }

    public function testMultibyteBoundaryIsNotSplit(): void
    {
        // "café" -> "cafés": the é is 2 bytes; the change must land after it,
        // not split the sequence. UTF-16 column of "café" is 4.
        $change = TextDiff::incrementalChange('café', 'cafés');
        $this->assertSame(['line' => 0, 'character' => 4], $change['range']['start']);
        $this->assertSame('s', $change['text']);
    }

    public function testMultibyteReplacementKeepsValidUtf8(): void
    {
        // Replace the trailing é with è — both 2-byte. The prefix/suffix trim
        // must not slice through either character.
        $change = TextDiff::incrementalChange('café', 'cafè');
        $this->assertSame('è', $change['text']);
        $this->assertSame(['line' => 0, 'character' => 3], $change['range']['start']);
        $this->assertSame(['line' => 0, 'character' => 4], $change['range']['end']);
    }

    public function testALeadingStrayContinuationByteDoesNotBackUpPastZero(): void
    {
        // `$new` starts with a stray UTF-8 continuation byte (invalid on its
        // own) and `$old` is empty, so the common prefix is already 0 — the
        // "back up onto a lead byte" loop must not decrement past that.
        $change = TextDiff::incrementalChange('', "\x80abc");

        $this->assertSame(0, $change['rangeLength']);
        $this->assertSame("\x80abc", $change['text']);
    }

    public function testAContinuationByteWithItsLowBitSetStillBacksUpThePrefix(): void
    {
        // 0x81 (10000001) is a continuation byte by its top two bits alone;
        // a mask that also examines the low bit would wrongly treat it as a
        // lead byte and stop backing up one byte too early.
        $change = TextDiff::incrementalChange('ab', "a\x81");

        $this->assertSame(['line' => 0, 'character' => 0], $change['range']['start']);
        $this->assertSame(2, $change['rangeLength']);
        $this->assertSame("a\x81", $change['text']);
    }

    public function testMultibyteCharsSharingTrailingByteAreNotMisaligned(): void
    {
        // "é" (0xC3 0xA9) -> "ĩ" (0xC4 0xA9): the shared trailing 0xA9 byte is a
        // continuation byte, so the suffix boundary must snap back to a lead
        // byte and replace the whole character rather than a dangling byte.
        $change = TextDiff::incrementalChange('é', 'ĩ');
        $this->assertSame('ĩ', $change['text']);
        $this->assertSame(['line' => 0, 'character' => 0], $change['range']['start']);
        $this->assertSame(['line' => 0, 'character' => 1], $change['range']['end']);
    }
}
