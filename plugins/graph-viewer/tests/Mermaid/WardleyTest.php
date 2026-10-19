<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\WardleyDiagram;
use Porta\GraphViewer\Mermaid\WardleyLayout;
use Porta\GraphViewer\Mermaid\WardleyLexer;
use Porta\GraphViewer\Mermaid\WardleyParser;
use Porta\GraphViewer\Mermaid\WardleySymbols;

final class WardleyTest extends TestCase
{
    private static function parse(string $source): WardleyDiagram
    {
        $parser = new WardleyParser();
        foreach (new WardleyLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testCoordinatesNamesStrategiesAndMetadataPreserveSourceSemantics(): void
    {
        $source = <<<'MMD'
            %% overview
            wardley-beta
            title Value chain
            accTitle: Chain
            accDescr {Visibility and evolution}
            anchor "User %% literal" [0.95, 0.5]
            component Service [75.0, 25.0] label [-30, 20] (build) inertia
            component "Cloud" [0.2, 0.9] (market)
            "User %% literal" -> Service
            Service +'use'> Cloud
            evolve Service 0.6
            evolve Service 0.7
            MMD;
        $diagram = self::parse($source);
        $this->assertSame([0.75, 0.25], [$diagram->nodes['Service']['visibility'], $diagram->nodes['Service']['evolution']]);
        $this->assertSame([-3.0, 2.0, 'build', true], [
            $diagram->nodes['Service']['dx'],
            $diagram->nodes['Service']['dy'],
            $diagram->nodes['Service']['strategy'],
            $diagram->nodes['Service']['inertia'],
        ]);
        $this->assertSame('User %% literal', $diagram->nodes['User %% literal']['label']);
        $this->assertSame(['forward', 'use'], [$diagram->links[1]['flow'], $diagram->links[1]['label']]);
        $this->assertSame(0.7, $diagram->trends['Service']['target']);
        $this->assertSame(6, $diagram->nodes['Service']['origin']);
        $this->assertCount(3, $diagram->metadata);
        $work = new WardleyLayout()->layout($diagram);
        $this->assertGreaterThan(50, \count(\iterator_to_array($work)));
        $scene = $work->getReturn();
        $service = \array_find($scene->graph->nodes, static fn ($node): bool => 'Service' === $node->name);
        $cloud = \array_find($scene->graph->nodes, static fn ($node): bool => 'Cloud' === $node->name);
        $user = \array_find($scene->graph->nodes, static fn ($node): bool => 'User %% literal' === $node->name);
        $this->assertLessThan($cloud->x, $service->x);
        $this->assertLessThan($cloud->y, $service->y);
        $this->assertSame(GraphFont::Bold, $user->font);
        $marketMarks = WardleySymbols::marks($cloud, $scene->shapes[$cloud->id]);
        $this->assertCount(3, \array_filter($marketMarks, static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind));
        $this->assertCount(1, \array_filter($marketMarks, static fn (MermaidMark $mark): bool => 'polyline' === $mark->kind));
        $this->assertSame(6, $scene->elements[$service->id]);
        $caption = \array_find($scene->graph->nodes, static fn ($node): bool => '@label:Service' === $node->name);
        $this->assertSame([$service->x - 3, $service->y + 2], [$caption->x - ($caption->w / 2), $caption->y]);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $inertiaX = $service->x + ($service->w / 2) + 0.8;
        $this->assertContains([[$inertiaX, $service->y - 1.5], [$inertiaX, $service->y + 1.5]], \array_column($drawing->getReturn()->marks, 'points'));
        $this->assertSame(2, $scene->graph->edgeCount());
        $this->assertStringContainsString('inertia', $scene->shapes[$service->id]);
        $this->assertStringContainsString('Value chain', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertContains(6, NativeFixtureRows::render($source, width: 30)->origins);
    }

    public function testPipelineComponentsInheritFinalParentCoordinatesAndResolveByLabel(): void
    {
        $diagram = self::parse(<<<'MMD'
            wardley-beta
            pipeline Parent {
                component Early [0.2] label [-20, 10]
                component Late [0.8]
            }
            component Parent [0.6, 0.4]
            component User [0.9, 0.5]
            User -> Late
            Early -> Parent
            Missing -> Unknown
            MMD);
        $this->assertSame(['Parent_Early', 'Parent_Late'], $diagram->pipelines['Parent']);
        $this->assertSame(0.6, $diagram->nodes['Parent_Early']['visibility']);
        $this->assertSame(-2.0, $diagram->nodes['Parent_Early']['dx']);
        $work = new WardleyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame(1, $scene->graph->edgeCount(), 'unknown and child-to-parent links are not painted upstream');
        $parent = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent' === $node->name);
        $early = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent_Early' === $node->name);
        $late = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent_Late' === $node->name);
        $this->assertSame($early->y, $late->y);
        $this->assertSame(($early->x + $late->x) / 2, $parent->x);
        $this->assertLessThan($early->y, $parent->y);
        $this->assertSame('rect', $scene->shapes[$parent->id]);
        $this->assertCount(2, $scene->graph->clusters);
        $anchorPipeline = "wardley-beta\nanchor Anchor [0.5,0.5]\npipeline Anchor {\ncomponent Only [0.4]\n}";
        $this->assertStringContainsString('Anchor', NativeFixtureExport::render($anchorPipeline, GraphExportFormat::Svg));
    }

    public function testFlowsStylesAndStageBoundariesHaveDistinctNativeGeometry(): void
    {
        $source = <<<'MMD'
            wardley-beta
            evolution "Idea -> concept"@0.2 / Concept -> Build@0.4 -> Product@0.75 -> Utility@1.0
            component A [0.8, 0.1] (outsource)
            component B [0.6, 0.4] (buy)
            component C [0.4, 0.7] (market)
            component D [0.2, 0.9] (inertia)
            A +<> B
            B +< C; reverse
            C -.-> D +>
            D +'return'< A
            MMD;
        $diagram = self::parse($source);
        $this->assertSame('Idea -> concept / Concept', $diagram->stages[0]['label']);
        $this->assertSame([0.2, 0.4, 0.75, 1.0], \array_column($diagram->stages, 'boundary'));
        $this->assertSame('; reverse', $diagram->links[1]['label']);
        $this->assertSame(['both', 'backward', 'forward', 'backward'], \array_column($diagram->links, 'flow'));
        $work = new WardleyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame(4, $scene->graph->edgeCount());
        $frame = $scene->graph->clusters[0];
        $divider = \array_find($scene->graph->edges, static fn (GraphEdge $edge): bool => '#000000cc' === $edge->color);
        $this->assertSame($frame->x0 + (0.2 * ($frame->x1 - $frame->x0)), $divider->points[0][0]);
        $reverse = $scene->graph->relationships[1];
        $tip = $reverse->arrowTip;
        $this->assertNotNull($tip);
        $tail = $scene->graph->nodes[$reverse->tail];
        $head = $scene->graph->nodes[$reverse->head];
        $this->assertLessThan(\hypot($tip[0] - $head->x, $tip[1] - $head->y), \hypot($tip[0] - $tail->x, $tip[1] - $tail->y));
        $this->assertCount(1, \array_filter($scene->graph->relationships, static fn (GraphEdge $edge): bool => $edge->dashed));
        $this->assertCount(5, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->arrowTip));
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $this->assertGreaterThan(30, \count($drawing->getReturn()->marks));
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('#666666', $svg);
        $this->assertStringContainsString('#cccccc', $svg);
    }

    public function testAnnotationsNotesArrowsAndCustomCanvasSurviveExports(): void
    {
        $source = <<<'MMD'
            wardley-beta
            size [1500, 800]
            component Point [0.5, 0.5] label [-1200, -800]
            note "Critical point" [0.4, 0.5]
            annotations [1, 0]
            annotations [0, 1]
            annotation 2,[0.4, 0.6] "Second"
            annotation 1,[0.6, 0.4] "First"
            accelerator Faster [0.3, 0.3]
            deaccelerator Slower [0.7, 0.7]
            evolve Absent 0.9
            MMD;
        $diagram = self::parse($source);
        $this->assertSame([150.0, 80.0], [$diagram->width, $diagram->height]);
        $this->assertCount(7, $diagram->annotations);
        $work = new WardleyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertCount(6, $scene->elements);
        foreach (['@accelerator:5', '@deaccelerator:6'] as $name) {
            $arrow = \array_find($scene->graph->nodes, static fn ($node): bool => $name === $node->name);
            $direction = \str_starts_with($name, '@deaccelerator:') ? -1 : 1;
            $this->assertContains([$arrow->x + (($direction * $arrow->w) / 2), $arrow->y], MermaidOutline::points($arrow, $scene->shapes[$arrow->id]));
            $this->assertTrue(MermaidOutline::contains($arrow, $scene->shapes[$arrow->id], $arrow->x, $arrow->y));
            $this->assertFalse(MermaidOutline::contains($arrow, $scene->shapes[$arrow->id], $arrow->x, $arrow->y + $arrow->h));
        }
        $this->assertSame(0, $scene->graph->edgeCount());
        $box = \array_find($scene->graph->nodes, static fn ($node): bool => '@annotations-box' === $node->name);
        $this->assertSame("1. First\n2. Second", $box->label);
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $this->assertStringContainsString('Critical point', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 1)->rows);
    }

    public function testComponentPrecedenceAndPartialStageBoundariesMatchPinnedPopulation(): void
    {
        $source = "wardley-beta\nevolution First@0.2 -> Last\ncomponent Same [0.3, 0.4]\nanchor Other [0.8, 0.8]\nanchor Same [0.9, 0.9]\ncomponent Last [0.2, 0.8]\nSame --> Last";
        $diagram = self::parse($source);
        $this->assertSame(['Other', 'Same', 'Last'], \array_keys($diagram->nodes));
        $this->assertSame('component', $diagram->nodes['Same']['kind']);
        $this->assertSame(0.3, $diagram->nodes['Same']['visibility']);
        $this->assertStringContainsString('First', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertNotEmpty(NativeFixtureRows::render('wardley-beta', width: 20)->rows);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'header' => ['wardley'];
        yield 'coordinates' => ["wardley-beta\ncomponent A [0.4]"];
        yield 'numeric range' => ["wardley-beta\ncomponent A [1000.0, 0.5]"];
        yield 'canvas' => ["wardley-beta\nsize [10, 200]"];
        yield 'large canvas' => ["wardley-beta\nsize [100000, 200]"];
        yield 'decorator' => ["wardley-beta\ncomponent A [0.4,0.5] (invalid)"];
        yield 'anchor decorator' => ["wardley-beta\nanchor A [0.4,0.5] (buy)"];
        yield 'offset limit' => ["wardley-beta\ncomponent A [0.4,0.5] label [1000000,0]"];
        yield 'empty pipeline' => ["wardley-beta\npipeline A {\n}"];
        yield 'unclosed pipeline' => ["wardley-beta\npipeline A {\ncomponent B [0.5]"];
        yield 'unknown pipeline parent' => ["wardley-beta\npipeline A {\ncomponent B [0.5]\n}"];
        yield 'pipeline decorator' => ["wardley-beta\npipeline A {\ncomponent B [0.5] (buy)\n}"];
        yield 'pipeline content' => ["wardley-beta\npipeline A {\nA -> B\n}"];
        yield 'single stage' => ["wardley-beta\nevolution Only"];
        yield 'stage punctuation' => ["wardley-beta\nevolution First -> @bad"];
        yield 'bad annotation' => ["wardley-beta\nannotation invalid"];
        yield 'named box' => ["wardley-beta\nannotations Wrong [0,1]"];
        yield 'unquoted note' => ["wardley-beta\nnote Wrong [0.4,0.5]"];
        yield 'nameless accelerator' => ["wardley-beta\naccelerator [0.4,0.5]"];
        yield 'missing link operator' => ["wardley-beta\nFirst Second"];
        yield 'invalid link' => ["wardley-beta\nA --- B"];
        yield 'unclosed quote' => ["wardley-beta\ncomponent \"Missing [0.4,0.5]"];
    }

    #[DataProvider('invalid')]
    public function testInvalidDeclarationsFailWithLocatedErrors(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }

    public function testEmptyInputInvalidCoordinatesAndBoundedDeclarations(): void
    {
        foreach (['', 'wrong'] as $source) {
            try {
                self::parse($source);
                $this->fail('Invalid header accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertSame(0, $error->offset);
            }
        }
        try {
            WardleyParser::coordinate('nonnumeric', new MermaidStatement('', offset: 4, line: 2, column: 1));
            $this->fail('Nonnumeric coordinate accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([4, 2, 1], [$error->offset, $error->sourceLine, $error->column]);
        }
        $parser = new WardleyParser();
        $parser->accept(new MermaidStatement('wardley-beta', offset: 0, line: 0, column: 0));
        for ($i = 0; $i < 4096; ++$i) {
            $parser->accept(new MermaidStatement('component A [0.5,0.5]', offset: 13, line: 1, column: 0));
        }
        $this->assertCount(1, $parser->finish()->nodes);
        $this->expectException(MermaidSyntaxError::class);
        $parser->accept(new MermaidStatement('component B [0.5,0.5]', offset: 13, line: 1, column: 0));
    }

    /** @return iterable<string, array{string}> */
    public static function pinned(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/wardley/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [(string) \file_get_contents($file)];
        }
    }

    #[DataProvider('pinned')]
    public function testPinnedExamplesRenderWithoutCli(string $source): void
    {
        $this->assertNotEmpty(self::parse($source)->nodes);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }
}
