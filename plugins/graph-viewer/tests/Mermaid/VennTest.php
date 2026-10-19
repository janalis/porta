<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\VennDiagram;
use Porta\GraphViewer\Mermaid\VennLexer;
use Porta\GraphViewer\Mermaid\VennParser;

final class VennTest extends TestCase
{
    private static function parse(string $source): VennDiagram
    {
        $parser = new VennParser();
        foreach (new VennLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/venn-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach (\glob(__DIR__.'/../fixtures/mermaid/venn/*.mmd') as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesMatchTheExecutableUpstreamParser(string $path, array $expected): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $diagram = self::parse($source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertSame($expected['title'], $diagram->title->text ?? '');
        foreach (['subsets', 'texts', 'styles'] as $key) {
            $this->assertCount(\count($expected[$key]), $diagram->{$key});
            foreach ($diagram->{$key} as $index => $item) {
                unset($item['statement']);
                $item = \array_filter($item, static fn ($value): bool => null !== $value);
                $reference = $expected[$key][$index];
                if ('subsets' === $key) {
                    $this->assertEqualsWithDelta((float) $reference['size'], $item['size'], delta: 1.0e-12);
                    unset($item['size'], $reference['size']);
                }
                $this->assertSame($reference, $item);
            }
        }
    }
}
