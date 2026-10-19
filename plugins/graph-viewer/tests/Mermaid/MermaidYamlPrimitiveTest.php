<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidYamlPrimitive;
use Symfony\Component\Yaml\Yaml;

final class MermaidYamlPrimitiveTest extends TestCase
{
    #[DataProvider('reference')]
    public function testKindsAndCanonicalValuesMatchTheJsonSchema(string $text, string $kind, mixed $expected): void
    {
        $this->assertSame($kind, MermaidYamlPrimitive::kind($text));
        $value = Yaml::parse(MermaidYamlPrimitive::canonical($text), flags: Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        if ('float' === $kind || 'int' === $kind) {
            $this->assertTrue(\is_float($value) || \is_int($value));
            if ('NaN' === $expected) {
                $this->assertNan((float) $value);

                return;
            }
            $expected = match ($expected) {
                'Infinity' => \INF,
                '-Infinity' => -\INF,
                default => (float) $expected,
            };
            $this->assertSame($expected, (float) $value);

            return;
        }
        $this->assertSame($expected, $value);
    }

    public static function reference(): iterable
    {
        $reference = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/yaml-primitives.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['cases'] as $case) {
            yield $case['text'] => [$case['text'], $case['kind'], $case['value']];
        }
    }

    #[DataProvider('keyReference')]
    public function testMappingKeyStringsMatchJavaScriptScalarCoercion(string $text, string $key): void
    {
        $this->assertSame($key, MermaidYamlPrimitive::key($text));
    }

    public static function keyReference(): iterable
    {
        $reference = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/yaml-key-primitives.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['cases'] as $case) {
            yield 'scalar '.$case['text'] => [$case['text'], $case['key']];
        }
    }

    public function testExplicitNullRequiresANullNodeOrAnAcceptedSpelling(): void
    {
        $this->assertTrue(MermaidYamlPrimitive::accepts('null', null));
        $this->assertFalse(MermaidYamlPrimitive::accepts('null', ''));
        $this->assertFalse(MermaidYamlPrimitive::accepts('bool', null));
        $this->assertFalse(MermaidYamlPrimitive::accepts('int', null));
        $this->assertFalse(MermaidYamlPrimitive::accepts('float', null));
        $this->assertFalse(MermaidYamlPrimitive::accepts('object', 'text'));
        $this->assertTrue(MermaidYamlPrimitive::accepts('str', ''));
        $this->assertFalse(MermaidYamlPrimitive::accepts('int', '0_0'));
        $this->assertTrue(MermaidYamlPrimitive::accepts('float', '0_0'));
        $this->assertFalse(MermaidYamlPrimitive::accepts('float', '-.0'));
    }
}
