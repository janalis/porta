<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\FlowchartLayout;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidOrigins;

final class MermaidOriginsTest extends TestCase
{
    public function testBlankAndOddHeightsKeepSourceRowsAndTheInclusiveLimit(): void
    {
        $this->assertSame([0], MermaidOrigins::rows(0.0));
        $this->assertSame([7, 7], MermaidOrigins::rows(3.0, origin: 7));
        $this->assertCount(500_000, MermaidOrigins::rows(1_000_000.0));
    }

    public static function invalid(): iterable
    {
        yield [\INF];
        yield [\NAN];
        yield [-1.0];
        yield [1_000_001.0];
    }

    #[DataProvider('invalid')]
    public function testInvalidHeightsFailBeforeAllocatingARowMap(float $height): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('layout height limit');
        MermaidOrigins::rows($height);
    }

    public function testLargeStyledLabelsFailBeforeAllocatingTheirFullHeight(): void
    {
        $source = 'graph TB; A["'.\str_repeat("Line\n", times: 10_000).'"]; style A font-size:1000px';
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new FlowchartLayout()->layout($parser->finish());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('layout height limit');
        \iterator_to_array($work);
    }
}
