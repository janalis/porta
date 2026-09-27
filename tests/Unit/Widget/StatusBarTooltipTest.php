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
 * What the status bar's cells say under the pointer, found where each one
 * RENDERED — a contributed cell, the file part, the right-hand cells — and
 * nothing where nothing was drawn.
 */
final class StatusBarTooltipTest extends TestCase
{
    private const int COLUMNS = 80;

    private function bar(string $left = 'src/file.php ●', ?string $leftTooltip = "src/file.php\nUnsaved changes"): StatusBarWidget
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => [
            'cells' => [
                ['text' => 'BR', 'menu' => null, 'tooltip' => 'the branch'],
                ['text' => 'QQ', 'menu' => null, 'tooltip' => null],
            ],
            'left' => $left,
            'leftTooltip' => $leftTooltip,
            'right' => 'AB CD',
            'rightClicks' => [
                new StatusClickSpan(0, 2, 'ide_problems', 'the problems'),
                new StatusClickSpan(3, 5, 'goto_line'),
            ],
        ]);
        $status->beforeRender();

        return $status;
    }

    private static function columnOf(string $row, string $needle): int
    {
        $at = \mb_strpos(AnsiUtils::stripAnsiCodes($row), $needle);
        self::assertNotFalse($at);

        return $at;
    }

    public function testEachCellSaysItselfWhereItRendered(): void
    {
        $status = $this->bar();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $branch = $status->tooltipAt(0, self::columnOf($row, 'BR'));
        $this->assertSame(['status:cell:0', 'the branch', 0, 1, 3], [$branch?->key, $branch?->text, $branch?->row, $branch?->colStart, $branch?->colEnd]);
        $this->assertNull($status->tooltipAt(0, self::columnOf($row, 'QQ')), 'a cell with nothing to say');

        $file = $status->tooltipAt(0, self::columnOf($row, 'file.php'));
        $this->assertSame(['status:file', "src/file.php\nUnsaved changes"], [$file?->key, $file?->text]);
        $this->assertSame(self::columnOf($row, 'src/'), $file?->colStart, 'the span is the file part as drawn');

        $problems = $status->tooltipAt(0, self::columnOf($row, 'AB'));
        $this->assertSame(['status:ide_problems', 'the problems', self::columnOf($row, 'AB')], [$problems?->key, $problems?->text, $problems?->colStart]);
        $this->assertNull($status->tooltipAt(0, self::columnOf($row, 'CD')), 'a click span with no words');
        $this->assertNull($status->tooltipAt(0, 60), 'the gap');
        $this->assertNull($status->tooltipAt(1, self::columnOf($row, 'BR')), 'the bar is one row');
    }

    /** A path elided to fit is exactly what the tooltip is for. */
    public function testACutPathIsSaidWhole(): void
    {
        $long = 'src/Some/Very/Deep/Directory/Tree/With/A/LongClassName.php';
        $status = $this->bar($long, $long);
        $row = $status->render(new RenderContext(40, 1))[0];

        $this->assertStringNotContainsString($long, AnsiUtils::stripAnsiCodes($row));
        $this->assertSame($long, $status->tooltipAt(0, self::columnOf($row, 'src/'))?->text);
    }

    public function testAFlashInTheFilesPlaceSaysNothingMore(): void
    {
        $status = $this->bar();
        $status->flash('Saved');
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertNull($status->tooltipAt(0, self::columnOf($row, 'Saved')));
    }

    /** A whole file name with nothing more to say gets no card: it would only repeat itself. */
    public function testAWholePathWithNothingMoreSaysNothing(): void
    {
        $status = $this->bar('src/file.php', 'src/file.php');
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertNull($status->tooltipAt(0, self::columnOf($row, 'file.php')));
    }

    public function testNoFileTooltipWithoutOne(): void
    {
        $status = $this->bar('porta — open a file', null);
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertNull($status->tooltipAt(0, self::columnOf($row, 'porta')));
    }
}
