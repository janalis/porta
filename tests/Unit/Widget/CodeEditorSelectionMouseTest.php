<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditorScroll;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Render\LineDecorator;
use Symfony\Component\Tui\Render\RenderContext;

final class CodeEditorSelectionMouseTest extends TestCase
{
    private function editor(string $content): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', $content);
        $editor->setFocused(true);
        $editor->render(new RenderContext(80, 20)); // establish gutter width + viewport

        return $editor;
    }

    private function gutter(CodeEditorWidget $editor): int
    {
        return new \ReflectionProperty($editor, 'lastGutterWidth')->getValue($editor);
    }

    public function testDragSelectsARange(): void
    {
        $editor = $this->editor("hello world\nsecond line\n");
        $g = $this->gutter($editor);

        $editor->beginSelectionAt(0, $g); // press at column 0
        $editor->extendSelectionTo(0, $g + 5); // drag to column 5

        $this->assertTrue($editor->getDocument()->hasSelection());
        $this->assertSame('hello', $editor->getDocument()->getSelectedText());
    }

    public function testMultiLineDrag(): void
    {
        $editor = $this->editor("abc\ndef\nghi\n");
        $g = $this->gutter($editor);

        $editor->beginSelectionAt(0, $g + 1); // inside line 0
        $editor->extendSelectionTo(2, $g + 1); // down to line 2

        $this->assertSame("bc\ndef\ng", $editor->getDocument()->getSelectedText());
    }

    public function testPressReleaseWithoutDragLeavesNoSelection(): void
    {
        $editor = $this->editor("hello world\n");
        $g = $this->gutter($editor);

        $editor->beginSelectionAt(0, $g + 3);
        $editor->extendSelectionTo(0, $g + 3); // released at the same cell

        $this->assertFalse($editor->getDocument()->hasSelection());
        $this->assertSame(3, $editor->getDocument()->getCursorCol());
    }

    public function testSelectWord(): void
    {
        $editor = $this->editor("hello world\n");
        $g = $this->gutter($editor);

        $editor->selectWordAt(0, $g + 2);

        $this->assertSame('hello', $editor->getDocument()->getSelectedText());
    }

    public function testSelectWordOffWordPlacesCaret(): void
    {
        $editor = $this->editor("  x\n");
        $g = $this->gutter($editor);

        $editor->selectWordAt(0, $g); // column 0 is a space

        $this->assertFalse($editor->getDocument()->hasSelection());
    }

    public function testSelectLine(): void
    {
        $editor = $this->editor("hello world\nsecond\n");
        $g = $this->gutter($editor);

        $editor->selectLineAt(0, $g + 4);

        $this->assertSame('hello world', $editor->getDocument()->getSelectedText());
    }

    public function testExtendFromCaretAnchorsAtTheCursor(): void
    {
        $editor = $this->editor("hello world\n");
        $g = $this->gutter($editor);

        $editor->clickAt(0, $g); // plain caret at column 0 (no anchor)
        $editor->extendSelectionTo(0, $g + 5); // Shift+click analogue

        $this->assertSame('hello', $editor->getDocument()->getSelectedText());
    }

    public function testSelectionHonoursVerticalScroll(): void
    {
        $editor = $this->editor("l0\nl1\nl2\nl3\nl4\nl5\n");
        $g = $this->gutter($editor);
        $editor->scrollBy(2); // viewport now starts at document line 2

        $editor->selectLineAt(0, $g); // screen row 0 -> document line 2

        $this->assertSame('l2', $editor->getDocument()->getSelectedText());
    }

    // Regression: a forward (left-to-right) selection ends where the caret sits.
    // Splitting the caret line at that column used to re-open the selection
    // background after the caret, bleeding the highlight to end of line.
    public function testForwardSelectionHighlightStopsAtCaret(): void
    {
        $editor = $this->editor('hello world');
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 5, keepSelection: true); // caret at the selection END

        $line = $editor->render(new RenderContext(80, 3))[0];

        $this->assertSame(1, substr_count($line, "\x1b[48;5;24m"), 'selection background must open once, not re-open past the caret');
        $this->assertStringContainsString("\x1b_pi:c", $line, 'caret marker present');
    }

    public function testBackwardSelectionHighlightIntact(): void
    {
        $editor = $this->editor('hello world');
        $doc = $editor->getDocument();
        $doc->setCursor(0, 5);
        $doc->beginSelect();
        $doc->setCursor(0, 0, keepSelection: true); // caret at the selection START

        $line = $editor->render(new RenderContext(80, 3))[0];

        $this->assertSame(1, substr_count($line, "\x1b[48;5;24m"));
    }

    public function testForwardSelectionHighlightWithHorizontalScroll(): void
    {
        $editor = $this->editor(str_repeat('x', 100));
        $doc = $editor->getDocument();
        $doc->setCursor(0, 90);
        $doc->beginSelect();
        $doc->setCursor(0, 95, keepSelection: true); // caret far right forces hScroll > 0

        $line = $editor->render(new RenderContext(80, 3))[0];

        $scroll = new \ReflectionProperty($editor, 'scroll')->getValue($editor);
        \assert($scroll instanceof EditorScroll);
        $this->assertGreaterThan(0, $scroll->hScroll(), 'a caret near end-of-line scrolls horizontally');
        $this->assertSame(1, substr_count($line, "\x1b[48;5;24m"));
    }

    public function testAltClickAddsThenRemovesCaret(): void
    {
        $editor = $this->editor("hello\nworld\n");
        $g = $this->gutter($editor);
        $editor->getDocument()->setCursor(0, 0);

        $editor->addOrRemoveCaretAt(1, $g + 2); // add a caret on line 1 (becomes primary)
        $this->assertTrue($editor->hasMultipleCarets());
        $this->assertCount(1, $editor->getExtraCarets());

        $editor->addOrRemoveCaretAt(1, $g + 2); // click it again -> remove (promote the extra)
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testAltClickRemovesAnExtraCaret(): void
    {
        $editor = $this->editor("hello\nworld\n");
        $g = $this->gutter($editor);
        $editor->getDocument()->setCursor(0, 0);

        $editor->addOrRemoveCaretAt(1, $g); // primary=(1,0), extra=(0,0)
        $editor->addOrRemoveCaretAt(0, $g); // click the extra at (0,0) -> remove it
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testAltClickNeverRemovesTheOnlyCaret(): void
    {
        $editor = $this->editor("hello\n");
        $g = $this->gutter($editor);
        $editor->getDocument()->setCursor(0, 2);

        $editor->addOrRemoveCaretAt(0, $g + 2); // clicks the sole caret -> no-op
        $this->assertFalse($editor->hasMultipleCarets());
        $this->assertSame(2, $editor->getDocument()->getCursorCol());
    }

    public function testColumnSelectionPastShortLinesShowsEndOfLineCarets(): void
    {
        $editor = $this->editor("ab\nlongline\ncd\n");
        $editor->render(new RenderContext(80, 6));
        $g = $this->gutter($editor);
        // Drag a column whose right edge (display col 6) exceeds the short lines.
        $editor->beginColumnSelectionAt(0, $g + 4);
        $editor->extendColumnSelectionTo(2, $g + 6);

        $rendered = $editor->render(new RenderContext(80, 6));

        // Short lines 0 and 2 have empty (clamped) selections but must still show
        // a caret at their end-of-line (clamped), rendered as a reverse-video cell.
        $this->assertSame(1, substr_count($rendered[0], "\x1b[7m"), 'caret at end of short line 0');
        $this->assertSame(1, substr_count($rendered[2], "\x1b[7m"), 'caret at end of short line 2');
    }

    public function testColumnSelectionRendersPerCaretHighlight(): void
    {
        $editor = $this->editor("aaaa\nbbbb\ncccc\n");
        $editor->render(new RenderContext(80, 6));
        $g = $this->gutter($editor);
        $editor->beginColumnSelectionAt(0, $g + 1);
        $editor->extendColumnSelectionTo(2, $g + 3);

        $rendered = $editor->render(new RenderContext(80, 6));
        // The extra carets (rows 0,1) each draw a selection background + a caret cell.
        $this->assertGreaterThanOrEqual(2, substr_count(implode('', $rendered), "\x1b[48;5;24m"));
        $this->assertStringContainsString("\x1b[7m", implode('', $rendered));
    }
}
