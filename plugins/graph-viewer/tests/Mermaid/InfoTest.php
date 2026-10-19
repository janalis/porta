<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\InfoLexer;
use Janalis\Viz\Mermaid\InfoParser;
use Janalis\Viz\Mermaid\MermaidGraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

final class InfoTest extends TestCase
{
    private static function parse(string $source): MermaidGraph
    {
        $parser = new InfoParser();
        foreach (new InfoLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    #[DataProvider('sources')]
    public function testAcceptedGrammarHasOneSourceLinkedDiagnostic(string $source, int $line): void
    {
        $graph = self::parse($source);
        $this->assertCount(1, $graph->nodes);
        $this->assertSame("Janalis Viz native Mermaid\nCompatibility target: v12.1.0", $graph->nodes['info']['label']);
        $this->assertSame($line, $graph->nodes['info']['origin']);
        $this->assertSame('text', $graph->shapes['info']);
        $this->assertSame([], $graph->edges);
        $rows = NativeFixtureRows::render($source, width: 80);
        $this->assertNotEmpty($rows->rows);
    }

    /** @return iterable<array{string, int}> */
    public static function sources(): iterable
    {
        yield ['info', 0];
        yield ['info showInfo', 0];
        yield ["%% intro\n\n  info\nshowInfo\n", 2];
        yield ["info\r\nshowInfo\r\n", 0];
        yield ["info\ntitle Caption %% comment\naccTitle: Accessible\naccDescr: Detail", 0];
        yield ["info showInfo\naccDescr {\n Multiline %% literal\n}\ntitle\naccTitle: 100% native\naccDescr: 1% of text %% hidden", 0];
        yield ["info %% comment\nshowInfo %% comment\ntitle 100% native %% hidden\naccTitle: %% empty\naccDescr: %% empty", 0];
    }

    public function testPinnedUpstreamFixtures(): void
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/info/*.mmd') as $path) {
            $source = \file_get_contents($path);
            $this->assertIsString($source);
            $this->assertCount(1, self::parse($source)->nodes);
            $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        }
    }

    public function testCancellationStopsLongMetadataBeforePublication(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "info\n".\str_repeat("title ignored\n", times: 3000),
            static function (): never {
                self::fail('Cancelled info diagram published');
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
