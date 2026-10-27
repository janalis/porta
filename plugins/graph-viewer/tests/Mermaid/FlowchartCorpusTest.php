<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowchartCorpusTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        return NativeCorpus::of('flowchart');
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesRenderToRowsAndSvg(string $path): void
    {
        NativeCorpus::assertBodyRendersToRowsAndSvg($path);
    }

    public function testNativeRowsPreserveBothArrowDirections(): void
    {
        foreach (['LR' => ['▸', '◂'], 'RL' => ['▸', '◂'], 'TB' => ['▾', '▴'], 'BT' => ['▾', '▴']] as $direction => $glyphs) {
            $rows = NativeFixtureRows::render('graph '.$direction."\nA <--> B", width: 80);
            $text = \implode("\n", $rows->rows);
            foreach ($glyphs as $glyph) {
                $this->assertStringContainsString($glyph, $text, $direction);
            }
        }
    }
}
