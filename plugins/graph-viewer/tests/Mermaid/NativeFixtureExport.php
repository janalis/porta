<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Assert;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

/** Export cases share callback scheduling; each family asserts its own graphic and escaping contract. */
final class NativeFixtureExport
{
    public static function render(string $source, GraphExportFormat $format): string
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            $source,
            $format,
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (string $error): never {
                Assert::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        Assert::assertIsString($result);

        return $result;
    }
}
