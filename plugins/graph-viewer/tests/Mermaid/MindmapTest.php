<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

final class MindmapTest extends TestCase
{
    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $result = NativeFixtureRows::render((string) \file_get_contents($file), 24);
        $this->assertNotEmpty($result->rows);
        $this->assertCount(\count($result->rows), $result->origins);
    }

    /** @return iterable<string, array{string}> */
    public static function upstreamFixtures(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/mindmap/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }

    public function testNativeSceneSelectionOriginsAndSvgExport(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $source = "mindmap\n  root((Root))\n    Child\n      Leaf";
        $scene = null;
        $renderer->render(
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
        $this->assertInstanceOf(MermaidScene::class, $scene);
        $this->assertSame([1, 2, 3], \array_values($scene->elements));
        $this->assertSame(1, $scene->origin($scene->onLine(1)));
        $this->assertSame('circle', $scene->shapes[0]);
        $this->assertSame($scene->graph->nodes[0]->w, $scene->graph->nodes[0]->h);
        $root = $scene->graph->nodes[0];
        $this->assertGreaterThan($root->x + ($root->w / 2), $scene->graph->nodes[1]->x - ($scene->graph->nodes[1]->w / 2), 'the first branch grows to the right');
        $this->assertNull($scene->graph->edges[0]->arrowTip);
        $svg = null;
        $renderer->export(
            $source,
            Format::Svg,
            static function (string $value) use (&$svg): void {
                $svg = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertIsString($svg);
        $this->assertStringContainsString('>Leaf</text>', $svg);
        $this->assertStringContainsString('<polygon', $svg);
    }

    public function testCancellationOfLargeLayoutSuppressesPublication(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $cancel = $renderer->render(
            "mindmap\nRoot\n".\str_repeat(" Child\n", times: 2000),
            static function (): never {
                self::fail('Cancelled render published a scene');
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
    }
}
