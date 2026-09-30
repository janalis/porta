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

    public function testNoAssignmentNamesANodeHoweverItsValueIsWritten(): void
    {
        $dot = "digraph G {\n  rankdir=LR;\n  a -> b [weight=2, color = red];\n  b [label=<<b>x</b> y>];\n  graph [label=c];\n  2 -> red;\n  x -> y -> c -> rankdir;\n}";

        $this->assertSame(\strpos($dot, needle: '2 -> red'), DotSource::declarationOf($dot, '2'), 'not the bare weight=2');
        $this->assertSame(\strpos($dot, needle: 'red;'), DotSource::declarationOf($dot, 'red'), 'not color = red, spaced');
        $this->assertSame(\strpos($dot, needle: 'x -> y'), DotSource::declarationOf($dot, 'x'), 'not inside an HTML label');
        $this->assertSame(\strpos($dot, needle: 'y -> c'), DotSource::declarationOf($dot, 'y'), 'not after the nested tag either');
        $this->assertSame(\strpos($dot, needle: 'c -> rankdir'), DotSource::declarationOf($dot, 'c'), 'not label=c');
        $this->assertSame(\strpos($dot, needle: 'rankdir;'), DotSource::declarationOf($dot, 'rankdir'), 'not the attribute\'s own name');
    }

    public function testABackslashInAQuotedIdIsTheNodesOwn(): void
    {
        // What Graphviz names "Porta\\Widget": both backslashes, as written.
        $dot = "digraph G {\n  \"Porta\\\\Widget\" [label=\"Widget\"];\n}";

        $this->assertSame(\strpos($dot, needle: '"Porta'), DotSource::declarationOf($dot, 'Porta\\\\Widget'));
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
