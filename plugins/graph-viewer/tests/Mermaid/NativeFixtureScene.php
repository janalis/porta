<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Assert;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

/** Scene probes use the same scheduled native pipeline as previews and exports. */
final class NativeFixtureScene
{
    public static function render(string $source): MermaidScene
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            $source,
            static function (MermaidScene $scene) use (&$result): void {
                $result = $scene;
            },
            static function (string $error): never {
                Assert::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        Assert::assertInstanceOf(MermaidScene::class, $result);

        return $result;
    }
}
