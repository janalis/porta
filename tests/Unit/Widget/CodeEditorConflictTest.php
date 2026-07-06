<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
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
        $this->assertFalse($editor->isOverlayOpen());
        $this->assertStringContainsString('<<<<<<< a', $editor->getDocument()->getText());
    }
}
