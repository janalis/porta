<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\UsecaseDiagram;
use Janalis\Viz\Mermaid\UsecaseLexer;
use Janalis\Viz\Mermaid\UsecaseParser;
use Janalis\Viz\Mermaid\UsecaseStyles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsecaseTest extends TestCase
{
    private static function parse(string $source): UsecaseDiagram
    {
        $parser = new UsecaseParser();
        foreach (new UsecaseLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/usecase-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        $found = \glob(__DIR__.'/../fixtures/mermaid/usecase/*.mmd');
        foreach (false === $found ? [] : $found as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesMatchTheUpstreamSemanticModel(string $path, array $expected): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: (string) \file_get_contents($path)) ?? '';
        $diagram = self::parse($source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        $this->assertSame('TD' === $expected['direction'] ? 'TB' : $expected['direction'], $diagram->graph->direction);
        $actual = ['actor' => [], 'usecase' => [], 'boundary' => [], 'json' => []];
        foreach ($diagram->elements as $element) {
            if ('edge' === $element->kind) {
                continue;
            }
            $actual[$element->kind][] = $element->id;
        }
        foreach (['actor' => 'actors', 'usecase' => 'usecases', 'boundary' => 'boundaries'] as $role => $key) {
            $this->assertEqualsCanonicalizing(\array_column($expected[$key], 'id'), $actual[$role]);
            foreach ($expected[$key] as $element) {
                $native = $diagram->elements[$element['id']];
                $this->assertSame($element['label'], $native->raw);
                $this->assertSame($element['stereotype'] ?? null, $native->stereotype);
                $this->assertSame($element['business'] ?? false, $native->metadata['business'] ?? false);
                if ('actor' === $role) {
                    $icon = $native->metadata['icon'] ?? '';
                    $this->assertSame($element['type'], '' !== $icon ? 'icon' : $native->metadata['type'] ?? 'normal');
                }
                if ('usecase' === $role) {
                    $this->assertSame($element['shape'], $native->shape);
                }
                if ('boundary' === $role) {
                    $this->assertSame($element['type'], $native->metadata['type'] ?? 'rect');
                    $members = [];
                    foreach ($diagram->elements as $member) {
                        if ($member->parent !== $native->id) {
                            continue;
                        }
                        $members[] = $member->id;
                    }
                    $this->assertEqualsCanonicalizing($element['members'], $members);
                }
            }
        }
        $this->assertEqualsCanonicalizing($expected['json'], $actual['json']);
        $this->assertCount(\count($expected['relations']), $diagram->relations);
        foreach ($expected['relations'] as $index => $relationship) {
            $native = $diagram->relations[$index];
            $this->assertSame($relationship['from'], $native->from);
            $this->assertSame($relationship['to'], $native->to);
            $this->assertSame($relationship['kind'], $native->kind);
            $this->assertSame($relationship['id'], $native->id);
            $this->assertSame($relationship['label'], $native->label);
        }
        $this->assertCount(\count($expected['notes']), $diagram->notes);
    }

    public static function invalidStyles(): iterable
    {
        yield ['style Unknown fill:red'];
        yield ['style A,B fill:red'];
        yield ['classDef red'];
    }

    #[DataProvider('invalidStyles')]
    public function testRejectsInvalidStyleTargets(string $style): void
    {
        $diagram = self::parse("usecase-beta\nA\nB\n".$style);
        $work = UsecaseStyles::apply($diagram, $diagram->graph);
        $this->expectException(MermaidSyntaxError::class);
        \iterator_to_array($work);
    }
}
