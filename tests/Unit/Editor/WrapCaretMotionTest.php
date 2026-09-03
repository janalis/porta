<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Down from a wrapped line steps through its segments before leaving it.
 * Driven through the widget because the width is a RENDER-time value — the
 * caret cannot move by visual row until something has been rendered.
 */
final class WrapCaretMotionTest extends TestCase
{
    private const string DOWN = "\x1b[B";
    private const string UP = "\x1b[A";

    private CodeEditorWidget $editor;

    /** A 60-column line between two short ones, rendered ~25 columns wide. */
    private function editor(bool $wrap): CodeEditorWidget
    {
        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument('/p/a.php', new Document("first\n".str_repeat('x', times: 60)."\nlast\n"));
        $this->editor->setFocused(true);
        $this->editor->display()->setSoftWrap($wrap);
        $this->editor->render(new RenderContext(30, 12)); // establishes the content width

        return $this->editor;
    }

    /** @return array{int, int} line, display column */
    private function caret(): array
    {
        $document = $this->editor->getDocument();

        return [$document->getCursorLine(), $document->getCursorDisplayCol()];
    }

    public function testDownStepsThroughAWrappedLineSegmentBySegment(): void
    {
        $editor = $this->editor(wrap: true);
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput(self::DOWN);
        [$line, $column] = $this->caret();
        $this->assertSame(1, $line, 'into the long line');
        $this->assertSame(0, $column, 'at its first segment');

        $editor->handleInput(self::DOWN);
        [$line, $second] = $this->caret();
        $this->assertSame(1, $line, 'still the same document line');
        $this->assertGreaterThan(0, $second, 'but a segment further along');

        $editor->handleInput(self::DOWN);
        [$line, $third] = $this->caret();
        $this->assertSame(1, $line);
        $this->assertGreaterThan($second, $third, 'and further again');
    }

    public function testDownEventuallyLeavesTheLineForTheNextOne(): void
    {
        $editor = $this->editor(wrap: true);
        $editor->getDocument()->setCursor(1, 0);

        // Press Down until the document line changes: however many segments the
        // rendered width produced, the line it lands on is the one below.
        $steps = 0;
        while (1 === $this->caret()[0] && $steps < 20) {
            $editor->handleInput(self::DOWN);
            ++$steps;
        }

        $this->assertSame(2, $this->caret()[0], 'the segments are traversed, then the next line');
        $this->assertGreaterThan(1, $steps, 'and it took more than one Down, which is the point of wrapping');
    }

    /** And back up through the same segments. */
    public function testUpStepsBackThroughTheSegments(): void
    {
        $editor = $this->editor(wrap: true);
        $editor->getDocument()->setCursor(2, 0);

        $editor->handleInput(self::UP);
        [$line, $column] = $this->caret();
        $this->assertSame(1, $line, 'into the long line from below');
        $this->assertGreaterThan(0, $column, 'at its LAST segment, not its first');

        $editor->handleInput(self::UP);
        $this->assertSame(1, $this->caret()[0], 'still inside it');
        $this->assertLessThan($column, $this->caret()[1]);
    }

    /** With wrap off nothing changes: one Down is one document line. */
    public function testWithoutWrapDownIsStillOneDocumentLine(): void
    {
        $editor = $this->editor(wrap: false);
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput(self::DOWN);
        $this->assertSame(1, $this->caret()[0]);

        $editor->handleInput(self::DOWN);
        $this->assertSame(2, $this->caret()[0], 'the long line is one row, so one Down leaves it');
    }

    /**
     * Leaving one wrapped line for another lands in the NEW line's first
     * segment, not at the column the old one was carrying — otherwise Down
     * from segment three appears to skip the next line's first two rows.
     */
    public function testDownOntoAnotherWrappedLineLandsInItsFirstSegment(): void
    {
        $editor = new CodeEditorWidget();
        $long = str_repeat('x', times: 60);
        $editor->showDocument('/p/a.php', new Document("{$long}\n{$long}\n"));
        $editor->setFocused(true);
        $editor->display()->setSoftWrap(true);
        $editor->render(new RenderContext(30, 12));
        $this->editor = $editor;

        // Walk to the last segment of the first line, then one more Down.
        while (0 === $this->caret()[0]) {
            $editor->handleInput(self::DOWN);
        }

        [$line, $column] = $this->caret();
        $this->assertSame(1, $line, 'onto the second long line');
        $this->assertLessThan($editor->getDocument()->lineDisplayWidth(1), $column);
        $this->assertLessThan(30, $column, 'in its first segment, not carrying the old column across');
    }
}
