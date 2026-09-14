<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CaretWord;
use Porta\Editor\Document;

/**
 * One rule, asked by three features. Its edges matter because they decide what
 * a keystroke searches for and what a multi-caret matches.
 */
final class CaretWordTest extends TestCase
{
    private function wordAt(string $line, int $col): string
    {
        $document = new Document($line);
        $document->setCursor(0, $col);

        return CaretWord::at($document);
    }

    public function testTheCaretINSIDEAWordTakesTheWholeWord(): void
    {
        $this->assertSame('handle', $this->wordAt('$this->handle();', 10));
    }

    public function testTheCaretAtEitherEDGETakesItToo(): void
    {
        // Standing just before the first character and just after the last are
        // both "in" the word: an editor that disagreed would make the answer
        // depend on which side you arrived from.
        $this->assertSame('handle', $this->wordAt('$this->handle();', 7), 'at the start');
        $this->assertSame('handle', $this->wordAt('$this->handle();', 13), 'at the end');
    }

    public function testAPhpVariableKEEPSItsSigil(): void
    {
        // `$name` rather than `name`: searching without the sigil also finds
        // the property, the parameter and the word inside a string.
        $this->assertSame('$name', $this->wordAt('    $name = 1;', 7));
        $this->assertSame('$name', $this->wordAt('    $name = 1;', 4), 'even from just before the $');
    }

    public function testTheSigilIsTakenOnTheLEFTOnly(): void
    {
        // `$a$b` is not an identifier in any language here, so a `$` on the
        // right belongs to the NEXT word, not this one.
        $this->assertSame('$a', $this->wordAt('$a$b', 2));
    }

    public function testAUNICODEIdentifierIsOneWord(): void
    {
        // PHP allows bytes 0x80-0xff in a name, so `$café` is a variable. Both
        // halves of the scan need the `u` flag to see it: without it `\w` is
        // ASCII-only and the accented letter reads as a word boundary, cutting
        // the name in two on whichever side the caret is.
        $line = '$café = 1;';

        $this->assertSame('$café', $this->wordAt($line, 4), 'from the middle, reaching right over the é');
        $this->assertSame('$café', $this->wordAt($line, 6), 'and from the end, reaching left over it');
    }

    public function testStandingInNOWordAnswersNothing(): void
    {
        $this->assertSame('', $this->wordAt('a + b', 2), 'on the operator');
        $this->assertSame('', $this->wordAt('', 0), 'on an empty line');
        $this->assertSame('', $this->wordAt('   ', 1), 'in whitespace');
    }

    public function testItReadsTHISLineOnly(): void
    {
        $document = new Document("first\nsecond\n");
        $document->setCursor(1, 3);

        $this->assertSame('second', CaretWord::at($document));
    }
}
