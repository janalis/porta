<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidRough;
use Porta\GraphViewer\Mermaid\MermaidRoughRandom;

/** The roughjs port against the generator the pinned build bundles (rough-oracle.mjs). */
final class MermaidRoughTest extends TestCase
{
    private const string REFERENCE = __DIR__.'/../fixtures/mermaid/look/rough-reference.json';

    /**
     * @param list<array{float, float}> $points
     * @param list<list<list<float>>> $expected each set's operations, fill before outline
     */
    #[DataProvider('generated')]
    public function testOperationsMatchTheBundledRoughjs(string $kind, int $seed, float $roughness, array $points, array $expected): void
    {
        $rough = new MermaidRough($seed, $roughness);
        $actual = 'polygon' === $kind ? $rough->polygon($points) : [$rough->line($points)];
        $this->assertCount(\count($expected), $actual);
        foreach ($expected as $set => $operations) {
            $this->assertCount(\count($operations), $actual[$set], 'set '.$set);
            foreach ($operations as $index => $operation) {
                $this->assertEqualsWithDelta($operation, $actual[$set][$index], 1e-9, 'set '.$set.' operation '.$index);
            }
        }
    }

    /** @return iterable<string, array{string, int, float, list<array{float, float}>, list<list<list<float>>>}> */
    public static function generated(): iterable
    {
        /** @var array{cases: list<array{kind: string, name: string, seed: int, roughness?: float, points: list<array{float, float}>, sets: list<array{ops: list<list<float|string>>}>}>} $reference */
        $reference = \json_decode((string) \file_get_contents(self::REFERENCE), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['cases'] as $case) {
            $roughness = (float) ($case['roughness'] ?? 0.7);
            $points = \array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $case['points']);
            $sets = \array_map(static fn (array $set): array => \array_map(static fn (array $operation): array => \array_map(
                \floatval(...),
                \array_slice($operation, offset: 1),
            ), $set['ops']), $case['sets']);
            yield $case['kind'].' '.$case['name'].' seed '.$case['seed'].' roughness '.$roughness => [$case['kind'], $case['seed'], $roughness, $points, $sets];
        }
    }

    public function testRandomFollowsJavascriptImulAndWrapsSeedsToThirtyTwoBits(): void
    {
        // Each draw is the generator's state over 2^31, so the states read back exactly.
        $expected = [
            1 => [48_271, 182_605_793, 1_291_342_511],
            -7 => [2_147_145_751, 869_243_097, 1_698_020_663],
            4_294_967_297 => [48_271, 182_605_793, 1_291_342_511],
            2_147_483_647 => [2_147_435_377, 1_964_877_855, 856_141_137],
        ];
        foreach ($expected as $seed => $states) {
            $random = new MermaidRoughRandom($seed);
            $this->assertSame(\array_map(static fn (int $state): float => $state / 2_147_483_648, $states), [$random->next(), $random->next(), $random->next()], 'seed '.$seed);
        }
    }

    public function testPolylinesFlattenEachCubicAndSplitAtMoves(): void
    {
        $lines = MermaidRough::polylines([[1.0, 1.0], [0.0, 0.0], [10.0, 0.0, 20.0, 0.0, 30.0, 0.0], [100.0, 100.0], [100.0, 100.0, 100.0, 100.0, 100.0, 100.0]], scale: 10.0);
        $this->assertEqualsWithDelta(
            [
                [[0.0, 0.0], [1.0, 0.0], [2.0, 0.0], [3.0, 0.0]],
                [[10.0, 10.0], [10.0, 10.0], [10.0, 10.0]],
            ],
            $lines,
            1e-12,
            'a lone move draws nothing; three 12px stretches of control polygon are three steps; a point is two',
        );
        $long = MermaidRough::polylines([[0.0, 0.0], [0.0, 400.0, 0.0, 800.0, 0.0, 1200.0]], scale: 1.0);
        $this->assertCount(13, $long[0], 'a cubic is never cut in more than twelve');
        $this->assertEqualsWithDelta([0.0, 100.0], $long[0][1], 1e-9);
        $curve = MermaidRough::polylines([[0.0, 0.0], [0.0, 16.0, 16.0, 16.0, 16.0, 0.0]], scale: 1.0);
        $this->assertEqualsWithDelta([[0.0, 0.0], [2.5, 9.0], [8.0, 12.0], [13.5, 9.0], [16.0, 0.0]], $curve[0], 1e-9, 'the Bernstein weights of a cubic');
        $this->assertSame([], MermaidRough::polylines([], scale: 10.0));
    }
}
