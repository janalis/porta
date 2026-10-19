<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceYamlBlockKeys;

final class SequenceYamlBlockPaddingTest extends TestCase
{
    #[DataProvider('budgets')]
    public function testCompactKeyPaddingIsBoundedBeforeAllocation(int $rootIndent, int $gap, int $padding, string $keySuffix): void
    {
        $indent = \str_repeat(' ', $rootIndent);
        $yaml = $indent.'? '.\str_repeat(' ', $gap).$keySuffix."\n".$indent.": Value\n".$indent."alias: Name\n";
        $this->assertLessThanOrEqual(65_536, \strlen($yaml));
        if ($padding > 262_144) {
            $this->expectException(MermaidSyntaxError::class);
            $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        }
        try {
            [$normalized, $marker] = SequenceYamlBlockKeys::normalize($yaml, new MermaidStatement('', offset: 17, line: 1, column: 2));
        } catch (MermaidSyntaxError $error) {
            if ($padding > 262_144) {
                $this->assertSame(1, $error->sourceLine);
                $this->assertSame(2, $error->column);
                throw $error;
            }
            $this->fail('Compact padding within the documented budget was rejected: '.$error->getMessage());
        }
        $this->assertIsString($marker);
        $this->assertSame(1, \substr_count($normalized, needle: '[entry]'));
        $this->assertLessThan(\strlen($yaml) + $padding + 2048, \strlen($normalized));
    }

    public static function budgets(): iterable
    {
        yield 'explicit exactly 256 KiB' => [4, 43_682, 262_144, '? ? ? [entry]'];
        yield 'explicit one byte over' => [5, 43_681, 262_145, '? ? ? [entry]'];
        yield 'implicit exactly 256 KiB' => [3, 52_422, 262_144, '? [entry]: Value'];
        yield 'implicit one byte over' => [4, 52_421, 262_145, '? [entry]: Value'];
    }
}
