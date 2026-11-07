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
        // Every 20th example: janalis/viz renders all 261, and what Porta adds,
        // the rows, is the same code for each. The files stay for
        // DisplayWidthParityTest, which measures every line of them.
        $index = 0;
        foreach (NativeCorpus::of('flowchart') as $name => $case) {
            if (0 !== ($index++ % 20)) {
                continue;
            }
            yield $name => $case;
        }
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
