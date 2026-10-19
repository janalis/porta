<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentflowTest extends TestCase
{
    public static function fixtures(): iterable
    {
        $found = \glob(__DIR__.'/../fixtures/mermaid/agentflow/*.mmd');
        foreach (false === $found ? [] : $found as $path) {
            yield \basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesRender(string $path): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: (string) \file_get_contents($path)) ?? '';
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }
}
