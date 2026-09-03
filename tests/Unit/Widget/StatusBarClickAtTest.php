<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\StatusClickSpan;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Where a right-hand status cell actually LANDS on the row.
 *
 * The provider reports these spans relative to the right segment, because only
 * the widget knows where that segment starts — after the leading space, the
 * left text, and a gap it computes from the terminal width. Getting that shift
 * wrong puts every cell in the wrong place while every unit below it passes,
 * so each assertion here locates the cell in the RENDERED row rather than
 * trusting the arithmetic.
 */
final class StatusBarClickAtTest extends TestCase
{
    private const int COLUMNS = 60;

    private function bar(string $left = 'file.php', string $right = 'AB CD'): StatusBarWidget
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => [
            'left' => $left,
            'right' => $right,
            'menus' => [],
            // 'AB' is cols [0,2) of the right segment; 'CD' is [3,5).
            'rightClicks' => [
                new StatusClickSpan(0, 2, 'ide_problems'),
                new StatusClickSpan(3, 5, 'goto_line'),
            ],
        ]);
        $status->beforeRender();

        return $status;
    }

    /** The column the given text occupies in the rendered row. */
    private static function columnOf(string $row, string $needle): int
    {
        $plain = AnsiUtils::stripAnsiCodes($row);
        $at = mb_strpos($plain, $needle);
        self::assertNotFalse($at, "the row should contain '{$needle}'");

        return $at;
    }

    public function testACellIsFoundAtTheColumnItRendersAt(): void
    {
        $status = $this->bar();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        self::assertSame('ide_problems', $status->clickAt(self::columnOf($row, 'AB')));
        self::assertSame('goto_line', $status->clickAt(self::columnOf($row, 'CD')));
    }

    public function testTheGapBetweenTwoCellsBelongsToNeither(): void
    {
        $status = $this->bar();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        self::assertNull($status->clickAt(self::columnOf($row, 'AB') + 2));
    }

    public function testTheLeftSideAndTheEmptyGapAreNotClickable(): void
    {
        $status = $this->bar();
        $status->render(new RenderContext(self::COLUMNS, 1));

        self::assertNull($status->clickAt(0));
        self::assertNull($status->clickAt(3));
    }

    public function testTheSpansFollowTheRightSegmentWhenTheTerminalIsResized(): void
    {
        // The gap absorbs the width change, so an absolute column baked in at
        // one width would be wrong at every other.
        $status = $this->bar();

        $narrow = $status->render(new RenderContext(40, 1))[0];
        $narrowCol = self::columnOf($narrow, 'AB');
        self::assertSame('ide_problems', $status->clickAt($narrowCol));

        $wide = $status->render(new RenderContext(100, 1))[0];
        $wideCol = self::columnOf($wide, 'AB');
        self::assertNotSame($narrowCol, $wideCol, 'the right segment must actually move');
        self::assertSame('ide_problems', $status->clickAt($wideCol));
    }

    public function testAFlashRemovesEveryHitRegion(): void
    {
        // The flash takes the row: the cells the spans describe are not on
        // screen, so acting on a click there would run an unrelated action.
        $status = $this->bar();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];
        $col = self::columnOf($row, 'AB');

        $status->flash('Saved!', 5.0);
        $status->render(new RenderContext(self::COLUMNS, 1));

        self::assertNull($status->clickAt($col));
    }

    public function testAProviderWithNoClickSpansOffersNothing(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => ['left' => 'file.php', 'right' => 'AB CD']);
        $status->beforeRender();
        $status->render(new RenderContext(self::COLUMNS, 1));

        self::assertNull($status->clickAt(50));
    }
}
