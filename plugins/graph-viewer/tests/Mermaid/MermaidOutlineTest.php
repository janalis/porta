<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Clock\MockClock;

final class MermaidOutlineTest extends TestCase
{
    #[DataProvider('shapes')]
    public function testShapesSurviveParsingLayoutAndDrawing(string $declaration, string $shape, int $marks): void
    {
        $scheduler = new FakeScheduler();
        $scene = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            'graph RL; subgraph G; '.$declaration.'; end; A-->B; A;',
            static function (MermaidScene $result) use (&$scene): void {
                $scene = $result;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertInstanceOf(MermaidScene::class, $scene);
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $node);
        $this->assertSame($shape, $scene->shapes[$node->id]);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $this->assertGreaterThanOrEqual($marks, \count($drawing->getReturn()->marks));
        $this->assertCount($marks, MermaidOutline::marks($node, $shape));
        $this->assertTrue(MermaidOutline::contains($node, $shape, $node->x, $node->y));
        $this->assertFalse(MermaidOutline::contains($node, $shape, $node->x + $node->w, $node->y + $node->h));
        if (\in_array($shape, ['circle', 'doublecircle', 'circ', 'dbl-circ', 'sm-circ'], strict: true)) {
            $this->assertSame($node->w, $node->h);
        }
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function shapes(): iterable
    {
        foreach ([
            'A[Label]' => ['rect', 1],
            'A(Label)' => ['rounded', 1],
            'A([Label])' => ['stadium', 1],
            'A((Label))' => ['circle', 1],
            'A(((Label)))' => ['doublecircle', 2],
            'A{Label}' => ['diamond', 1],
            'A{{Label}}' => ['hexagon', 1],
            'A[[Label]]' => ['subroutine', 3],
            'A[(Label)]' => ['cylinder', 2],
            'A>Label]' => ['asymmetric', 1],
            'A[/Label/]' => ['lean-r', 1],
            'A[\\Label\\]' => ['lean-l', 1],
            'A[/Label\\]' => ['trap-b', 1],
            'A[\\Label/]' => ['trap-t', 1],
        ] as $declaration => [$shape, $marks]) {
            yield $shape => [$declaration, $shape, $marks];
        }
        foreach ([
            'diam',
            'decision',
            'circ',
            'sm-circ',
            'dbl-circ',
            'cyl',
            'database',
            'db',
            'subproc',
            'subprocess',
            'round',
            'lined-document',
            'event',
            'hex',
            'prepare',
            'odd',
            'ellipse',
        ] as $alias) {
            $marks = match ($alias) {
                'dbl-circ', 'cyl', 'database', 'db', 'lined-document' => 2,
                'subproc', 'subprocess' => 3,
                default => 1,
            };
            yield $alias => ['A@{ shape: '.$alias.', label: Label }', $alias, $marks];
        }
        yield 'attributes default' => ['A@{ label: Label }', 'rect', 1];
    }

    public function testHitTestingRejectsEmptyCornersAndIncludesTheOutline(): void
    {
        $node = new GraphNode(0, 'n', '', 10, 10, 10, 10, null, null, dashed: false);
        $this->assertFalse(MermaidOutline::contains($node, 'diamond', x: 5.5, y: 5.5));
        $this->assertTrue(MermaidOutline::contains($node, 'diamond', x: 5.0, y: 10.0));
        $this->assertTrue(MermaidOutline::contains($node, 'rect', x: 5.0, y: 5.0));
        $this->assertFalse(MermaidOutline::contains($node, 'circle', x: 5.5, y: 5.5));
        $this->assertFalse(MermaidOutline::contains($node, 'rounded', x: 5.1, y: 5.1));
        $this->assertSame([12.5, 12.5], MermaidOutline::boundary($node, 'diamond', towardX: 20.0, towardY: 20.0));
        $this->assertSame([15.0, 15.0], MermaidOutline::boundary($node, 'rect', towardX: 20.0, towardY: 20.0));
        $this->assertSame([10.0, 5.0], MermaidOutline::boundary($node, 'rect', towardX: 10.0, towardY: 10.0));
        $this->assertEqualsWithDelta([13.535_533_906, 13.535_533_906], MermaidOutline::boundary($node, 'circle', towardX: 20.0, towardY: 20.0), delta: 0.000_001);
        $point = new GraphNode(0, 'point', '', 10, 10, 0, 0, null, null, dashed: false);
        $this->assertSame([10.0, 10.0], MermaidOutline::boundary($point, 'rect', towardX: 20.0, towardY: 20.0));
    }

    public function testExpensiveWorkYieldsAtTheSliceDeadline(): void
    {
        $scheduler = new FakeScheduler();
        $clock = new MockClock('2026-01-01');
        $count = 0;
        $work = static function () use (&$count, $clock): \Generator {
            for ($index = 0; $index < 100; ++$index) {
                ++$count;
                $clock->modify('+1 millisecond');
                yield null;
            }

            return 'done';
        };
        $result = null;
        new MermaidTask($scheduler, $clock)->run(
            $work(),
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertSame(4, $count, 'the 4ms boundary yields even before the 64-step batch is exhausted');
        $this->assertNull($result);
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame('done', $result);
    }
}
