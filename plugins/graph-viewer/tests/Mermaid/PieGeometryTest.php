<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;

final class PieGeometryTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $scheduler = new FakeScheduler();
        $scene = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            $source,
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        self::assertInstanceOf(MermaidScene::class, $scene);

        return $scene;
    }

    #[DataProvider('positions')]
    public function testLegendPositionsAndDonutGeometryRemainInsideTheScene(string $position): void
    {
        $source = "---\nconfig: {pie: {donutHole: 0.4, textPosition: 0.7, legendPosition: ".$position."}}\n---\npie showData title Demo\n\"First\":1\n\"Last\":3\n\"Zero\":0";
        $scene = self::scene($source);
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertCount(3, $nodes);
        foreach ($nodes as $node) {
            $shape = $scene->shapes[$node->id];
            foreach (MermaidOutline::points($node, $shape) as [$x, $y]) {
                $this->assertGreaterThanOrEqual(0, $x);
                $this->assertLessThanOrEqual($scene->graph->width, $x);
                $this->assertGreaterThanOrEqual(0, $y);
                $this->assertLessThanOrEqual($scene->graph->height, $y);
            }
            $this->assertTrue(MermaidOutline::contains($node, $shape, $node->x, $node->y));
        }
        $first = $nodes[0];
        $parts = \explode(':', $scene->shapes[$first->id]);
        $cx = $first->x + (float) $parts[4];
        $cy = $first->y + (float) $parts[5];
        $this->assertFalse(MermaidOutline::contains($first, $scene->shapes[$first->id], $cx, $cy));
        $label = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $node->id !== $first->id && $node->label === $first->label);
        match ($position) {
            'left' => $this->assertLessThan($cx - 24, $label->x),
            'right' => $this->assertGreaterThan($cx + 24, $label->x),
            'top' => $this->assertLessThan($cy - 24, $label->y),
            'bottom' => $this->assertGreaterThan($cy + 24, $label->y),
            default => $this->assertEqualsWithDelta($cx + 2, $label->x, delta: 0.01),
        };
        $this->assertCount(3, \array_unique(\array_values($scene->elements)));
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $lastSector = -1;
        $firstCaption = \PHP_INT_MAX;
        foreach ($drawing->getReturn()->marks as $index => $mark) {
            if ('polygon' === $mark->kind && \count($mark->points) > 10) {
                $lastSector = $index;
            }
            if ('text' === $mark->kind && '' !== $mark->text) {
                $firstCaption = \min($firstCaption, $index);
            }
        }
        $this->assertGreaterThanOrEqual(0, $lastSector);
        $percentage = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => '25%' === $node->label);
        $this->assertEqualsWithDelta(16.8, \hypot($percentage->x - $cx, $percentage->y - $cy), delta: 1.0e-10);
        $this->assertLessThan($firstCaption, $lastSector, 'all sectors paint before captions and centered legends');
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $this->assertStringContainsString('75%', NativeFixtureExport::render($source, Format::Svg));
    }

    public static function positions(): iterable
    {
        foreach (['top', 'bottom', 'left', 'right', 'center'] as $position) {
            yield $position => [$position];
        }
    }

    public function testSmallSlicesRemainSearchableWhileVisibleArcsFillTheWholeCircle(): void
    {
        $scene = self::scene("pie\n\"Large\":386\n\"Medium\":85\n\"Tiny\":1\n\"Zero\":0");
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertCount(4, $nodes);
        $this->assertSame('text', $scene->shapes[$nodes[2]->id]);
        $this->assertSame('text', $scene->shapes[$nodes[3]->id]);
        $first = \explode(':', $scene->shapes[$nodes[0]->id]);
        $last = \explode(':', $scene->shapes[$nodes[1]->id]);
        $this->assertSame(0.0, (float) $first[1]);
        $this->assertEqualsWithDelta(2 * \M_PI, (float) $last[2], delta: 1.0e-12);
        $this->assertEqualsWithDelta(386 / 85, ((float) $first[2] - (float) $first[1]) / ((float) $last[2] - (float) $last[1]), delta: 1.0e-12);
        $this->assertStringContainsString('Tiny (0.21%)', $nodes[2]->label);
    }

    public function testBitmapHitTestingFindsEachSectorAndRejectsTheDonutHole(): void
    {
        $scene = self::scene("---\nconfig: {pie: {donutHole: 0.4}}\n---\npie\n\"A\":1\n\"B\":1");
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $view = new ZoomPanModel((int) \ceil($scene->graph->width), (int) \ceil($scene->graph->height), [1.0]);
        $view->fit();
        $cols = (int) \ceil($scene->graph->width);
        $rows = (int) \ceil($scene->graph->height / 2);
        $found = [];
        $hole = 0;
        for ($row = 0; $row < $rows; ++$row) {
            for ($col = 0; $col < $cols; ++$col) {
                $id = MermaidHitTest::nodeAt($scene, $view, $cols, $rows, $row, $col);
                if (null === $id) {
                    ++$hole;
                    continue;
                }
                $found[$id] = true;
            }
        }
        $this->assertEqualsCanonicalizing(\array_keys($scene->elements), \array_keys($found));
        $this->assertGreaterThan(0, $hole);
        $parts = \explode(':', $scene->shapes[$nodes[0]->id]);
        $cx = $nodes[0]->x + (float) $parts[4];
        $cy = $nodes[0]->y + (float) $parts[5];
        $grid = new GraphCellGrid($cols, $rows, $view, null);
        $this->assertSame($nodes[0]->id, MermaidHitTest::nodeAt($scene, $view, $cols, $rows, $grid->rowOf($cy + 15), $grid->colOf($cx + 15)));
        $this->assertSame($nodes[1]->id, MermaidHitTest::nodeAt($scene, $view, $cols, $rows, $grid->rowOf($cy + 15), $grid->colOf($cx - 15)));
        $this->assertNull(MermaidHitTest::nodeAt($scene, $view, $cols, $rows, (int) ($cy / 2), (int) $cx));
    }

    public function testAWholePieHasNoRadialSeamAndAWholeDonutKeepsItsHole(): void
    {
        foreach ([0.0, 0.4] as $hole) {
            $scene = self::scene("---\nconfig: {pie: {donutHole: ".$hole."}}\n---\npie\n\"Only\":1");
            $id = \array_key_first($scene->elements);
            $node = $scene->graph->nodes[$id];
            $parts = \explode(':', $scene->shapes[$id]);
            $cx = $node->x + (float) $parts[4];
            $cy = $node->y + (float) $parts[5];
            $points = MermaidOutline::points($node, $scene->shapes[$id]);
            $area = 0.0;
            $previous = \array_last($points);
            foreach ($points as [$x, $y]) {
                $area += ($previous[0] * $y) - ($x * $previous[1]);
                $previous = [$x, $y];
            }
            $this->assertEqualsWithDelta(\M_PI * 24 * 24 * (1 - ($hole * $hole)), $area / 2, delta: 1.0);
            $this->assertNotContains([$cx, $cy], $points);
            $this->assertSame(0.0 === $hole, MermaidOutline::contains($node, $scene->shapes[$id], $cx, $cy));
            $this->assertTrue(MermaidOutline::contains($node, $scene->shapes[$id], $cx + 20, $cy));
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedGraphicsFixtures(string $path): void
    {
        $source = \file_get_contents($path);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
        $this->assertStringContainsString('<polygon', NativeFixtureExport::render($source, Format::Svg));
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/pie/*.mmd') as $path) {
            yield \basename($path) => [$path];
        }
    }
}
