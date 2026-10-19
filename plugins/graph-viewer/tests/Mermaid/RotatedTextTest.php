<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSvg;

/** Pinned rotated captions: quadrant and xy side titles read upward, git commit ids slant. */
final class RotatedTextTest extends TestCase
{
    /** @return list<MermaidMark> */
    private static function marks(MermaidScene $scene): array
    {
        $work = MermaidDrawing::scene($scene);
        \iterator_to_array($work);

        return $work->getReturn()->marks;
    }

    private static function text(MermaidScene $scene, string $text): MermaidMark
    {
        $mark = \array_find(self::marks($scene), static fn (MermaidMark $mark): bool => 'text' === $mark->kind && $text === $mark->text);
        self::assertInstanceOf(MermaidMark::class, $mark, $text);

        return $mark;
    }

    public function testQuadrantYAxisCaptionsReadUpwardBesideTheChart(): void
    {
        $source = "quadrantChart\nx-axis Low --> High\ny-axis Bottom --> Top\nquadrant-1 One";
        $scene = NativeFixtureScene::render($source);
        $left = \min(\array_map(static fn (GraphCluster $cluster): float => $cluster->x0, $scene->graph->clusters));
        foreach (['Bottom', 'Top'] as $label) {
            $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $label === $node->label);
            $mark = self::text($scene, $label);
            $this->assertSame(-90.0, $mark->angle, $label);
            $this->assertEqualsWithDelta($left - 2.5 + 0.5, $mark->points[0][0], 1e-9, $label.' sits beside the chart\'s left edge');
            $this->assertEqualsWithDelta($node->y, $mark->points[0][1], 1e-9);
        }
        $this->assertSame(0.0, self::text($scene, 'Low')->angle);
        $right = NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    yAxisPosition: right\n---\n".$source);
        $edge = \max(\array_map(static fn (GraphCluster $cluster): float => $cluster->x1, $right->graph->clusters));
        $this->assertEqualsWithDelta($edge + 2.5 + 0.5, self::text($right, 'Top')->points[0][0], 1e-9, 'or beside its right edge');
        $empty = NativeFixtureScene::render("quadrantChart\nquadrant-1 One");
        $this->assertSame(0.0, self::text($empty, 'One')->angle);
    }

    public function testXySideTitlesReadUpwardAndBottomTitlesStayLevel(): void
    {
        $scene = NativeFixtureScene::render("xychart-beta\nx-axis \"Months\" [a, b]\ny-axis \"Revenue\" 0 --> 10\nbar [3, 5]");
        $this->assertSame(-90.0, self::text($scene, 'Revenue')->angle);
        $this->assertSame(0.0, self::text($scene, 'Months')->angle);
        $sideways = NativeFixtureScene::render("xychart-beta horizontal\nx-axis \"Months\" [a, b]\ny-axis \"Revenue\" 0 --> 10\nbar [3, 5]");
        $this->assertSame(-90.0, self::text($sideways, 'Months')->angle, 'a horizontal chart turns its x title');
        $this->assertSame(0.0, self::text($sideways, 'Revenue')->angle);
    }

    public function testBackendsTurnTextAboutItsAnchor(): void
    {
        $mark = new MermaidMark('text', [[10.0, 10.0]], 'WWWWWW', stroke: '#000000', angle: -90.0);
        $svg = MermaidSvg::render(new MermaidDrawing(20.0, 20.0, [$mark]));
        \iterator_to_array($svg);
        $this->assertStringContainsString('transform="rotate(-90 10 10)"', $svg->getReturn());
        $level = MermaidSvg::render(new MermaidDrawing(20.0, 20.0, [new MermaidMark('text', [[10.0, 10.0]], 'W')]));
        \iterator_to_array($level);
        $this->assertStringNotContainsString('rotate(', $level->getReturn());
        $png = new MermaidPng()->render(new MermaidDrawing(20.0, 20.0, [$mark]));
        \iterator_to_array($png);
        $image = \imagecreatefromstring($png->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
        [$tall, $wide] = [0, 0];
        for ($x = 0; $x < 200; ++$x) {
            for ($y = 0; $y < 200; ++$y) {
                if ((\imagecolorat($image, $x, $y) & 0xFF_FFFF) >= 0x80_8080) {
                    continue;
                }
                $tall = \max($tall, \abs($y - 100));
                $wide = \max($wide, \abs($x - 100));
            }
        }
        $this->assertGreaterThan($wide, $tall, 'the six letters run up the page, centered on the anchor');
    }
}
