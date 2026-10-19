<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\D3Format;

/** d3-format against the copy bundled in the pinned build (fixtures/mermaid/options/d3-format-reference.json). */
final class D3FormatTest extends TestCase
{
    #[DataProvider('cases')]
    public function testFormatsLikeThePinnedBuild(string $specifier, float $value, string $expected): void
    {
        $this->assertSame($expected, D3Format::parse($specifier)->format($value));
    }

    #[DataProvider('invalid')]
    public function testInvalidSpecifiersAreRejected(string $specifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        D3Format::parse($specifier);
    }

    /** @return iterable<string, array{string, float, string}> */
    public static function cases(): iterable
    {
        foreach (self::reference() as $case) {
            if (!\is_string($case['output'] ?? null)) {
                continue;
            }
            yield $case['specifier'].' '.\json_encode($case['value'] ?? null, \JSON_THROW_ON_ERROR) => [$case['specifier'], (float) ($case['value'] ?? 0), $case['output']];
        }
    }

    /** @return iterable<array{string}> */
    public static function invalid(): iterable
    {
        foreach (self::reference() as $case) {
            if (true !== ($case['invalid'] ?? false)) {
                continue;
            }
            yield [$case['specifier']];
        }
    }

    /** @return list<array{specifier: string, value?: float|int, output?: string|array<string, string>, invalid?: bool}> */
    private static function reference(): array
    {
        /** @var array{cases: list<array{specifier: string, value?: float|int, output?: string|array<string, string>, invalid?: bool}>} $reference */
        $reference = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/options/d3-format-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);

        return $reference['cases'];
    }
}
