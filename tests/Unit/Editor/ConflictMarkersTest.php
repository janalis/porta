<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ConflictMarkers;
use Porta\Editor\ConflictSide;

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
            [['start' => 1, 'base' => null, 'sep' => 3, 'end' => 5]],
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
        $this->assertSame(['start' => 0, 'base' => null, 'sep' => 2, 'end' => 4], $regions[0]);
        $this->assertSame(['start' => 6, 'base' => null, 'sep' => 8, 'end' => 10], $regions[1]);
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
            [['start' => 2, 'base' => null, 'sep' => 4, 'end' => 6]],
            ConflictMarkers::find($lines),
        );
    }

    public function testRegionAt(): void
    {
        $lines = $this->lines("<<<<<<< a\nx\n=======\ny\n>>>>>>> b\ntail");
        $this->assertSame(['start' => 0, 'base' => null, 'sep' => 2, 'end' => 4], ConflictMarkers::regionAt($lines, 0), 'the opener line itself is inside the region');
        $this->assertSame(['start' => 0, 'base' => null, 'sep' => 2, 'end' => 4], ConflictMarkers::regionAt($lines, 1));
        $this->assertSame(['start' => 0, 'base' => null, 'sep' => 2, 'end' => 4], ConflictMarkers::regionAt($lines, 4));
        $this->assertNull(ConflictMarkers::regionAt($lines, 5), 'tail line is outside the region');
    }

    public function testASecondSeparatorInsideARegionIsIgnored(): void
    {
        // Once a separator is recorded, a later stray "=======" line must not
        // overwrite it — it belongs to "theirs" content, not a new boundary.
        $lines = $this->lines("<<<<<<< a\nours\n=======\nmid\n=======\ntheirs\n>>>>>>> b");
        $this->assertSame(
            [['start' => 0, 'base' => null, 'sep' => 2, 'end' => 6]],
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

        $this->assertSame(['o1', 'o2'], ConflictMarkers::resolution($lines, $region, ConflictSide::Ours));
        $this->assertSame(['t1'], ConflictMarkers::resolution($lines, $region, ConflictSide::Theirs));
        $this->assertSame(['o1', 'o2', 't1'], ConflictMarkers::resolution($lines, $region, ConflictSide::Both));
    }

    public function testResolutionWithEmptySide(): void
    {
        $lines = $this->lines("<<<<<<< a\n=======\nt1\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];
        $this->assertSame([], ConflictMarkers::resolution($lines, $region, ConflictSide::Ours));
        $this->assertSame(['t1'], ConflictMarkers::resolution($lines, $region, ConflictSide::Both));
    }

    public function testResolutionWhenTheRegionDoesNotStartAtLineZero(): void
    {
        // A conflict block deeper in the file, so `sep - start` and a wrong
        // `sep + start` would only diverge once `start` is nonzero.
        $lines = $this->lines("before1\nbefore2\n<<<<<<< a\no1\no2\n=======\nt1\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertSame(['o1', 'o2'], ConflictMarkers::resolution($lines, $region, ConflictSide::Ours));
    }

    public function testResolutionBothSpreadsEveryTheirsLineNotJustTheFirst(): void
    {
        $lines = $this->lines("<<<<<<< a\no1\n=======\nt1\nt2\nt3\n>>>>>>> b");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertSame(['o1', 't1', 't2', 't3'], ConflictMarkers::resolution($lines, $region, ConflictSide::Both));
    }

    public function testADiff3BaseSectionIsRecordedApartFromOurs(): void
    {
        $lines = $this->lines("pre\n<<<<<<< HEAD\no1\n||||||| base\nb1\n=======\nt1\n>>>>>>> topic");

        $this->assertSame([['start' => 1, 'base' => 3, 'sep' => 5, 'end' => 7]], ConflictMarkers::find($lines));
    }

    public function testNoResolutionKeepsTheBaseSection(): void
    {
        // git's diff3 and zdiff3 styles: the base sits between ours and the separator.
        $lines = $this->lines("pre\n<<<<<<< HEAD\no1\no2\n||||||| merged common ancestors\nb1\n=======\nt1\n>>>>>>> topic");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertSame(['o1', 'o2'], ConflictMarkers::resolution($lines, $region, ConflictSide::Ours));
        $this->assertSame(['t1'], ConflictMarkers::resolution($lines, $region, ConflictSide::Theirs));
        $this->assertSame(['o1', 'o2', 't1'], ConflictMarkers::resolution($lines, $region, ConflictSide::Both));
    }

    public function testOnlyTheFirstBaseMarkerCounts(): void
    {
        $lines = $this->lines("<<<<<<< a\no1\n||||||| b\nb1\n||||||| b\nb2\n=======\nt1\n>>>>>>> c");

        $this->assertSame(2, ConflictMarkers::find($lines)[0]['base']);
    }

    public function testABaseMarkerAfterTheSeparatorIsTheirsContent(): void
    {
        $lines = $this->lines("<<<<<<< a\no1\n=======\n||||||| t\n>>>>>>> c");
        $region = ConflictMarkers::find($lines)[0];

        $this->assertNull($region['base']);
        $this->assertSame(['||||||| t'], ConflictMarkers::resolution($lines, $region, ConflictSide::Theirs));
    }

    public function testANewOpenerForgetsAPendingBase(): void
    {
        $lines = $this->lines("<<<<<<< a\n||||||| b\n<<<<<<< c\no1\n=======\nt1\n>>>>>>> d");

        $this->assertSame([['start' => 2, 'base' => null, 'sep' => 4, 'end' => 6]], ConflictMarkers::find($lines));
    }

    public function testABaseIsForgottenOnceItsRegionCloses(): void
    {
        $lines = $this->lines("<<<<<<< a\no\n||||||| b\n=======\nt\n>>>>>>> c\n<<<<<<< d\no\n=======\nt\n>>>>>>> e");

        $this->assertNull(ConflictMarkers::find($lines)[1]['base']);
    }

    public function testCleanTextIsResolved(): void
    {
        $this->assertFalse(ConflictMarkers::unresolved($this->lines("<?php\necho 1;\nTitle\n=======\n")), 'a lone separator is an underline');
    }

    public function testAWholeRegionIsUnresolved(): void
    {
        $this->assertTrue(ConflictMarkers::unresolved($this->lines("a\n<<<<<<< HEAD\no\n=======\nt\n>>>>>>> topic")));
    }

    public function testAStrayMarkerLeftByAHandResolutionIsUnresolved(): void
    {
        $this->assertTrue(ConflictMarkers::unresolved($this->lines("o\n>>>>>>> topic")), 'a closer');
        $this->assertTrue(ConflictMarkers::unresolved($this->lines("<<<<<<< HEAD\no")), 'an opener');
        $this->assertTrue(ConflictMarkers::unresolved($this->lines("o\n||||||| base\nb")), 'a base');
    }

    public function testAMarkerMidLineIsNotOne(): void
    {
        $this->assertFalse(ConflictMarkers::unresolved($this->lines(" <<<<<<< x\n// >>>>>>> y")));
    }
}
