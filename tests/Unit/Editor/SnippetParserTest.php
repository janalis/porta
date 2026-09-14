<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\SnippetParser;
use Porta\Editor\SnippetTabStop;

final class SnippetParserTest extends TestCase
{
    public function testPlainTextHasNoStops(): void
    {
        $result = SnippetParser::parse('strlen');
        $this->assertSame('strlen', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testPlaceholdersAndFinalStop(): void
    {
        $result = SnippetParser::parse('foo(${1:bar}, ${2:baz})$0');
        $this->assertSame('foo(bar, baz)', $result['text']);
        $this->assertEquals(
            [
                new SnippetTabStop(1, 4, 7),
                new SnippetTabStop(2, 9, 12),
                new SnippetTabStop(0, 13, 13),
            ],
            $result['stops'],
        );
    }

    public function testBareNumberedStops(): void
    {
        $result = SnippetParser::parse('$1 => $2');
        $this->assertSame(' => ', $result['text']);
        $this->assertEquals(
            [
                new SnippetTabStop(1, 0, 0),
                new SnippetTabStop(2, 4, 4),
            ],
            $result['stops'],
        );
    }

    public function testEscapesAreLiteral(): void
    {
        $result = SnippetParser::parse('price is \\$5 and \\}');
        $this->assertSame('price is $5 and }', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testChoiceUsesFirstOption(): void
    {
        $result = SnippetParser::parse('visibility ${1|public,private,protected|}');
        $this->assertSame('visibility public', $result['text']);
        $this->assertEquals([new SnippetTabStop(1, 11, 17)], $result['stops']);
    }

    public function testRepeatedIndexEmitsTextButOnlyOneStop(): void
    {
        // The second $1 is a mirror: its text is emitted, but it is not a stop.
        $result = SnippetParser::parse('${1:x}-$1');
        $this->assertSame('x-', $result['text']);
        $this->assertEquals([new SnippetTabStop(1, 0, 1)], $result['stops']);
    }

    public function testNestedPlaceholderIsFlattened(): void
    {
        $result = SnippetParser::parse('${1:${2:inner}}');
        $this->assertSame('inner', $result['text']);
        // The outer placeholder spans the whole default including the nested text.
        $this->assertSame(1, $result['stops'][0]->index);
        $this->assertSame(0, $result['stops'][0]->start);
    }

    public function testFinalStopSortsLast(): void
    {
        $result = SnippetParser::parse('$0 first-written $1');
        $indices = \array_column($result['stops'], 'index');
        $this->assertSame([1, 0], $indices, '$0 always visited last');
    }

    public function testUnmatchedBraceIsLiteral(): void
    {
        // A malformed placeholder with no closing brace stays literal text.
        $result = SnippetParser::parse('a ${1:b');
        $this->assertSame('a ${1:b', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testDollarVariableWithoutIndexIsLiteral(): void
    {
        $result = SnippetParser::parse('${TM_FILENAME}');
        $this->assertSame('${TM_FILENAME}', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testBracedIndexWithoutDefault(): void
    {
        // ${1} is a zero-width tab stop just like $1.
        $result = SnippetParser::parse('a${1}b');
        $this->assertSame('ab', $result['text']);
        $this->assertEquals([new SnippetTabStop(1, 1, 1)], $result['stops']);
    }

    public function testEscapedBraceInsidePlaceholder(): void
    {
        // The escaped \} must not end the placeholder early.
        $result = SnippetParser::parse('${1:a\\}b}');
        $this->assertSame('a}b', $result['text']);
        $this->assertEquals([new SnippetTabStop(1, 0, 3)], $result['stops']);
    }

    public function testTrailingBackslashIsLiteral(): void
    {
        // A backslash as the very last byte has nothing left to escape; it
        // must not read past the end of the string.
        $result = SnippetParser::parse('x\\');
        $this->assertSame('x\\', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testTrailingDollarIsLiteral(): void
    {
        // A `$` as the very last byte has no `{`/digit to look ahead at; it
        // must not read past the end of the string.
        $result = SnippetParser::parse('x$');
        $this->assertSame('x$', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testNestedPlaceholderOffsetsAreRelativeToOuterStart(): void
    {
        // The nested stop must actually be recorded, and its offsets shifted
        // by how much literal text precedes it inside the outer default.
        $result = SnippetParser::parse('${1:x${2:inner}}');
        $this->assertSame('xinner', $result['text']);
        $byIndex = [];
        foreach ($result['stops'] as $stop) {
            $byIndex[$stop->index] = $stop;
        }
        $this->assertCount(2, $byIndex);
        $this->assertSame(1, $byIndex[2]->start);
        $this->assertSame(6, $byIndex[2]->end);
    }

    public function testUnmatchedBraceWithNoClosingEverStaysLiteral(): void
    {
        // No closing brace anywhere in the snippet: matchingBrace's -1
        // sentinel must be trusted exactly, or the parser mistakes trailing
        // garbage for a real stop (and can even loop forever re-scanning
        // from the start).
        $snippet = '${1:'.\str_repeat('b', times: 20);
        $result = SnippetParser::parse($snippet);
        $this->assertSame($snippet, $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testColonFormRequiresIndexAtStart(): void
    {
        // A leading non-digit before the index means this isn't `${N:...}`
        // syntax at all — it must stay fully literal.
        $result = SnippetParser::parse('${x1:default}');
        $this->assertSame('${x1:default}', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testColonFormDefaultMayContainNewlines(): void
    {
        $result = SnippetParser::parse("\${1:line1\nline2}");
        $this->assertSame("line1\nline2", $result['text']);
        $this->assertEquals([new SnippetTabStop(1, 0, 11)], $result['stops']);
    }

    public function testChoiceFormRequiresExactClosingPipe(): void
    {
        // Trailing content after the closing `|` breaks the choice syntax —
        // it must not silently truncate and still parse as a placeholder.
        $result = SnippetParser::parse('${1|a,b,c|extra}');
        $this->assertSame('${1|a,b,c|extra}', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testBraceIndexRequiresPureDigitsAnchoredAtStart(): void
    {
        // A leading non-digit means it is not a plain index placeholder — it
        // must stay literal, not silently parse via a loose `(int)` cast.
        $result = SnippetParser::parse('${x123}');
        $this->assertSame('${x123}', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testBraceIndexRequiresPureDigitsAnchoredAtEnd(): void
    {
        // Trailing garbage after the digits means it is not a plain index
        // placeholder either.
        $result = SnippetParser::parse('${123abc}');
        $this->assertSame('${123abc}', $result['text']);
        $this->assertSame([], $result['stops']);
    }

    public function testEscapedClosingBraceInsideADefaultDoesNotEndTheMatch(): void
    {
        // matchingBrace() must skip the char right after a `\` — otherwise
        // this `\}` is misread as the real closer, one character early.
        $result = SnippetParser::parse('${1:x\\}y}');
        $this->assertSame('x}y', $result['text']);
        $this->assertEquals([new SnippetTabStop(1, 0, 3)], $result['stops']);
    }
}
