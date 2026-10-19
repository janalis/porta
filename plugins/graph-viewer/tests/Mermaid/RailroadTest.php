<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\RailroadExpression;
use Porta\GraphViewer\Mermaid\RailroadLexer;
use Porta\GraphViewer\Mermaid\RailroadParser;

final class RailroadTest extends TestCase
{
    public static function fixtures(): iterable
    {
        $expected = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/railroad-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach (\glob(__DIR__.'/../fixtures/mermaid/railroad/*.mmd') as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedGrammarsProduceTheSameTransformedExpressionTrees(string $path, array $expected): void
    {
        $parser = new RailroadParser();
        foreach (new RailroadLexer(\file_get_contents($path))->statements() as $statement) {
            $parser->accept($statement);
        }
        $this->assertNotEmpty(NativeFixtureRows::render(\file_get_contents($path), width: 100)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render(\file_get_contents($path), GraphExportFormat::Svg));
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
