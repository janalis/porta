<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\RailroadExpression;
use Janalis\Viz\Mermaid\RailroadLexer;
use Janalis\Viz\Mermaid\RailroadParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RailroadTest extends TestCase
{
    public static function fixtures(): iterable
    {
        $expected = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/railroad-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        $found = \glob(__DIR__.'/../fixtures/mermaid/railroad/*.mmd');
        foreach (false === $found ? [] : $found as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedGrammarsProduceTheSameTransformedExpressionTrees(string $path, array $expected): void
    {
        $parser = new RailroadParser();
        foreach (new RailroadLexer((string) \file_get_contents($path))->statements() as $statement) {
            $parser->accept($statement);
        }
        $this->assertNotEmpty(NativeFixtureRows::render((string) \file_get_contents($path), width: 100)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render((string) \file_get_contents($path), Format::Svg));
        $source = $parser->finish();
        $this->assertSame($expected['metadata'], $source->metadata);
        $rules = [];
        foreach ($source->rules as $rule) {
            $rules[] = ['name' => $rule['name'], 'definition' => self::model($rule['definition'])];
        }
        $this->assertSame($expected['rules'], $rules);
    }

    private static function model(RailroadExpression $expression): array
    {
        $children = \array_map(self::model(...), $expression->children);

        return match ($expression->type) {
            'terminal' => ['type' => 'terminal', 'value' => $expression->label],
            'nonterminal' => ['type' => 'nonterminal', 'name' => $expression->label],
            'special' => ['type' => 'special', 'text' => $expression->label],
            'sequence' => ['type' => 'sequence', 'elements' => $children],
            'choice' => ['type' => 'choice', 'alternatives' => $children],
            'optional' => ['type' => 'optional', 'element' => $children[0]],
            default => ['type' => 'repetition', 'element' => $children[0], 'min' => $expression->min, 'max' => $expression->max],
        };
    }
}
