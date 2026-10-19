<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\VennDiagram;
use Janalis\Viz\Mermaid\VennLexer;
use Janalis\Viz\Mermaid\VennParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $expected = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/venn-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        $found = \glob(__DIR__.'/../fixtures/mermaid/venn/*.mmd');
        foreach (false === $found ? [] : $found as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesMatchTheExecutableUpstreamParser(string $path, array $expected): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: (string) \file_get_contents($path)) ?? '';
        $diagram = self::parse($source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, Format::Svg));
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
