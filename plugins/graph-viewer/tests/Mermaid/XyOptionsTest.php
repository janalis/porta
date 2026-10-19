<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** Pinned `xyChart` options: orientation, visibility switches and bar value labels. */
final class XyOptionsTest extends TestCase
{
    private const string BODY = "title Sales\nx-axis \"Month\" [a, b]\ny-axis \"Units\" 0 --> 10\nbar \"Shop\" [3, 7]\n";

    public function testTheHeaderOrientationWinsOverTheConfiguredOne(): void
    {
        $vertical = self::scene([], 'xychart-beta');
        $configured = self::scene(['chartOrientation' => 'horizontal'], 'xychart-beta');
        $header = self::scene(['chartOrientation' => 'horizontal'], 'xychart-beta vertical');
        $bar = static fn (MermaidScene $scene): GraphNode => self::named($scene, 'xy-0-1');
        $this->assertGreaterThan($bar($vertical)->w, $bar($vertical)->h);
        $this->assertGreaterThan($bar($configured)->h, $bar($configured)->w);
        $this->assertEquals($bar($vertical), $bar($header));
    }

    public function testSwitchesHideTheirParts(): void
    {
        $plain = self::names(self::scene([], 'xychart-beta'));
        $this->assertContains('title', $plain);
        $this->assertContains('legend-0', $plain);
        $this->assertContains('category-0', $plain);
        $this->assertContains('value-0', $plain);
        $this->assertContains('x-axis', $plain);
        $this->assertContains('y-axis', $plain);
        $this->assertNotContains('title', self::names(self::scene(['showTitle' => false], 'xychart-beta')));
        $this->assertNotContains('legend-0', self::names(self::scene(['showLegend' => false], 'xychart-beta')));
        $hidden = self::scene(['xAxis' => ['showLabel' => false, 'showTitle' => false], 'yAxis' => ['showAxisLine' => false]], 'xychart-beta');
        $this->assertNotContains('category-0', self::names($hidden));
        $this->assertNotContains('x-axis', self::names($hidden));
        $this->assertContains('value-0', self::names($hidden));
        $this->assertContains('y-axis', self::names($hidden));
        $this->assertCount(2, self::scene([], 'xychart-beta')->graph->edges);
        $this->assertCount(1, $hidden->graph->edges);
        $this->assertEquals(\array_slice(self::scene([], 'xychart-beta')->graph->edges, offset: 1), $hidden->graph->edges);
    }

    public function testDataLabelsSitInsideOrPastTheBarEnd(): void
    {
        $this->assertNotContains('data-xy-0-1', self::names(self::scene([], 'xychart-beta')));
        $inside = self::scene(['showDataLabel' => true], 'xychart-beta');
        $outside = self::scene(['showDataLabel' => true, 'showDataLabelOutsideBar' => true], 'xychart-beta');
        $bar = self::named($inside, 'xy-0-1');
        $label = self::named($inside, 'data-xy-0-1');
        $this->assertSame('7', $label->label);
        $this->assertSame($bar->x, $label->x);
        $this->assertGreaterThan($bar->y - ($bar->h / 2), $label->y);
        $this->assertLessThan($bar->y - ($bar->h / 2), self::named($outside, 'data-xy-0-1')->y);
        $this->assertSame($inside->palette?->color('xyChart.dataLabelColor'), $label->textColor);
        $wide = self::scene(['showDataLabel' => true, 'chartOrientation' => 'horizontal'], 'xychart-beta');
        $across = self::named($wide, 'xy-0-1');
        $this->assertLessThan($across->x + ($across->w / 2), self::named($wide, 'data-xy-0-1')->x);
        $this->assertSame($across->y, self::named($wide, 'data-xy-0-1')->y);
    }

    /** @param array<string, mixed> $options */
    private static function scene(array $options, string $header): MermaidScene
    {
        $config = [] === $options ? '' : "---\nconfig:\n  xyChart: ".\json_encode($options, \JSON_THROW_ON_ERROR)."\n---\n";

        return NativeFixtureScene::render($config.$header."\n".self::BODY);
    }

    /** @return list<string> */
    private static function names(MermaidScene $scene): array
    {
        return \array_values(\array_map(static fn (GraphNode $node): string => $node->name, $scene->graph->nodes));
    }

    private static function named(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node);

        return $node;
    }
}
