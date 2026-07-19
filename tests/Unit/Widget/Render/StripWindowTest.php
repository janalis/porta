<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\StripWindow;

final class StripWindowTest extends TestCase
{
    public function testAStripThatFitsHasNoOverflowAndStartsAtZero(): void
    {
        $w = StripWindow::of([10, 8], columns: 40, scroll: 0, activeIndex: 0, lastActive: null);

        $this->assertFalse($w['overflow']);
        $this->assertSame(40, $w['budget'], 'no overflow: the tabs get the whole width');
        $this->assertSame(0, $w['start']);
        $this->assertSame(0, $w['lastActive'], 'the active index is adopted on the first frame');
    }

    public function testOverflowReservesAColumnPerSideAndFollowsTheActiveTab(): void
    {
        // Four 10-wide tabs in 25 cols → overflow; budget 23. Active is the last.
        $w = StripWindow::of([10, 10, 10, 10], columns: 25, scroll: 0, activeIndex: 3, lastActive: null);

        $this->assertTrue($w['overflow']);
        $this->assertSame(23, $w['budget']);
        $this->assertGreaterThan(0, $w['start'], 'the window scrolled forward to reveal the active tab');
        $this->assertLessThanOrEqual(3, $w['start']);
    }

    public function testAManualScrollSurvivesWhileTheActiveTabIsUnchanged(): void
    {
        // Active unchanged (lastActive === activeIndex): the caller's scroll stays.
        $w = StripWindow::of([10, 10, 10, 10], columns: 25, scroll: 1, activeIndex: 0, lastActive: 0);

        $this->assertSame(1, $w['start'], 'a parked frame keeps the user scroll');
    }

    public function testScrollIsClampedSoTheStripNeverShowsABlankTail(): void
    {
        // scroll far past the end → clamped to the max start that still fills 25 cols
        // (last two 10-wide tabs = 20 ≤ 23 budget; a third would be 30 > 23).
        $w = StripWindow::of([10, 10, 10, 10], columns: 25, scroll: 99, activeIndex: 0, lastActive: 0);

        $this->assertSame(2, $w['start'], 'the last two tabs fill the budget; no blank tail');
    }
}
