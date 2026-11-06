<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiskText;
use Porta\Editor\Document;

/**
 * The round trip between a file's bytes and the text a document holds, asked of
 * DiskText directly: its callers (a rename's write, an agent's kept proposal)
 * each see one shape, and a CRLF or BOM file that comes back as LF without its
 * mark is a whole-file diff nobody made.
 */
final class DiskTextTest extends TestCase
{
    public function testTextGoesBackInTheShapeItsSenderUsed(): void
    {
        $sent = Document::BOM."one\r\ntwo\r\n";

        $this->assertSame($sent, DiskText::inShapeOf(DiskText::asHeld($sent), $sent), 'a CRLF file with a mark comes back byte for byte');
        $this->assertSame("a\rb", DiskText::inShapeOf("a\nb", "x\ry"), 'a CR file keeps its CR');
        $this->assertSame("a\nb", DiskText::inShapeOf("a\nb", 'no break at all'), 'no break in what was sent writes \n, and no mark appears');
    }

    public function testADocumentIsWrittenWithTheEndingAndMarkItWasLoadedWith(): void
    {
        $bytes = Document::BOM."one\r\ntwo\r\n";

        $this->assertSame($bytes, DiskText::asWritten(new Document($bytes)));
        $this->assertSame("one\ntwo\n", DiskText::asWritten(new Document("one\ntwo\n")));
    }
}
