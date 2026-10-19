<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SwimlaneLayout;
use Porta\GraphViewer\Mermaid\SwimlaneParser;

final class SwimlaneTest extends TestCase
{
    private static function parse(string $source): MermaidGraph
    {
        $parser = new SwimlaneParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new SwimlaneLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/swimlane/*.mmd') as $path) {
            yield \basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesRenderToRowsAndSvg(string $path): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    public static function directions(): iterable
    {
        foreach (['LR', 'RL', 'TB', 'BT', 'TD'] as $direction) {
            yield [$direction];
        }
    }

    #[DataProvider('directions')]
    public function testGlobalTimeAdvancesAcrossSeparateLanesAndHeadersRemainSelectable(string $direction): void
    {
        $source = 'swimlane-beta '.$direction."\nsubgraph Sales\nA\nB\nend\nsubgraph Support\nC\nD\nend\nA --> B --> C --> D";
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $lanes = \array_column($scene->graph->clusters, null, 'name');
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        $reverse = \in_array($direction, ['RL', 'BT'], strict: true) ? -1 : 1;
        foreach ([['A', 'B'], ['B', 'C'], ['C', 'D']] as [$from, $to]) {
            $distance = $horizontal ? $nodes[$to]->x - $nodes[$from]->x : $nodes[$to]->y - $nodes[$from]->y;
            $this->assertGreaterThan(0, $distance * $reverse);
        }
        if ($horizontal) {
            $this->assertSame($lanes['Sales']->x0, $lanes['Support']->x0);
            $this->assertSame($lanes['Sales']->x1, $lanes['Support']->x1);
            $this->assertLessThan($lanes['Support']->y0, $lanes['Sales']->y1);
        }
        if (!$horizontal) {
            $this->assertSame($lanes['Sales']->y0, $lanes['Support']->y0);
            $this->assertSame($lanes['Sales']->y1, $lanes['Support']->y1);
            $this->assertLessThan($lanes['Support']->x0, $lanes['Sales']->x1);
        }
        foreach (['A' => 'Sales', 'B' => 'Sales', 'C' => 'Support', 'D' => 'Support'] as $id => $lane) {
            $this->assertGreaterThanOrEqual($lanes[$lane]->x0, $nodes[$id]->x - ($nodes[$id]->w / 2));
            $this->assertLessThanOrEqual($lanes[$lane]->x1, $nodes[$id]->x + ($nodes[$id]->w / 2));
            $this->assertGreaterThanOrEqual($lanes[$lane]->y0, $nodes[$id]->y - ($nodes[$id]->h / 2));
            $this->assertLessThanOrEqual($lanes[$lane]->y1, $nodes[$id]->y + ($nodes[$id]->h / 2));
        }
        $this->assertSame(3, $scene->graph->edgeCount());
        $this->assertSame(1, $scene->origin($nodes['Sales']->id));
        $this->assertSame(5, $scene->origin($nodes['Support']->id));
        $this->assertSame($nodes['A']->id, $scene->onLine(2));
        $this->assertSame('text', $scene->shapes[$nodes['Sales']->id]);
        $this->assertLessThan($lanes['Sales']->y1 - $lanes['Sales']->y0, $nodes['Sales']->h);
    }

    #[DataProvider('directions')]
    public function testNestedTracksKeepFramesChildrenAndLoopsInsideTheirParent(string $direction): void
    {
        $source =
            'swimlane-beta '
            .$direction
            ."\nsubgraph Outer\nsubgraph Inner[\"Nested team\"]\nA -->|A longer self caption| A\nB\nend\nsubgraph Sibling\nC\nend\nInner -->|Nested loop| Inner\nend\nA --> C --> B";
        $scene = self::scene($source);
        $frames = \array_column($scene->graph->clusters, null, 'name');
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        foreach (['Inner', 'Sibling'] as $name) {
            $this->assertGreaterThanOrEqual($frames['Outer']->x0, $frames[$name]->x0);
            $this->assertLessThanOrEqual($frames['Outer']->x1, $frames[$name]->x1);
            $this->assertGreaterThanOrEqual($frames['Outer']->y0, $frames[$name]->y0);
            $this->assertLessThanOrEqual($frames['Outer']->y1, $frames[$name]->y1);
        }
        foreach (['A', 'B'] as $id) {
            $this->assertGreaterThanOrEqual($frames['Inner']->x0, $nodes[$id]->x - ($nodes[$id]->w / 2));
            $this->assertLessThanOrEqual($frames['Inner']->x1, $nodes[$id]->x + ($nodes[$id]->w / 2));
            $this->assertGreaterThanOrEqual($frames['Inner']->y0, $nodes[$id]->y - ($nodes[$id]->h / 2));
            $this->assertLessThanOrEqual($frames['Inner']->y1, $nodes[$id]->y + ($nodes[$id]->h / 2));
        }
        $loop = $scene->graph->edges[0];
        $this->assertLessThanOrEqual($frames['Inner']->x1, $loop->maxX);
        $this->assertLessThanOrEqual($frames['Inner']->y1, $loop->maxY);
        $this->assertLessThanOrEqual($frames['Outer']->x1, $scene->graph->edges[1]->maxX);
        $this->assertLessThanOrEqual($frames['Outer']->y1, $scene->graph->edges[1]->maxY);
        $this->assertSame(['Outer', 'Inner', 'Sibling'], \array_column($scene->graph->clusters, 'name'), 'Frames paint from parent to child');
        $this->assertSame(4, $scene->graph->edgeCount());
    }

    #[DataProvider('directions')]
    public function testGroupDependenciesOrderAllNestedSteps(string $direction): void
    {
        $scene = self::scene('swimlane-beta '.$direction."\nsubgraph Before\nA\nend\nsubgraph Team\nsubgraph Inner\nB --> C\nend\nend\nsubgraph After\nD\nend\nA --> Team\nTeam --> D");
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        $reverse = \in_array($direction, ['RL', 'BT'], strict: true) ? -1 : 1;
        foreach ([['A', 'B'], ['B', 'C'], ['C', 'D']] as [$from, $to]) {
            $distance = $horizontal ? $nodes[$to]->x - $nodes[$from]->x : $nodes[$to]->y - $nodes[$from]->y;
            $this->assertGreaterThan(0, $distance * $reverse);
        }
        $this->assertSame(3, $scene->graph->edgeCount());
    }

    public function testCircularAndTriangularShapesUseSharedOutlineMeasurements(): void
    {
        $scene = self::scene('swimlane-beta LR; subgraph Team; A((Circle)); B@{shape: tri, label: Tip}; end; A --> B');
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame($nodes['A']->w, $nodes['A']->h);
        $this->assertSame('circle', $scene->shapes[$nodes['A']->id]);
        $this->assertSame('tri', $scene->shapes[$nodes['B']->id]);
        $this->assertSame($nodes['B']->w, $nodes['B']->h, 'a pinned triangle is square');
    }

    #[DataProvider('directions')]
    public function testNestedGroupLoopsStayInsideANonRootParent(string $direction): void
    {
        $scene = self::scene('swimlane-beta '.$direction."\nsubgraph Outer\nsubgraph Middle\nsubgraph Inner\nA\nend\nInner -->|Nested loop| Inner\nend\nend");
        $frames = \array_column($scene->graph->clusters, null, 'name');
        $edge = $scene->graph->edges[0];
        $this->assertGreaterThanOrEqual($frames['Middle']->x0, $edge->minX);
        $this->assertLessThanOrEqual($frames['Middle']->x1, $edge->maxX);
        $this->assertGreaterThanOrEqual($frames['Middle']->y0, $edge->minY);
        $this->assertLessThanOrEqual($frames['Middle']->y1, $edge->maxY);
        $this->assertSame(1, $scene->graph->edgeCount());
    }

    public function testLooseNodesGetAnAnonymousLaneWithoutMutatingTheParsedGraph(): void
    {
        $graph = self::parse("swimlane-beta\n__swimlane_default__ --> A\nsubgraph Named\nB\nend\nA --> B");
        $work = new SwimlaneLayout()->layout($graph);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame([], $graph->nodes['A']['groups']);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(1, $scene->origin($nodes['__swimlane_default__']->id));
        $this->assertSame('rect', $scene->shapes[$nodes['__swimlane_default__']->id]);

        $this->assertCount(2, $scene->graph->clusters);
        $this->assertCount(4, $scene->elements);
        $this->assertNotContains('__swimlane_default___', \array_column($scene->graph->nodes, 'name'));
        $this->assertSame('TB', $graph->direction);
    }

    public function testStylesForwardGroupLinksEmptyLanesAndExportsKeepTheirSourceOrigins(): void
    {
        $source = <<<'MMD'
            swimlane-beta LR
            subgraph Empty
            end
            subgraph Team
            A
            end
            subgraph Other
            B
            end
            Team --> B
            A o--o B
            B -.-> A
            A ~~~ Empty
            style Team fill:none,stroke:red,stroke-dasharray:4 2,font-size:24px
            style A font-size:20px
            linkStyle 0 stroke:blue,font-size:20px
            MMD;
        $scene = self::scene($source);
        $this->assertSame(3, $scene->graph->edgeCount());
        $frames = \array_column($scene->graph->clusters, null, 'name');
        $this->assertSame('none', $frames['Team']->fillColor);
        $this->assertSame('#ff0000', $frames['Team']->color);
        $this->assertTrue($frames['Team']->dashed);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(1.5, $nodes['Team']->fontScale);
        $this->assertSame(1.25, $nodes['A']->fontScale);
        $this->assertSame(3, $scene->origin($nodes['Team']->id));
        $this->assertSame('#0000ff', $scene->graph->edges[0]->color);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertStringContainsString('font-size:2.4px', NativeFixtureExport::render("---\ntitle: Lanes\n---\n".$source, GraphExportFormat::Svg));
    }

    public function testAnAuthoredDefaultLaneCanBeReusedInsideAnotherLane(): void
    {
        $scene = self::scene("swimlane-beta\nsubgraph Parent\nsubgraph __swimlane_default__\nend\nend\nA");
        $this->assertCount(2, $scene->graph->clusters);
        $this->assertCount(3, $scene->elements);
        $this->assertNotEmpty(self::scene('swimlane-beta')->origins);
    }

    public function testInvalidHeadersAndUnclosedScopesAreLocated(): void
    {
        $parser = new SwimlaneParser();
        $this->expectException(MermaidSyntaxError::class);
        $parser->accept(new MermaidStatement('swimlane-beta XX', offset: 7, line: 2, column: 1));
    }
}
