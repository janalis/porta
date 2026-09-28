<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\StatusClickSpan;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;

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
        $at = \mb_strpos($plain, $needle);
        self::assertNotFalse($at, "the row should contain '{$needle}'");

        return $at;
    }

    public function testACellIsFoundAtTheColumnItRendersAt(): void
    {
        $status = $this->bar();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertSame('ide_problems', $status->clickAt(self::columnOf($row, 'AB')));
        $this->assertSame('goto_line', $status->clickAt(self::columnOf($row, 'CD')));
    }

    public function testTheGapBetweenTwoCellsBelongsToNeither(): void
    {
        $status = $this->bar();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertNull($status->clickAt(self::columnOf($row, 'AB') + 2));
    }

    public function testTheLeftSideAndTheEmptyGapAreNotClickable(): void
    {
        $status = $this->bar();
        $status->render(new RenderContext(self::COLUMNS, 1));

        $this->assertNull($status->clickAt(0));
        $this->assertNull($status->clickAt(3));
    }

    public function testTheSpansFollowTheRightSegmentWhenTheTerminalIsResized(): void
    {
        // The gap absorbs the width change, so an absolute column baked in at
        // one width would be wrong at every other.
        $status = $this->bar();

        $narrow = $status->render(new RenderContext(40, 1))[0];
        $narrowCol = self::columnOf($narrow, 'AB');
        $this->assertSame('ide_problems', $status->clickAt($narrowCol));

        $wide = $status->render(new RenderContext(100, 1))[0];
        $wideCol = self::columnOf($wide, 'AB');
        $this->assertNotSame($narrowCol, $wideCol, 'the right segment must actually move');
        $this->assertSame('ide_problems', $status->clickAt($wideCol));
    }

    /**
     * A flash takes the LEFT; the right-hand cells are still drawn, so they
     * still answer — at the columns they are drawn at now. They used to go
     * dead for as long as any message showed.
     */
    public function testAFlashKeepsTheRightCellsItStillDraws(): void
    {
        $status = $this->bar();
        $status->flash('Saved!', 5.0);

        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertSame('ide_problems', $status->clickAt(self::columnOf($row, 'AB')));
        $this->assertSame('goto_line', $status->clickAt(self::columnOf($row, 'CD')));
    }

    /** A cut right side keeps only the cells that end before its ellipsis. */
    public function testACutRightKeepsOnlyTheCellsDrawnWhole(): void
    {
        $status = $this->bar(left: \str_repeat('L', times: 30), right: 'AB CDXY');
        $status->flash(\str_repeat('F', times: 10), 5.0); // `◦ FFFFFFFFFF`: 12 columns, leaving the right 5

        $row = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(20, 1))[0]);

        // CD's span, [3,5), ends exactly at the cut right's width — but its
        // last column is the ellipsis, so it is not drawn whole.
        $this->assertStringContainsString('AB C…', $row);
        $this->assertSame('ide_problems', $status->clickAt(self::columnOf($row, 'AB')));
        $this->assertNull($status->clickAt(self::columnOf($row, 'AB') + 3), 'CD was cut: its half and the ellipsis are no target');
    }

    public function testAProviderWithNoClickSpansOffersNothing(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => ['left' => 'file.php', 'right' => 'AB CD']);
        $status->beforeRender();
        $status->render(new RenderContext(self::COLUMNS, 1));

        $this->assertNull($status->clickAt(50));
    }

    /**
     * The status bar naming $path, drawn as `src/a.php` and then a caret
     * diagnostic, behind the contributed $cells.
     *
     * @param list<array{text: string, menu: null}> $cells
     */
    private function fileBar(?string $path, array $cells = []): StatusBarWidget
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => [
            'cells' => $cells,
            'left' => 'src/a.php  boom',
            'leftPath' => $path,
            'leftPathWidth' => 9,
            'right' => 'AB',
        ]);
        $status->beforeRender();

        return $status;
    }

    public function testTheFileIsFoundOnItsPathAndNotOnTheDiagnosticAfterIt(): void
    {
        $status = $this->fileBar('/p/src/a.php');
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];
        $at = self::columnOf($row, 'src/a.php');

        $this->assertNull($status->fileAt($at - 1), 'the leading space');
        $this->assertSame('/p/src/a.php', $status->fileAt($at));
        $this->assertSame('/p/src/a.php', $status->fileAt($at + 8), 'its last column');
        $this->assertNull($status->fileAt($at + 9));
        $this->assertNull($status->fileAt(self::columnOf($row, 'boom')), 'the diagnostic names no file');
    }

    public function testTheFileSpanFollowsTheCellsAheadOfIt(): void
    {
        $status = $this->fileBar('/p/src/a.php', [['text' => 'main', 'menu' => null]]);
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];

        $this->assertNull($status->fileAt(self::columnOf($row, 'main')));
        $this->assertSame('/p/src/a.php', $status->fileAt(self::columnOf($row, 'src/a.php')));
    }

    public function testAPathCutToFitIsStillTheFileButOnlyWhereDrawn(): void
    {
        // 12 columns leave the left 7, narrower than the 9-column path: the
        // span ends where the cut text does, not where the whole path would.
        $status = $this->fileBar('/p/src/a.php');
        $row = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(12, 1))[0]);
        $this->assertSame(1 + 7 + 1, \mb_strpos($row, needle: 'AB'), 'the leading space, the left cut to 7, a one-column gap');

        $this->assertSame('/p/src/a.php', $status->fileAt(7), 'its last drawn column');
        $this->assertNull($status->fileAt(8), 'past the cut path');
    }

    public function testNoFileIsNamedByThePromptOrUnderAFlash(): void
    {
        $prompt = $this->fileBar(null);
        $prompt->render(new RenderContext(self::COLUMNS, 1));
        $this->assertNull($prompt->fileAt(1), 'the open-a-file prompt');

        $status = $this->fileBar('/p/src/a.php');
        $status->flash('Saved!', 5.0);
        $status->render(new RenderContext(self::COLUMNS, 1));
        $this->assertNull($status->fileAt(1), 'a flash has the left');
    }

    public function testANewFileBehindTheSameTextMovesTheSpan(): void
    {
        // Through the renderer, which serves a widget's cached lines unless
        // it was invalidated: the drawn text does not change, so only the
        // path can say the span is stale.
        $path = '/p/src/a.php';
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static function () use (&$path): array {
            return ['left' => 'src/a.php', 'leftPath' => $path, 'leftPathWidth' => 9, 'right' => ''];
        });
        $renderer = new Renderer(new StyleSheet());
        $renderer->renderWidgetLines($status, new RenderContext(self::COLUMNS, 1));
        $this->assertSame('/p/src/a.php', $status->fileAt(1));

        $path = '/q/src/a.php';
        $renderer->renderWidgetLines($status, new RenderContext(self::COLUMNS, 1));

        $this->assertSame('/q/src/a.php', $status->fileAt(1));
    }
}
