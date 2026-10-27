<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        return NativeCorpus::of('block');
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesRenderToRowsAndSvg(string $path): void
    {
        NativeCorpus::assertBodyRendersToRowsAndSvg($path);
    }
}
