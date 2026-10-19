<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceYamlArrayKey;
use Porta\GraphViewer\Mermaid\SequenceYamlScalar;

final class SequenceYamlArrayKeyTest extends TestCase
{
    #[DataProvider('budgets')]
    public function testJoinedKeysHaveABoundedBudgetBeforeEncoding(string $tail, int $expected): void
    {
        $yaml = 'text: &text "'.\str_repeat('x', times: 32_767)."\"\nignored: &key [".\implode(', ', \array_fill(0, count: 8, value: '*text')).', "'.$tail."\"]\n*key : Value";
        $start = \strpos($yaml, needle: '[');
        $use = \strpos($yaml, needle: '*key');
        $value = \strpos($yaml, needle: '"');
        $this->assertIsInt($start);
        $this->assertIsInt($use);
        $this->assertIsInt($value);
        $bindings = [];
        \preg_match_all('/\*text/', $yaml, $matches, flags: \PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [, $offset]) {
            $bindings[$offset] = [$value, 0, false];
        }
        if ($expected > 262_144) {
            $this->expectException(MermaidSyntaxError::class);
            $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        }
        [$key, $complete] = SequenceYamlArrayKey::read($yaml, $start, $use, $bindings, properties: [], statement: new MermaidStatement('', offset: 0, line: 0, column: 0));
        $this->assertTrue($complete);
        $this->assertSame($expected, \strlen($key));
        $this->assertSame(',', \substr($key, offset: -1));
    }

    public function testAnExplicitEmptyStringBindingDoesNotBecomeNullKeyText(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $yaml = "!!str\nnext: ignored";
        $this->assertSame('', SequenceYamlScalar::keyValue($yaml, start: 0, indent: 0, ownLine: false, statement: $statement));
        $this->assertSame('', SequenceYamlScalar::elementValue($yaml, start: 0, indent: 0, ownLine: false, statement: $statement));
    }

    public static function budgets(): iterable
    {
        yield 'exactly 256 KiB' => ['', 262_144];
        yield 'one byte over' => ['t', 262_145];
    }
}
