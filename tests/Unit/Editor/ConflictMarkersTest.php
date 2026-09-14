<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ConflictMarkers;

final class ConflictMarkersTest extends TestCase
{
    /** @return list<string> */
    private function lines(string $text): array
    {
        return \explode("\n", $text);
    }

    public function testFindsWellFormedRegions(): void
    {
        $lines = $this->lines(
            "before\n<<<<<<< HEAD\nours line\n=======\ntheir line\n>>>>>>> branch\n".'after',
        );

        $this->assertSame(
            [['start' => 1, 'sep' => 3, 'end' => 5]],
            ConflictMarkers::find($lines),
        );
    }

    public function testFindsMultipleRegions(): void
    {
        $lines = $this->lines(
            "<<<<<<< a\nx\n=======\ny\n>>>>>>> b\nmid\n<<<<<<< c\nz\n=======\nw\n>>>>>>> d",
        );

        $regions = ConflictMarkers::find($lines);
        $this->assertCount(2, $regions);
        $this->assertSame(['start' => 0, 'sep' => 2, 'end' => 4], $regions[0]);
        $this->assertSame(['start' => 6, 'sep' => 8, 'end' => 10], $regions[1]);
    }

    public function testIgnoresIncompleteMarkers(): void
    {
        // Separator/closer without an opener, and an opener with no closer.
        $lines = $this->lines("=======\n>>>>>>> x\n<<<<<<< y\nours\n=======\ntheirs");
        $this->assertSame([], ConflictMarkers::find($lines));
    }

    public function testNewOpenerResetsPendingSeparator(): void
    {
        // A second opener before any separator discards the first opener.
        $lines = $this->lines("<<<<<<< a\nfoo\n<<<<<<< b\nbar\n=======\nbaz\n>>>>>>> c");
        $this->assertSame(
            [['start' => 2, 'sep' => 4, 'end' => 6]],
            ConflictMarkers::find($lines),
        );
    }

    public function testRegionAt(): void
    {
        $lines = $this->lines("<<<<<<< a\nx\n=======\ny\n>>>>>>> b\ntail");
        $this->assertSame(['start' => 0, 'sep' => 2, 'end' => 4], ConflictMarkers::regionAt($lines, 0), 'the opener line itself is inside the region');
        $this->assertSame(['start' => 0, 'sep' => 2, 'end' => 4], ConflictMarkers::regionAt($lines, 1));
        $this->assertSame(['start' => 0, 'sep' => 2, 'end' => 4], ConflictMarkers::regionAt($lines, 4));
        $this->assertNull(ConflictMarkers::regionAt($lines, 5), 'tail line is outside the region');
    }

    public function testASecondSeparatorInsideARegionIsIgnored(): void
    {
        // Once a separator is recorded, a later stray "=======" line must not
        // overwrite it — it belongs to "theirs" content, not a new boundary.
        $lines = $this->lines("<<<<<<< a\nours\n=======\nmid\n=======\ntheirs\n>>>>>>> b");
        $this->assertSame(
            [['start' => 0, 'sep' => 2, 'end' => 6]],
            ConflictMarkers::find($lines),
        );
    }

    public function testACloserWithNoSeparatorIsNotAValidRegion(): void
    {
        // An opener followed directly by a closer, with no "=======" between
        // them, is malformed and must be reported as no region at all.
        $lines = $this->lines("<<<<<<< a\nfoo\n>>>>>>> b");
        $this->assertSame([], ConflictMarkers::find($lines));
    }

    public function testResolutionKeepsOursTheirsOrBoth(): void
    {
        $lines = $this->lines("<<<<<<< a\no1\no2\n=======\nt1\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertSame(['o1', 'o2'], ConflictMarkers::resolution($lines, $region, 'ours'));
        $this->assertSame(['t1'], ConflictMarkers::resolution($lines, $region, 'theirs'));
        $this->assertSame(['o1', 'o2', 't1'], ConflictMarkers::resolution($lines, $region, 'both'));
    }

    public function testResolutionWithEmptySide(): void
    {
        $lines = $this->lines("<<<<<<< a\n=======\nt1\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];
        $this->assertSame([], ConflictMarkers::resolution($lines, $region, 'ours'));
        $this->assertSame(['t1'], ConflictMarkers::resolution($lines, $region, 'both'));
    }

    public function testResolutionWhenTheRegionDoesNotStartAtLineZero(): void
    {
        // A conflict block deeper in the file, so `sep - start` and a wrong
        // `sep + start` would only diverge once `start` is nonzero.
        $lines = $this->lines("before1\nbefore2\n<<<<<<< a\no1\no2\n=======\nt1\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertSame(['o1', 'o2'], ConflictMarkers::resolution($lines, $region, 'ours'));
    }

    public function testResolutionBothSpreadsEveryTheirsLineNotJustTheFirst(): void
    {
        $lines = $this->lines("<<<<<<< a\no1\n=======\nt1\nt2\nt3\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertSame(['o1', 't1', 't2', 't3'], ConflictMarkers::resolution($lines, $region, 'both'));
    }
}
