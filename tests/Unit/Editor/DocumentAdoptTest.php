<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;

/**
 * What a document REMEMBERS about the text it was loaded from: the UTF-8
 * byte-order mark, and the line ending. Both are taken off the text on load and
 * put back by the save path.
 *
 * Nothing in this tree handled one. A BOM is valid UTF-8, so a marked file
 * loaded without complaint and carried U+FEFF as the first character of line 0
 * — where `mb_strwidth()` counts it as a column and no terminal draws one, so
 * that line measured wider than it rendered and every column mapped on it was
 * off by one. It also sits exactly where the caret starts, so the first
 * character typed on opening such a file went in FRONT of it, which is
 * invisible on screen and stops it being a byte-order mark.
 *
 * The line ending is the same story with a bigger blast radius: lines are held
 * LF-joined whatever the file used, and the save path defaulted to LF — so the
 * first save of a CRLF file rewrote every line in it. A whole-file diff on
 * every Windows-authored file the user touched.
 *
 * Its own file rather than more methods on {@see DocumentTest}, which is at the
 * method ceiling — the same split {@see DocumentEdgeTest} and
 * {@see DocumentEditorConfigTest} already use.
 *
 * The round trip through a real save is
 * {@see \Porta\Tests\E2e\IdeFilesystemSessionTest::testAFileWithAByteOrderMarkKeepsItAcrossAnEdit()}.
 */
final class DocumentAdoptTest extends TestCase
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

        $this->assertTrue($doc->hasByteOrderMark, 'the file had one');
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
        $this->assertTrue($doc->hasByteOrderMark, 'and it is still remembered for the save');
    }

    /** A reload through the other door is the same file, so it answers the same. */
    public function testAReloadedFileKeepsItsOwnAnswerAboutTheMark(): void
    {
        $doc = new Document("plain\n");
        $this->assertFalse($doc->hasByteOrderMark);

        $doc->reload("\u{FEFF}marked\n");
        $this->assertTrue($doc->hasByteOrderMark, 'the reload brought one');
        $this->assertSame('marked', $doc->getLine(0));

        $doc->reload("plain again\n");
        $this->assertFalse($doc->hasByteOrderMark, 'and a later reload without one clears it');
    }

    /** The line ending the file used is remembered, not just normalised away. */
    public function testTheLoadedLineEndingIsRemembered(): void
    {
        $this->assertSame("\n", new Document("a\nb\n")->eol, 'plain LF');
        $this->assertSame("\r\n", new Document("a\r\nb\r\n")->eol, 'CRLF');
        $this->assertSame("\r", new Document("a\rb\r")->eol, 'lone CR');
        $this->assertSame("\n", new Document('no endings at all')->eol, 'nothing to go on');
    }

    /** A mixed file answers for the ending it OPENS with. */
    public function testAMixedFileAnswersForItsFirstEnding(): void
    {
        $this->assertSame("\r\n", new Document("a\r\nb\nc\n")->eol);
        $this->assertSame("\n", new Document("a\nb\nc\r\n")->eol, 'one pasted CRLF line further down is not the file\'s ending');
        $this->assertSame("\n", new Document("a\nb\rc\n")->eol, 'nor is a stray CR');
        $this->assertSame("\r", new Document("a\rb\r\n")->eol, 'a CR first is a CR file');
    }

    /** A reload adopts the new text's ending rather than keeping the old answer. */
    public function testAReloadAdoptsTheNewEnding(): void
    {
        $doc = new Document("a\r\n");
        $this->assertSame("\r\n", $doc->eol);

        $doc->reload("a\nb\n");

        $this->assertSame("\n", $doc->eol, 'the reload brought LF');
    }

    /**
     * An edit of the whole text — project replace, a formatter — is made of
     * getText()'s `\n`-joined, mark-less text: the file keeps its own shape.
     */
    public function testAWholeTextEditKeepsTheFilesShape(): void
    {
        $doc = new Document("\u{FEFF}a\r\nb\r\n");

        $doc->replaceAll("a\nB\n");

        $this->assertSame("\r\n", $doc->eol);
        $this->assertTrue($doc->hasByteOrderMark);
        $this->assertSame("a\nB\n", $doc->getText());
        $doc->undo();
        $this->assertSame("a\nb\n", $doc->getText(), 'one undoable edit');
    }
}
