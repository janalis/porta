<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\DotSource;

final class DotSourceTest extends TestCase
{
    private const string DOT = "// A is first here, in a comment\n/* and A here */\n# and A here\ndigraph A {\n  label=\"B\";\n  \"hello \\\"world\\\"\" -> B;\n  A [shape=box];\n  AB -> B;\n}";

    public function testANodeIsFoundWhereItIsWrittenAsAnIdAndNowhereElse(): void
    {
        $this->assertSame(\strpos(self::DOT, needle: 'A [shape'), DotSource::declarationOf(self::DOT, 'A'), 'not in a comment, not the graph\'s name, not inside AB');
        $this->assertSame(\strpos(self::DOT, needle: 'B;'), DotSource::declarationOf(self::DOT, 'B'), 'not in label="B"');
        $this->assertSame(\strpos(self::DOT, needle: '"hello'), DotSource::declarationOf(self::DOT, 'hello "world"'), 'quoted, escapes and all');
        $this->assertSame(\strpos(self::DOT, needle: 'AB'), DotSource::declarationOf(self::DOT, 'AB'));
    }

    public function testTheEarlierOfTheBareAndTheQuotedSpellingWins(): void
    {
        $this->assertSame(0, DotSource::declarationOf("\"x\" -> y;\nx -> z;", 'x'));
        $this->assertSame(0, DotSource::declarationOf("x -> y;\n\"x\" -> z;", 'x'));
    }

    public function testANameNeverWrittenOrEmptyIsNowhere(): void
    {
        $this->assertNull(DotSource::declarationOf(self::DOT, 'Z'));
        $this->assertNull(DotSource::declarationOf(self::DOT, ''));
    }

    public function testTheNodeAtTheCaretIsTheOneWrittenOnOrNearestIt(): void
    {
        $dot = "digraph G {\n  A -> B;\n  B -> \"C d\" [label=\"A\"]; // A\n}";
        $names = ['A', 'B', 'C d', ''];

        $this->assertSame('A', DotSource::nodeAt($dot, $names, 1, 2), 'on it');
        $this->assertSame('B', DotSource::nodeAt($dot, $names, 1, 7));
        $this->assertSame('B', DotSource::nodeAt($dot, $names, 2, 0), 'before the first: the nearest');
        $this->assertSame('C d', DotSource::nodeAt($dot, $names, 2, 11), 'quoted');
        $this->assertSame('C d', DotSource::nodeAt($dot, $names, 2, 40), 'past the last, the label and the comment naming no node');
        $this->assertNull(DotSource::nodeAt($dot, $names, 0, 3), 'the graph\'s own name is no node');
        $this->assertNull(DotSource::nodeAt($dot, $names, 9, 0), 'no such line');
    }

    public function testACaretInsideALongIdIsOnThatIdNotOnTheNearestStart(): void
    {
        $this->assertSame('LongNodeName', DotSource::nodeAt('  LongNodeName -> X;', ['LongNodeName', 'X'], 0, 13));
    }
}
