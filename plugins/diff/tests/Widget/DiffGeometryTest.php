<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Widget\DiffColumn;
use Porta\DiffPlugin\Widget\DiffGeometry;

final class DiffGeometryTest extends TestCase
{
    public function testTextStartPerColumnAndTheSplitBoundary(): void
    {
        $this->assertSame(39, DiffGeometry::splitBoundary(80), 'the divider column');

        $this->assertSame(12, DiffGeometry::textStart(null, 80), 'unified: past the dual gutters');
        $this->assertSame(6, DiffGeometry::textStart(DiffColumn::Old, 80), 'left half gutter');
        $this->assertSame(46, DiffGeometry::textStart(DiffColumn::New, 80), 'divider + right gutter');
    }

    public function testMouseAndRenderCanNoLongerDisagree(): void
    {
        // The regression this class exists to prevent: both paths read the
        // same origin, so a drag's highlight matches its selection per width.
        foreach ([41, 80, 121] as $width) {
            $this->assertSame(
                DiffGeometry::splitBoundary($width) + 7,
                DiffGeometry::textStart(DiffColumn::New, $width),
            );
        }
    }

    /** The text a row shows ends at its column's edge, so a wrapped segment is exactly that wide. */
    public function testBodyWidthRunsFromTheTextStartToTheColumnsEdge(): void
    {
        $this->assertSame(68, DiffGeometry::bodyWidth(null, 80), 'unified: the width less the gutters and the marker');
        $this->assertSame(33, DiffGeometry::bodyWidth(DiffColumn::Old, 80), 'left: up to the divider');
        $this->assertSame(34, DiffGeometry::bodyWidth(DiffColumn::New, 80), 'right: to the pane\'s edge');
        foreach ([41, 80, 121] as $width) {
            $this->assertSame(DiffGeometry::splitBoundary($width), DiffGeometry::textStart(DiffColumn::Old, $width) + DiffGeometry::bodyWidth(DiffColumn::Old, $width));
            $this->assertSame($width, DiffGeometry::textStart(DiffColumn::New, $width) + DiffGeometry::bodyWidth(DiffColumn::New, $width));
            $this->assertSame($width, DiffGeometry::textStart(null, $width) + DiffGeometry::bodyWidth(null, $width));
        }
        $this->assertLessThan(1, DiffGeometry::bodyWidth(null, 12), 'a pane too narrow for any text');
    }
}
