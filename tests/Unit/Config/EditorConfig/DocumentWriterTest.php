<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config\EditorConfig;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\DocumentWriter;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Editor\Document;

final class DocumentWriterTest extends TestCase
{
    private function config(array $props): ResolvedEditorConfig
    {
        return ResolvedEditorConfig::fromProps($props);
    }

    public function testAllNullMatchesGetText(): void
    {
        $doc = new Document("a  \nb\t\n");

        $this->assertSame($doc->getText(), DocumentWriter::render($doc, ResolvedEditorConfig::none()));
    }

    public function testTrimTrailingWhitespace(): void
    {
        $doc = new Document("a  \nb\t\t\nc");

        $this->assertSame("a\nb\nc", DocumentWriter::render($doc, $this->config(['trim_trailing_whitespace' => 'true'])));
    }

    public function testTrimDisabledLeavesWhitespace(): void
    {
        $doc = new Document("a  \nb");

        $this->assertSame("a  \nb", DocumentWriter::render($doc, $this->config(['trim_trailing_whitespace' => 'false'])));
    }

    public function testCrlfEndOfLine(): void
    {
        $doc = new Document("a\nb\nc");

        $this->assertSame("a\r\nb\r\nc", DocumentWriter::render($doc, $this->config(['end_of_line' => 'crlf'])));
    }

    public function testCrEndOfLine(): void
    {
        $doc = new Document("a\nb");

        $this->assertSame("a\rb", DocumentWriter::render($doc, $this->config(['end_of_line' => 'cr'])));
    }

    /**
     * `end_of_line = lf` OVERRIDES the file's own ending.
     *
     * The arm that matters now that a missing setting means "keep what the file
     * had": without it, a CRLF file under an `.editorconfig` demanding LF would
     * fall through to the document's answer and keep its CRLF, which is the
     * opposite of what the file asked for.
     */
    public function testLfEndOfLineOverridesACrlfFile(): void
    {
        $doc = new Document("a\r\nb\r\n");
        $this->assertSame("\r\n", $doc->eol, 'the document knows it loaded CRLF');

        $this->assertSame("a\nb\n", DocumentWriter::render($doc, $this->config(['end_of_line' => 'lf'])));
    }

    /** With no setting at all, the file's own ending is written back. */
    public function testWithoutASettingTheFilesOwnEndingIsKept(): void
    {
        $doc = new Document("a\r\nb\r\n");

        $this->assertSame("a\r\nb\r\n", DocumentWriter::render($doc, $this->config([])));
    }

    public function testInsertFinalNewlineAddsWhenMissing(): void
    {
        $doc = new Document("a\nb"); // no trailing newline

        $this->assertSame("a\nb\n", DocumentWriter::render($doc, $this->config(['insert_final_newline' => 'true'])));
    }

    public function testInsertFinalNewlineDoesNotDoubleUp(): void
    {
        $doc = new Document("a\nb\n"); // already ends with a newline (trailing empty line)

        $this->assertSame("a\nb\n", DocumentWriter::render($doc, $this->config(['insert_final_newline' => 'true'])));
    }

    public function testInsertFinalNewlineCrlf(): void
    {
        $doc = new Document('a');

        $this->assertSame("a\r\n", DocumentWriter::render($doc, $this->config(['insert_final_newline' => 'true', 'end_of_line' => 'crlf'])));
    }

    public function testFinalNewlineFalseStripsAllTrailing(): void
    {
        $doc = new Document("a\n\n\n"); // three trailing newlines

        $this->assertSame('a', DocumentWriter::render($doc, $this->config(['insert_final_newline' => 'false'])));
    }

    public function testFinalNewlineFalseOnAlreadyBareText(): void
    {
        $doc = new Document('a');

        $this->assertSame('a', DocumentWriter::render($doc, $this->config(['insert_final_newline' => 'false'])));
    }

    public function testCombinedTrimCrlfAndFinalNewline(): void
    {
        $doc = new Document("x  \ny\t");

        $this->assertSame(
            "x\r\ny\r\n",
            DocumentWriter::render($doc, $this->config([
                'trim_trailing_whitespace' => 'true',
                'end_of_line' => 'crlf',
                'insert_final_newline' => 'true',
            ])),
        );
    }
}
