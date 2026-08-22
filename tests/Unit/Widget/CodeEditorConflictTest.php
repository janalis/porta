<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\EditorState;
use Porta\Widget\CodeEditorWidget;

final class CodeEditorConflictTest extends TestCase
{
    private function editor(string $content): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('t.php', $content);
        $editor->setFocused(true);

        return $editor;
    }

    private const TWO_REGIONS = "<<<<<<< a\nx\n=======\ny\n>>>>>>> b\nmid\n<<<<<<< c\nz\n=======\nw\n>>>>>>> d";

    public function testNextConflictNavigatesAndWraps(): void
    {
        $editor = $this->editor(self::TWO_REGIONS);
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1b[18~"); // F7 -> next region (start 6)
        $this->assertSame(6, $editor->getDocument()->getCursorLine());

        $editor->handleInput("\x1b[18~"); // F7 -> wraps to first (start 0)
        $this->assertSame(0, $editor->getDocument()->getCursorLine());
    }

    public function testPrevConflictNavigatesAndWraps(): void
    {
        $editor = $this->editor(self::TWO_REGIONS);
        $editor->getDocument()->setCursor(6, 0);

        $editor->handleInput("\x1b[18;2~"); // Shift+F7 -> previous region (start 0)
        $this->assertSame(0, $editor->getDocument()->getCursorLine());

        $editor->handleInput("\x1b[18;2~"); // Shift+F7 -> wraps to last (start 6)
        $this->assertSame(6, $editor->getDocument()->getCursorLine());
    }

    public function testConflictNavigationNoopWithoutConflicts(): void
    {
        $editor = $this->editor("just\nplain\ntext");
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput("\x1b[18~");
        $this->assertSame(1, $editor->getDocument()->getCursorLine(), 'no conflicts: caret unmoved');
    }

    public function testResolveKeepOurs(): void
    {
        $editor = $this->editor("top\n<<<<<<< a\no1\no2\n=======\nt1\n>>>>>>> b\nbot");
        $editor->getDocument()->setCursor(2, 0); // inside "ours"

        $editor->handleInput("\x1bm"); // alt+m -> resolve menu
        $editor->handleInput("\r"); // accept first item (Keep Ours)

        $this->assertSame("top\no1\no2\nbot", $editor->getDocument()->getText());
    }

    public function testResolveKeepTheirs(): void
    {
        $editor = $this->editor("top\n<<<<<<< a\no1\n=======\nt1\nt2\n>>>>>>> b\nbot");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1bm");
        $editor->handleInput("\x1b[B"); // down to "Keep Theirs"
        $editor->handleInput("\r");

        $this->assertSame("top\nt1\nt2\nbot", $editor->getDocument()->getText());
    }

    public function testResolveKeepBoth(): void
    {
        $editor = $this->editor("<<<<<<< a\no1\n=======\nt1\n>>>>>>> b");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1bm");
        $editor->handleInput("\x1b[B");
        $editor->handleInput("\x1b[B"); // down to "Keep Both"
        $editor->handleInput("\r");

        $this->assertSame("o1\nt1", $editor->getDocument()->getText());
    }

    public function testResolveEmptySideDeletesRegion(): void
    {
        $editor = $this->editor("top\n<<<<<<< a\n=======\nt1\n>>>>>>> b\nbot");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1bm");
        $editor->handleInput("\r"); // Keep Ours, but "ours" is empty

        $this->assertSame("top\n\nbot", $editor->getDocument()->getText());
    }

    public function testResolveMenuNoopOutsideConflict(): void
    {
        $editor = $this->editor("top\n<<<<<<< a\no1\n=======\nt1\n>>>>>>> b\nbot");
        $editor->getDocument()->setCursor(0, 0); // "top", outside any conflict

        $editor->handleInput("\x1bm");
        $this->assertFalse(EditorState::pickerOpen($editor));
        $this->assertStringContainsString('<<<<<<< a', $editor->getDocument()->getText());
    }

    private const THREE_REGIONS = "before\n<<<<<<< a\no1\n=======\nt1\n>>>>>>> b\nmid1\n<<<<<<< c\no2\n=======\nt2\n>>>>>>> d\nmid2\n<<<<<<< e\no3\n=======\nt3\n>>>>>>> f\nafter";

    public function testNextConflictSkipsPastTheCurrentRegionThenWrapsToTheFirst(): void
    {
        // Regions start at lines 1, 7, 13. Two hops from inside the first
        // region must land on the second, not re-trigger the first (this is
        // the "dir > 0" boundary, not just its "wrap past the last" tail).
        $editor = $this->editor(self::THREE_REGIONS);
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1b[18~");
        $this->assertSame(1, $editor->getDocument()->getCursorLine());
        $editor->handleInput("\x1b[18~");
        $this->assertSame(7, $editor->getDocument()->getCursorLine(), 'skips past the current region to the next one');
        $editor->handleInput("\x1b[18~");
        $this->assertSame(13, $editor->getDocument()->getCursorLine());
        $editor->handleInput("\x1b[18~"); // past the last region: wraps
        $this->assertSame(1, $editor->getDocument()->getCursorLine());
    }

    public function testNextConflictLandsAtColumnZero(): void
    {
        $editor = $this->editor(self::TWO_REGIONS);
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1b[18~");

        $this->assertSame(0, $editor->getDocument()->getCursorCol());
    }

    public function testPrevConflictJumpsToTheClosestRegionBeforeTheCaretNotTheFirstOne(): void
    {
        // With regions before the caret at lines 1, 7 and 13, "previous" must
        // land on 13 (the closest), which a non-reversed backward scan would
        // get wrong (it would find 1 first).
        $editor = $this->editor(self::THREE_REGIONS);
        $editor->getDocument()->setCursor(18, 0); // "after", past every region

        $editor->handleInput("\x1b[18;2~");
        $this->assertSame(13, $editor->getDocument()->getCursorLine());
        $editor->handleInput("\x1b[18;2~");
        $this->assertSame(7, $editor->getDocument()->getCursorLine());
        $editor->handleInput("\x1b[18;2~");
        $this->assertSame(1, $editor->getDocument()->getCursorLine());
        $editor->handleInput("\x1b[18;2~"); // before every region: wraps to the last
        $this->assertSame(13, $editor->getDocument()->getCursorLine());
    }

    public function testGotoConflictClearsAnyExistingSelection(): void
    {
        $editor = $this->editor(self::TWO_REGIONS);
        $editor->getDocument()->setCursor(0, 0);
        $editor->getDocument()->beginSelect();
        $editor->getDocument()->setCursor(0, 3, selection: \Porta\Editor\SelectionMode::Keep);

        $editor->handleInput("\x1b[18~"); // F7

        $this->assertFalse($editor->getDocument()->hasSelection(), 'jumping to a conflict clears a stray selection');
    }

    public function testResolveMenuItemsCarryALabelKeyThePickerLayoutReadsDirectly(): void
    {
        $editor = $this->editor("top\n<<<<<<< a\no1\n=======\nt1\n>>>>>>> b\nbot");
        $editor->getDocument()->setCursor(2, 0);

        $editor->handleInput("\x1bm"); // open the resolve-conflict menu

        $host = \Porta\Tests\Support\Probe::object($editor, 'overlayHost');
        $host->layout([0, 0, 24, 80]);

        $this->assertTrue(true, 'laying out the open picker must not warn on a missing label key');
    }
}
