<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Mermaid\ErMarkers;

final class ErMarkersTest extends TestCase
{
    /** @param list<array{float, float}> $points */
    private static function edge(array $points): GraphEdge
    {
        return new GraphEdge(3, 7, $points, null, 0, 0, 20, 20, null, null, null, dashed: true);
    }

    #[DataProvider('cardinalities')]
    public function testBothEndpointGlyphsUseSolidBoundedPrimitives(string $card, int $count, int $filled): void
    {
        $marks = ErMarkers::forEdge(self::edge([[0.0, 0.0], [20.0, 0.0]]), $card, $card);
        $this->assertCount($count, $marks);
        $this->assertCount($filled, \array_filter($marks, static fn (GraphEdge $mark): bool => null !== $mark->ribbon));
        foreach ($marks as $mark) {
            $this->assertSame([3, 7], [$mark->tail, $mark->head]);
            $this->assertFalse($mark->dashed);
            $this->assertNull($mark->arrowTip);
            $this->assertNull($mark->label);
            $this->assertSame(\min(\array_column($mark->points, 0)), $mark->minX);
            $this->assertSame(\max(\array_column($mark->points, 0)), $mark->maxX);
            $this->assertSame(\min(\array_column($mark->points, 1)), $mark->minY);
            $this->assertSame(\max(\array_column($mark->points, 1)), $mark->maxY);
            foreach ($mark->points as [$x, $y]) {
                $this->assertTrue(\is_finite($x) && \is_finite($y));
                $this->assertTrue(($x > 0 && $x < 4) || ($x > 16 && $x < 20));
                $this->assertLessThanOrEqual(0.9, \abs($y));
            }
        }
    }

    /** @return iterable<array{string, int, int}> */
    public static function cardinalities(): iterable
    {
        yield ['1', 4, 0];
        yield ['0..1', 6, 4];
        yield ['1..*', 6, 0];
        yield ['0..*', 8, 4];
        yield ['MD parent', 2, 2];
    }

    public function testMandatoryBarsMirrorAtTheTarget(): void
    {
        $marks = ErMarkers::forEdge(self::edge([[0.0, 0.0], [20.0, 0.0]]), '1', '1');
        $this->assertSame([[0.6, -0.8], [0.6, 0.8]], $marks[0]->points);
        $this->assertSame([[1.6, -0.8], [1.6, 0.8]], $marks[1]->points);
        $this->assertSame([[19.4, 0.8], [19.4, -0.8]], $marks[2]->points);
        $this->assertSame([[18.4, 0.8], [18.4, -0.8]], $marks[3]->points);
    }

    public function testOptionalCircleMasksItsConnectorAndManyHasThreeProngs(): void
    {
        $marks = ErMarkers::forEdge(self::edge([[0.0, 0.0], [20.0, 0.0]]), '0..*', 'MD parent');
        $this->assertSame([[0.2, -0.9], [1.8, 0.0], [0.2, 0.9]], $marks[0]->points);
        $this->assertSame([[0.2, 0.0], [1.8, 0.0]], $marks[1]->points);
        $this->assertSame('@lineColor', $marks[2]->color);
        $this->assertSame('@mainBkg', $marks[3]->color);
        $this->assertCount(32, $marks[2]->ribbon);
        $this->assertGreaterThan($marks[3]->maxX, $marks[2]->maxX);
        $this->assertLessThan($marks[3]->minX, $marks[2]->minX);
        $this->assertCount(4, $marks[4]->ribbon);
        $this->assertSame('@lineColor', $marks[4]->color);
    }

    #[DataProvider('directions')]
    public function testGlyphAxesFollowVerticalDiagonalAndReversedSegments(float $dx, float $dy): void
    {
        $length = \hypot($dx, $dy);
        $marks = ErMarkers::forEdge(self::edge([[10.0, 10.0], [10.0 + $dx, 10.0 + $dy]]), '1', '1');
        foreach ($marks[0]->points as [$x, $y]) {
            $along = ((($x - 10) * $dx) + (($y - 10) * $dy)) / $length;
            $across = ((($y - 10) * $dx) - (($x - 10) * $dy)) / $length;
            $this->assertEqualsWithDelta(0.6, $along, delta: 1.0e-9);
            $this->assertEqualsWithDelta(0.8, \abs($across), delta: 1.0e-9);
        }
    }

    /** @return iterable<array{float, float}> */
    public static function directions(): iterable
    {
        yield [0.0, 20.0];
        yield [0.0, -20.0];
        yield [-20.0, 0.0];
        yield [20.0, 20.0];
        yield [-20.0, -20.0];
    }

    public function testShortAndDegenerateRoutesNeverProduceInvalidCoordinates(): void
    {
        foreach (ErMarkers::forEdge(self::edge([[0.0, 0.0], [1.0, 0.0]]), '0..*', '0..*') as $mark) {
            $this->assertGreaterThanOrEqual(0.0, $mark->minX);
            $this->assertLessThanOrEqual(1.0, $mark->maxX);
        }
        $this->assertSame([], ErMarkers::forEdge(self::edge([]), '1', '1'));
        $this->assertSame([], ErMarkers::forEdge(self::edge([[1.0, 1.0]]), '1', '1'));
        $this->assertSame([], ErMarkers::forEdge(self::edge([[1.0, 1.0], [1.0, 1.0]]), '1', '1'));
    }

    public function testInvalidInternalCardinalityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown ER endpoint cardinality');
        ErMarkers::forEdge(self::edge([[0.0, 0.0], [20.0, 0.0]]), 'unknown', '1');
    }
}
