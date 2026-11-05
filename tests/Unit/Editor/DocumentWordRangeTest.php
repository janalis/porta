<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;

final class DocumentWordRangeTest extends TestCase
{
    public function testMidWord(): void
    {
        $doc = new Document('  foo bar');

        $this->assertSame(['startCol' => 2, 'endCol' => 5], $doc->wordRangeAt(0, 3));
    }

    public function testAtWordStartAndEnd(): void
    {
        $doc = new Document('  foo bar');

        $this->assertSame(['startCol' => 2, 'endCol' => 5], $doc->wordRangeAt(0, 2), 'at the start of foo');
        $this->assertSame(['startCol' => 2, 'endCol' => 5], $doc->wordRangeAt(0, 5), 'at the end of foo');
        $this->assertSame(['startCol' => 6, 'endCol' => 9], $doc->wordRangeAt(0, 7), 'inside bar');
    }

    /** A letter of any script is part of the word: byte by byte, `$café` stopped at `$caf` and `名前` was no word. */
    public function testALetterOfAnyScriptIsPartOfTheWord(): void
    {
        $doc = new Document('echo $café; 名前 = 1;');

        $this->assertSame(['startCol' => 5, 'endCol' => 11], $doc->wordRangeAt(0, 7));
        $this->assertSame(['startCol' => 13, 'endCol' => 19], $doc->wordRangeAt(0, 16), 'from the middle of a CJK word, at a character boundary');
    }

    public function testOnWhitespaceIsNull(): void
    {
        $doc = new Document('  foo');

        $this->assertNull($doc->wordRangeAt(0, 1), 'a space is not a word');
        $this->assertNull($doc->wordRangeAt(0, 0));
    }

    public function testDollarPrefixedIdentifierIncludesSigil(): void
    {
        $doc = new Document('$var = 1');

        $this->assertSame(['startCol' => 0, 'endCol' => 4], $doc->wordRangeAt(0, 2));
    }

    public function testOutOfRangeColumnClamps(): void
    {
        $doc = new Document('foo');

        $this->assertSame(['startCol' => 0, 'endCol' => 3], $doc->wordRangeAt(0, 999));
    }

    public function testEmptyLineIsNull(): void
    {
        $doc = new Document("\nx");

        $this->assertNull($doc->wordRangeAt(0, 0));
    }
}
