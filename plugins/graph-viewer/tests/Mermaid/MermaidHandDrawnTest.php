<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

/** The pinned `look: handDrawn`: roughjs strokes and hachure in pictures, classic cells. */
final class MermaidHandDrawnTest extends TestCase
{
    private const string FLOWCHART = "flowchart TD\nA[Start]-->B{Choice}\nsubgraph S[Group]\nC(Round)\nend\nB-.->C";

    public function testCellsStayClassicAndExportsCarryTheSketch(): void
    {
        $this->assertEquals(NativeFixtureRows::render(self::FLOWCHART, width: 60)->rows, NativeFixtureRows::render(self::handDrawn(self::FLOWCHART, seed: 5), width: 60)->rows);
        $svg = NativeFixtureExport::render(self::handDrawn(self::FLOWCHART, seed: 5), Format::Svg);
        $this->assertGreaterThan(50, \substr_count($svg, needle: '<polyline'));
        $this->assertStringContainsString('stroke-width="0.13"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render(self::handDrawn(self::FLOWCHART, seed: 5), Format::Png));
    }

    private static function handDrawn(string $source, int|float|string $seed): string
    {
        return "---\nconfig:\n  look: handDrawn\n  handDrawnSeed: ".$seed."\n---\n".$source;
    }
}
