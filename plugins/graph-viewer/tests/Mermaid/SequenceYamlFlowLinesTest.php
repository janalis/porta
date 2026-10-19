<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceYamlFlowLines;

final class SequenceYamlFlowLinesTest extends TestCase
{
    #[DataProvider('budgets')]
    public function testPaddingIsBoundedBeforeAllocation(int $lines, int $existing, int $added): void
    {
        $yaml = \str_repeat(' ', times: 8190)."value: [\n".\str_repeat(' ', $existing).\str_repeat("a,\n", $lines).']';
        if ($added > 262_144) {
            $this->expectException(MermaidSyntaxError::class);
            $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        }
        try {
            $normalized = SequenceYamlFlowLines::normalize($yaml, new MermaidStatement('', offset: 0, line: 0, column: 0));
        } catch (MermaidSyntaxError $error) {
            if ($added > 262_144) {
                throw $error;
            }
            $this->fail('Padding within the documented budget was rejected: '.$error->getMessage());
        }
        $this->assertSame(\strlen($yaml) + $added, \strlen($normalized));
        $this->assertSame(\substr_count($yaml, needle: "\n"), \substr_count($normalized, needle: "\n"));
        $this->assertStringEndsWith(\str_repeat(' ', times: 8192).']', $normalized);
    }

    public static function budgets(): iterable
    {
        yield 'exactly 256 KiB' => [31, 0, 262_144];
        yield 'one byte over' => [32, 8191, 262_145];
    }
}
