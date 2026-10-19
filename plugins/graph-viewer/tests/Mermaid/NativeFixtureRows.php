<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Assert;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\Tests\Support\FakeScheduler;

/** Acceptance corpora share the scheduler plumbing; each family asserts its own rendered behavior. */
final class NativeFixtureRows
{
    public static function render(string $source, int $width): FenceResult
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->rows(
            $source,
            $width,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $error): never {
                Assert::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        Assert::assertInstanceOf(FenceResult::class, $result);

        return $result;
    }
}
