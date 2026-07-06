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
