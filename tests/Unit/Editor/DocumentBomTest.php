<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;

/**
 * The UTF-8 byte-order mark: taken off the text on load, remembered, and put
 * back by the save path.
 *
 * Nothing in this tree handled one. A BOM is valid UTF-8, so a marked file
 * loaded without complaint and carried U+FEFF as the first character of line 0
 * — where `mb_strwidth()` counts it as a column and no terminal draws one, so
 * that line measured wider than it rendered and every column mapped on it was
 * off by one. It also sits exactly where the caret starts, so the first
 * character typed on opening such a file went in FRONT of it, which is
 * invisible on screen and stops it being a byte-order mark.
 *
 * Its own file rather than more methods on {@see DocumentTest}, which is at the
 * method ceiling — the same split {@see DocumentEdgeTest} and
 * {@see DocumentEditorConfigTest} already use.
 *
 * The round trip through a real save is
 * {@see \Porta\Tests\E2e\IdeFilesystemSessionTest::testAFileWithAByteOrderMarkKeepsItAcrossAnEdit()}.
 */
final class DocumentBomTest extends TestCase
{
    /**
     * A UTF-8 BOM is taken off the text and remembered, not carried in it.
     *
     * Nothing in this tree handled one. A BOM is valid UTF-8, so the file
     * loads, and `mb_strwidth()` counts U+FEFF as ONE column while a terminal
     * draws it as none — so line 0 of every BOM-prefixed file measured a column
     * wider than it rendered, and every column the editor maps on that line was
     * off by one.
     *
     * It is also three bytes sitting at column 0, where the caret starts. Type
     * one character on opening such a file and the text goes BEFORE the mark,
     * which is invisible on screen and stops it being a byte-order mark at all.
     *
     * Stripped rather than tolerated, and remembered rather than dropped: the
     * save path puts it back, so a file that arrived with one leaves with one.
     */
    public function testAByteOrderMarkIsStrippedAndRemembered(): void
    {
        $bom = "\u{FEFF}";
        $doc = new Document($bom."<?php\n\necho 1;\n");

        $this->assertTrue($doc->hasByteOrderMark(), 'the file had one');
        $this->assertSame('<?php', $doc->getLine(0), 'and the text no longer carries it');
        $this->assertStringNotContainsString($bom, $doc->getText());
    }

    /** Typing where the caret starts no longer lands in front of the mark. */
    public function testTypingAtTheStartOfABomFileDoesNotDisplaceTheMark(): void
    {
        $doc = new Document("\u{FEFF}<?php\n");
        $doc->setCursor(0, 0);

        $doc->insert('X');

        $this->assertSame('X<?php', $doc->getLine(0));
        $this->assertTrue($doc->hasByteOrderMark(), 'and it is still remembered for the save');
    }

    /** A reload through the other door is the same file, so it answers the same. */
    public function testAReloadedFileKeepsItsOwnAnswerAboutTheMark(): void
    {
        $doc = new Document("plain\n");
        $this->assertFalse($doc->hasByteOrderMark());

        $doc->replaceAll("\u{FEFF}marked\n");
        $this->assertTrue($doc->hasByteOrderMark(), 'the reload brought one');
        $this->assertSame('marked', $doc->getLine(0));

        $doc->replaceAll("plain again\n");
        $this->assertFalse($doc->hasByteOrderMark(), 'and a later reload without one clears it');
    }
}
