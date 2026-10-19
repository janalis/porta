<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphFont;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\WardleyDiagram;
use Janalis\Viz\Mermaid\WardleyLayout;
use Janalis\Viz\Mermaid\WardleyLexer;
use Janalis\Viz\Mermaid\WardleyParser;
use Janalis\Viz\Mermaid\WardleySymbols;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $this->assertStringContainsString('Value chain', NativeFixtureExport::render($source, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $this->assertContains(6, NativeFixtureRows::render($source, width: 30)->origins);
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
        $this->assertStringContainsString('Critical point', NativeFixtureExport::render($source, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 1)->rows);
    }

    public function testComponentPrecedenceAndPartialStageBoundariesMatchPinnedPopulation(): void
    {
        $source = "wardley-beta\nevolution First@0.2 -> Last\ncomponent Same [0.3, 0.4]\nanchor Other [0.8, 0.8]\nanchor Same [0.9, 0.9]\ncomponent Last [0.2, 0.8]\nSame --> Last";
        $diagram = self::parse($source);
        $this->assertSame(['Other', 'Same', 'Last'], \array_keys($diagram->nodes));
        $this->assertSame('component', $diagram->nodes['Same']['kind']);
        $this->assertSame(0.3, $diagram->nodes['Same']['visibility']);
        $this->assertStringContainsString('First', NativeFixtureExport::render($source, Format::Svg));
        $this->assertNotEmpty(NativeFixtureRows::render('wardley-beta', width: 20)->rows);
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
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, Format::Svg));
    }
}
