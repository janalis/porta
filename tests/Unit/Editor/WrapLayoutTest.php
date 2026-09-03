<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\WrapLayout;

final class WrapLayoutTest extends TestCase
{
    /** @return \Generator<string, array{int, int, int}> width, line length, rows */
    public static function widths(): \Generator
    {
        yield 'empty line is still a row' => [10, 0, 1];
        yield 'shorter than the width' => [10, 4, 1];
        yield 'exactly the width does not spill' => [10, 10, 1];
        yield 'one past the width takes a second row' => [10, 11, 2];
        yield 'two full widths' => [10, 20, 2];
        yield 'two widths and a remainder' => [10, 21, 3];
    }

    #[DataProvider('widths')]
    public function testRowsForCountsTheSegmentsALineNeeds(int $width, int $length, int $rows): void
    {
        $layout = new WrapLayout(new Document(str_repeat('x', times: $length)."\n"));

        $this->assertSame($rows, $layout->rowsFor(0, $width));
    }

    /** A pane squeezed to nothing has no width to wrap into; every line is one row. */
    public function testANonPositiveWidthWrapsNothing(): void
    {
        $layout = new WrapLayout(new Document(str_repeat('x', times: 50)."\n"));

        $this->assertSame(1, $layout->rowsFor(0, 0));
        $this->assertSame(1, $layout->rowsFor(0, -5));
    }

    /**
     * Segment offsets are the same display columns horizontal scroll slices at
     * — that equivalence is what lets renderContentLine() serve both.
     */
    public function testOffsetAndSegmentAreInverses(): void
    {
        $this->assertSame(0, WrapLayout::offsetOf(0, 10));
        $this->assertSame(20, WrapLayout::offsetOf(2, 10));

        $this->assertSame(0, WrapLayout::segmentOf(0, 10));
        $this->assertSame(0, WrapLayout::segmentOf(9, 10));
        $this->assertSame(1, WrapLayout::segmentOf(10, 10), 'the column at the boundary starts the next segment');
        $this->assertSame(2, WrapLayout::segmentOf(25, 10));
    }

    /** Both helpers are asked about degenerate values by the render path before a layout exists. */
    public function testTheHelpersClampRatherThanDivideByZero(): void
    {
        $this->assertSame(0, WrapLayout::segmentOf(40, 0), 'no width, no segments');
        $this->assertSame(0, WrapLayout::offsetOf(-2, 10), 'a negative segment is the first one');
        $this->assertSame(0, WrapLayout::offsetOf(2, -10));
        $this->assertSame(0, WrapLayout::segmentOf(-40, 10));
    }

    /** The memo is keyed on width AND document version, so neither can serve a stale count. */
    public function testTheMemoFollowsBothTheWidthAndTheDocument(): void
    {
        $document = new Document(str_repeat('x', times: 15)."\n");
        $layout = new WrapLayout($document);

        $this->assertSame(2, $layout->rowsFor(0, 10));
        $this->assertSame(1, $layout->rowsFor(0, 20), 'a wider pane re-measures rather than reusing the count');

        $document->setCursor(0, 15);
        $document->insert(str_repeat('y', times: 30));
        $this->assertSame(3, $layout->rowsFor(0, 20), 'and an edit re-measures at the same width');
    }
}
