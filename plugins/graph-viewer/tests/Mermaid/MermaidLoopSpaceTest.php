<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\BlockLayout;
use Porta\GraphViewer\Mermaid\BlockLexer;
use Porta\GraphViewer\Mermaid\BlockParser;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;
use Porta\GraphViewer\Mermaid\StateLayout;
use Porta\GraphViewer\Mermaid\StateLexer;
use Porta\GraphViewer\Mermaid\StateParser;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MermaidLoopSpaceTest extends TestCase
{
    public static function diagrams(): iterable
    {
        yield 'state' => ['state', "stateDiagram-v2\nstate Outer {\nstate Group {\nA --> A: A long self transition caption\nB\n}\nGroup --> Group: Group loop caption\n}"];
        yield 'block' => ['block', "block\ncolumns 1\nblock:Outer\nblock:Group\ncolumns 2\nA B\nA -- \"A long self transition caption\" --> A\nend\nGroup -- \"Group loop caption\" --> Group\nend"];
        yield 'scaled flowchart' => [
            'flowchart',
            "flowchart LR\nsubgraph Outer\nsubgraph Group\nA -->|A long self transition caption| A\nB\nend\nGroup -->|Group loop caption| Group\nend\nlinkStyle 0 font-size:32px\nlinkStyle 1 font-size:24px",
        ];
        yield 'flowchart' => ['flowchart', "flowchart LR\nsubgraph Outer\nsubgraph Group\nA -->|A long self transition caption| A\nB\nend\nGroup -->|Group loop caption| Group\nend"];
    }

    #[DataProvider('diagrams')]
    public function testSelfRoutesAndCaptionsStayInsideTheirContainingFrame(string $family, string $source): void
    {
        $parser = match ($family) {
            'state' => new StateParser(),
            'block' => new BlockParser(),
            default => new FlowchartParser(),
        };
        $lexer = match ($family) {
            'state' => new StateLexer($source),
            'block' => new BlockLexer($source),
            default => new MermaidLexer($source),
        };
        foreach ($lexer->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = match (true) {
            $parser instanceof StateParser => new StateLayout()->layout($parser->finish()),
            $parser instanceof BlockParser => new BlockLayout()->layout($parser->finish()),
            default => new RankedGraphLayout()->layout($parser->finish()),
        };
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $frames = \array_column($scene->graph->clusters, null, 'name');
        $edges = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
        foreach (['Group', 'Outer'] as $index => $name) {
            $frame = $frames[$name];
            $edge = $edges[$index];
            $this->assertGreaterThanOrEqual($frame->x0, $edge->minX);
            $this->assertLessThanOrEqual($frame->x1, $edge->maxX);
            $this->assertGreaterThanOrEqual($frame->y0, $edge->minY);
            $this->assertLessThanOrEqual($frame->y1, $edge->maxY);
            $this->assertNotNull($edge->labelPos);
            $halfWidth = (AnsiUtils::visibleWidth($edge->label) * $edge->fontScale) / 2;
            $this->assertGreaterThanOrEqual($frame->x0, $edge->labelPos[0] - $halfWidth);
            $this->assertLessThanOrEqual($frame->x1, $edge->labelPos[0] + $halfWidth);
            $this->assertGreaterThanOrEqual($frame->y0, $edge->labelPos[1] - 1);
            $this->assertLessThanOrEqual($frame->y1, $edge->labelPos[1] + 1);
        }
        $a = \array_find($scene->graph->nodes, static fn ($node): bool => 'A' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $a);
        $this->assertSame(6.0, $a->h, 'Packing space must not enlarge the visible state or card');
    }
}
