<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\ArchitectureDiagram;
use Janalis\Viz\Mermaid\ArchitectureLexer;
use Janalis\Viz\Mermaid\ArchitectureParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    private static function parse(string $source): ArchitectureDiagram
    {
        $parser = new ArchitectureParser();
        foreach (new ArchitectureLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/architecture-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach (\glob(__DIR__.'/../fixtures/mermaid/architecture/*.mmd') as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesMatchTheExecutableUpstreamGrammar(string $path, array $expected): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $diagram = self::parse($source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        foreach (['group' => 'groups', 'service' => 'services', 'junction' => 'junctions'] as $kind => $key) {
            $items = [];
            foreach ($diagram->items as $item) {
                if ($kind !== $item['kind']) {
                    continue;
                }
                unset($item['kind'], $item['statement']);
                $items[] = \array_filter($item, static fn (?string $value): bool => null !== $value);
            }
            $this->assertSame($expected[$key], $items);
        }
        $edges = [];
        foreach ($diagram->edges as $edge) {
            unset($edge['statement']);
            $edges[] = \array_filter($edge, static fn (bool|string|null $value): bool => null !== $value);
        }
        $this->assertEquals($expected['edges'], $edges);
        $alignments = [];
        foreach ($diagram->alignments as $alignment) {
            unset($alignment['statement']);
            $alignments[] = $alignment;
        }
        $this->assertSame($expected['alignments'], $alignments);
    }

    public function testInlineTitleQuotedTextAndSourceLocations(): void
    {
        $source = "architecture-beta title Example\r\n%% ignored\r\n service a \"x\\ny\"[\"a\\\"b\"]\r\naccDescr {\n Description\n}\n";
        $diagram = self::parse($source);
        $this->assertSame('Example', $diagram->metadata['title']);
        $this->assertSame("x\ny", $diagram->items['a']['iconText']);
        $this->assertSame('a"b', $diagram->items['a']['title']);
        $this->assertSame(2, $diagram->items['a']['statement']->line);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
    }
}
