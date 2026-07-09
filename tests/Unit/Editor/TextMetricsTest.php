<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\TextMetrics;

final class TextMetricsTest extends TestCase
{
    public function testCharBoundariesWalkMultibyte(): void
    {
        $line = 'aé好b'; // a(1) é(2) 好(3) b(1)
        $this->assertSame(1, TextMetrics::nextCharBoundary($line, 0));
        $this->assertSame(3, TextMetrics::nextCharBoundary($line, 1), 'skips the 2-byte é');
        $this->assertSame(6, TextMetrics::nextCharBoundary($line, 3), 'skips the 3-byte 好');

        $this->assertSame(3, TextMetrics::prevCharBoundary($line, 6));
        $this->assertSame(1, TextMetrics::prevCharBoundary($line, 3));
        $this->assertSame(0, TextMetrics::prevCharBoundary($line, 1));
    }

    public function testDisplayWidthExpandsTabs(): void
    {
        $this->assertSame(0, TextMetrics::displayWidth('', 4));
        $this->assertSame(5, TextMetrics::displayWidth('hello', 4), 'ascii fast path');
        // "a" then tab → next stop at 4, so width 4; plus "b" → 5
        $this->assertSame(5, TextMetrics::displayWidth("a\tb", 4));
        $this->assertSame(4, TextMetrics::displayWidth("\t", 4));
    }

    public function testExpandTabsProducesSpacesToStops(): void
    {
        $this->assertSame('a   b', TextMetrics::expandTabs("a\tb", 4));
        $this->assertSame('    x', TextMetrics::expandTabs("\tx", 4));
    }

    public function testByteColForDisplayColHandlesTabsAndAscii(): void
    {
        $this->assertSame(0, TextMetrics::byteColForDisplayCol('', 5, 4));
        $this->assertSame(3, TextMetrics::byteColForDisplayCol('abcdef', 3, 4), 'ascii');
        // "a\tb": display col 4 lands just after the tab (byte 2)
        $this->assertSame(2, TextMetrics::byteColForDisplayCol("a\tb", 4, 4));
    }

    public function testUtf16RoundTrip(): void
    {
        $line = 'x😀y'; // 😀 is 4 UTF-8 bytes / 2 UTF-16 code units
        $u = TextMetrics::byteColToUtf16($line, \strlen($line));
        $this->assertSame(4, $u, 'x(1) + 😀(2) + y(1) = 4 UTF-16 units');
        $this->assertSame(\strlen($line), TextMetrics::utf16ColToByteCol($line, $u));
        $this->assertSame(0, TextMetrics::byteColToUtf16($line, 0));
        $this->assertSame(0, TextMetrics::utf16ColToByteCol($line, 0));
    }

    /**
     * offsetAt / positionAt on Document round-trip for every position — the
     * property the widget's structural selection relies on.
     */
    public function testDocumentOffsetPositionRoundTrip(): void
    {
        $doc = new Document("abc\n\ndéf\nghi");
        $text = $doc->getText();

        for ($offset = 0; $offset <= \strlen($text); ++$offset) {
            $pos = $doc->positionAt($offset);
            $back = $doc->offsetAt($pos['line'], $pos['col']);
            // Offsets that land mid-multibyte-char clamp to a boundary, but every
            // offset that IS a valid boundary must round-trip exactly.
            $this->assertSame(
                $doc->positionAt($back),
                $pos,
                "position stable through offset {$offset}",
            );
        }
    }

    public function testDocumentOffsetAtClampsOutOfRange(): void
    {
        $doc = new Document("ab\ncd");
        $this->assertSame(0, $doc->offsetAt(-5, -5));
        // Beyond the end clamps to the last line's end (offset of 'cd' end = 5).
        $this->assertSame(5, $doc->offsetAt(99, 99));
    }

    public function testCharBoundaryAtOrBeforeSnapsOffMidCharacterBytes(): void
    {
        // "a—b": 'a' (1) + em-dash (3 bytes: 2..4) + 'b' (5).
        $line = "a\u{2014}b";
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore($line, 1), 'already a boundary');
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore($line, 2), 'mid em-dash → its start');
        $this->assertSame(1, TextMetrics::charBoundaryAtOrBefore($line, 3), 'mid em-dash → its start');
        $this->assertSame(4, TextMetrics::charBoundaryAtOrBefore($line, 4), 'after the em-dash');
        $this->assertSame(5, TextMetrics::charBoundaryAtOrBefore($line, 99), 'past the end clamps to length');
        $this->assertSame(0, TextMetrics::charBoundaryAtOrBefore($line, -3), 'before the start clamps to 0');
    }
}
