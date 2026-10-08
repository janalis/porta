<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\TestCase;
use Porta\Markdown\MappedText;
use Porta\Markdown\SourceRange;
use Porta\Markdown\SourceText;

/** Byte offsets to the editor's (line, byte column) and back, and text that remembers where it came from. */
final class SourceTextTest extends TestCase
{
    public function testOffsetsConvertToLinesAndColumnsAndBack(): void
    {
        $source = new SourceText("ab\ncde\n\nf");
        $this->assertSame([0, 0, 1, 1, 2, 3], \array_map($source->lineOf(...), [0, 2, 3, 6, 7, 8]));
        $this->assertSame(2, $source->columnOf(5));
        $this->assertSame(5, $source->offsetOf(1, 2));
        $this->assertSame(9, $source->offsetOf(9, 9), 'clamped to the end');
        $this->assertSame(0, $source->offsetOf(-1, -1), 'clamped to the start');
        $this->assertSame('cde', $source->slice(new SourceRange(3, 6)));
    }

    public function testMappedTextKeepsEachPiecesSource(): void
    {
        // "ab" written at 10, "cd" at 20: as if a quote marker sat between them.
        $text = MappedText::verbatim('ab', 10)->append(MappedText::verbatim('cd', 20));
        $this->assertSame('abcd', $text->text);
        $this->assertSame([10, 11, 20, 21, 22], \array_map($text->source(...), [0, 1, 2, 3, 4]));
        $this->assertEquals(new SourceRange(11, 21), $text->range(1, 3));
        $this->assertEquals(new SourceRange(20, 20), $text->range(2, 2), 'an empty range sits where it starts');
        $this->assertSame([20, 21], \array_map($text->from(2)->source(...), [0, 1]));
        $this->assertSame([10, 11], \array_map($text->head(2)->source(...), [0, 1]));
    }

    public function testRangesCompose(): void
    {
        $range = new SourceRange(3, 8);
        $this->assertSame(5, $range->length());
        $this->assertTrue($range->contains(new SourceRange(3, 8)));
        $this->assertFalse($range->contains(new SourceRange(2, 4)));
        $this->assertFalse($range->contains(new SourceRange(7, 9)));
        $this->assertEquals(new SourceRange(1, 8), $range->union(new SourceRange(1, 4)));
    }
}
