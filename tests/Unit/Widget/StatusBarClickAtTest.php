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

    /** `$path  ✗ $problem` with the problem's words clickable, as the provider reports it. */
    private function barWithAProblem(string $path = 'src/A.php', string $problem = 'boom'): StatusBarWidget
    {
        $from = AnsiUtils::visibleWidth($path) + 2;
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => [
            'left' => $path,
            'leftNote' => '✗ '.$problem,
            'leftPath' => '/p/'.$path,
            'leftPathWidth' => AnsiUtils::visibleWidth($path),
            'leftClicks' => [new StatusClickSpan($from, $from + 2 + \mb_strlen($problem), 'ide_reveal_caret_problem', "✗ {$problem}\nClick to show it in the Problems panel")],
            'right' => 'AB',
        ]);
        $status->beforeRender();

        return $status;
    }

    /** The caret's problem, after the path, opens it in the Problems panel; the path stays the file. */
    public function testTheCaretProblemOnTheLeftIsClickable(): void
    {
        $status = $this->barWithAProblem();
        $row = $status->render(new RenderContext(self::COLUMNS, 1))[0];
        $at = self::columnOf($row, '✗ boom');

        $this->assertSame('ide_reveal_caret_problem', $status->clickAt($at));
        $this->assertSame('ide_reveal_caret_problem', $status->clickAt($at + 5), 'its last cell');
        $this->assertNull($status->clickAt($at + 6), 'not past it');
        $this->assertNull($status->clickAt($at - 1), 'not the gap before it');
        $this->assertSame('/p/src/A.php', $status->fileAt(self::columnOf($row, 'src/A.php')), 'the path is still the file');
        $this->assertSame("✗ boom\nClick to show it in the Problems panel", $status->tooltipAt(0, $at)?->text, 'its card says what a click does');
    }

    public function testTheCaretProblemUnderlinesUnderThePointer(): void
    {
        $status = $this->barWithAProblem();
        $at = self::columnOf($status->render(new RenderContext(self::COLUMNS, 1))[0], '✗ boom');

        $this->assertTrue($status->hoverAt($at + 2));

        $this->assertStringContainsString("\e[4m✗ boom", $status->render(new RenderContext(self::COLUMNS, 1))[0]);
    }

    /**
     * Short of room, the path and the problem are cut apart: the path in the
     * middle, keeping the file's name and its ●, and the problem at its tail,
     * keeping what it is. One middle cut through both drew
     * `src/Editor/BufferNavigator…n a variable, expected {`.
     */
    public function testAPathAndItsProblemAreCutApart(): void
    {
        // 45 columns leave the left 40: the path its floor of 24, the
        // problem 14 after the two-space gap.
        $status = $this->barWithAProblem('src/Editor/BufferNavigator.php ●', 'Syntax error: unexpected token');
        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(45, 1))[0]);

        $this->assertSame(' src/Editor/…igator.php ●  ✗ Syntax erro…', \rtrim(\mb_substr($plain, start: 0, length: \mb_strpos($plain, needle: 'AB'))));
        $this->assertSame('/p/src/Editor/BufferNavigator.php ●', $status->fileAt(1), 'the path is still the file');
        $at = \mb_strpos($plain, needle: '✗');
        $this->assertNotFalse($at);
        $this->assertSame('ide_reveal_caret_problem', $status->clickAt($at), 'the problem moved with the cut path');
        $this->assertSame('ide_reveal_caret_problem', $status->clickAt($at + 13), 'its mark, the last of it drawn');
        $this->assertNull($status->clickAt($at + 14), 'not the gap after it');
        $this->assertNull($status->clickAt($at - 1), 'not the gap before it');
    }

    /** Too narrow for the problem's glyph and its mark, the path has the room, cut in the middle as when alone. */
    public function testAProblemWithNoRoomLeavesThePathTheLeft(): void
    {
        // 31 columns leave the left 26: past the floor and the gap, the problem 0.
        $status = $this->barWithAProblem('src/Editor/BufferNavigator.php ●', 'Syntax error');
        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(31, 1))[0]);

        $this->assertStringNotContainsString('✗', $plain);
        $this->assertStringContainsString(' src/Editor/B…vigator.php ● ', $plain);
        $this->assertNull($status->clickAt(\mb_strpos($plain, needle: '●') + 2), 'nothing is left to click');

        // One more column of room and the problem shows: its glyph and its mark.
        $plain = AnsiUtils::stripAnsiCodes($this->barWithAProblem('src/Editor/BufferNavigator.php ●', 'Syntax error')->render(new RenderContext(33, 1))[0]);
        $this->assertStringContainsString('●  ✗…', $plain);
    }

    /**
     * The path gives way only as far as the problem needs: down from 41
     * columns to 33, not to its floor, and the problem shows whole.
     */
    public function testAPathGivesAProblemOnlyTheRoomItNeeds(): void
    {
        // 50 columns leave the left 45: 33 for the path, 2 for the gap, 10 for the problem.
        $status = $this->barWithAProblem('src/Some/Deep/Directory/LongClassName.php', '12345678');
        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(50, 1))[0]);

        $this->assertSame(' src/Some/Deep/Di…ongClassName.php  ✗ 12345678', \rtrim(\mb_substr($plain, start: 0, length: \mb_strpos($plain, needle: 'AB'))));
    }

    /** A span over part of the note moves with the path's cut and ends where it did: here the glyph alone. */
    public function testANoteSpanMovesWithTheCutPathAndKeepsItsEnd(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => [
            'left' => 'src/Some/Deep/Directory/LongClassName.php',
            'leftNote' => '✗ 12345678',
            'leftClicks' => [new StatusClickSpan(43, 44, 'glyph')],
            'right' => 'AB',
        ]);
        $status->beforeRender();
        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(50, 1))[0]);
        $at = \mb_strpos($plain, needle: '✗');
        $this->assertNotFalse($at);

        $this->assertSame('glyph', $status->clickAt($at));
        $this->assertNull($status->clickAt($at + 1), 'the space after it');
    }

    /** A new problem behind the same file part is drawn: the note alone says the row is stale. */
    public function testANewProblemBehindTheSamePathIsDrawn(): void
    {
        $note = '✗ boom';
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static function () use (&$note): array {
            return ['left' => 'src/a.php', 'leftNote' => $note, 'right' => ''];
        });
        $renderer = new Renderer(new StyleSheet());
        $this->assertStringContainsString('✗ boom', AnsiUtils::stripAnsiCodes($renderer->renderWidgetLines($status, new RenderContext(self::COLUMNS, 1))->getLine(0)));

        $note = '✗ bang';

        $this->assertStringContainsString('✗ bang', AnsiUtils::stripAnsiCodes($renderer->renderWidgetLines($status, new RenderContext(self::COLUMNS, 1))->getLine(0)));
    }

    /** A flash has the left's place, and the problem's click goes with it. */
    public function testAFlashTakesTheProblemsClick(): void
    {
        $status = $this->barWithAProblem();
        $status->flash('Saved');
        $row = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(self::COLUMNS, 1))[0]);

        $this->assertStringNotContainsString('boom', $row);
        foreach (\range(0, self::COLUMNS - 4) as $column) {
            $this->assertNull($status->clickAt($column), "column {$column} is the flash's, or the gap");
        }
    }

    /** Only the note's spans are clicks: one over the file part is not offered — the file part's click is the file. */
    public function testASpanOverTheFilePartIsNotOffered(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn (): array => [
            'left' => 'src/a.php',
            'leftNote' => '✗ boom',
            // From the file part's end on: the gap and the note.
            'leftClicks' => [new StatusClickSpan(0, 3, 'file'), new StatusClickSpan(9, 17, 'note')],
            'right' => 'AB',
        ]);
        $status->beforeRender();
        $status->render(new RenderContext(self::COLUMNS, 1));

        $this->assertNull($status->clickAt(1), 'the file part');
        $this->assertSame('note', $status->clickAt(1 + 9), 'the gap after it belongs to the note');
        $this->assertSame('note', $status->clickAt(1 + 16), 'its last column');
        $this->assertNull($status->clickAt(1 + 17));
    }
}
