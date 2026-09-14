<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\StructureFollowsCaret;
use Porta\Event\DockToolBound;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;

final class StructureFollowsCaretTest extends TestCase
{
    private CodeEditorWidget $editor;
    private BottomDock $dock;
    private ResultsPanelWidget $results;

    /** @return list<array{path: string, line: int, text: string}> */
    private static function symbols(): array
    {
        return [
            ['path' => 'a.php', 'line' => 2, 'text' => 'class Demo'],
            ['path' => 'a.php', 'line' => 4, 'text' => '  greet()'],
            ['path' => 'a.php', 'line' => 9, 'text' => '  farewell()'],
        ];
    }

    private function follower(): StructureFollowsCaret
    {
        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument('/p/a.php', new Document(\str_repeat("// line\n", times: 14)));
        $this->results = new ResultsPanelWidget();
        $this->dock = TestBottomDock::create(TestTui::create(100, 24), new TerminalPanelWidget(), $this->results, new DebugPanelWidget());

        return new StructureFollowsCaret($this->editor, $this->dock);
    }

    /** The line of the row the list currently has selected — the public read. */
    private function selectedLine(): ?int
    {
        return $this->results->captureViewState()->selectedLine;
    }

    public function testTheSelectionMovesToTheSymbolEnclosingTheCaret(): void
    {
        $follower = $this->follower();
        $this->dock->showList(ResultsPanelMode::Structure, 'Structure', self::symbols());

        $this->editor->getDocument()->setCursor(6, 0); // inside greet(), which starts at 4
        $follower->caretMoved();

        $this->assertSame(4, $this->selectedLine(), 'greet(), the innermost symbol at or above the caret');

        $this->editor->getDocument()->setCursor(11, 0); // now inside farewell()
        $follower->caretMoved();

        $this->assertSame(9, $this->selectedLine(), 'farewell() now');
    }

    public function testACaretAboveEverySymbolLeavesTheSelectionAlone(): void
    {
        // The file header encloses nothing; selecting the first symbol there
        // would claim the caret is inside it.
        $follower = $this->follower();
        $this->dock->showList(ResultsPanelMode::Structure, 'Structure', self::symbols());
        $this->editor->getDocument()->setCursor(9, 0);
        $follower->caretMoved();
        $this->assertSame(9, $this->selectedLine(), 'farewell() now');

        $this->editor->getDocument()->setCursor(0, 0);
        $follower->caretMoved();

        $this->assertSame(9, $this->selectedLine(), 'unchanged, not reset to the top');
    }

    public function testNothingHappensWithNoStructureTabOnDisplay(): void
    {
        $follower = $this->follower();
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', [
            ['path' => 'a.php', 'line' => 0, 'text' => '✗ first'],
            ['path' => 'a.php', 'line' => 8, 'text' => '✗ second'],
        ]);

        $this->editor->getDocument()->setCursor(11, 0);
        $follower->caretMoved();

        $this->assertSame(0, $this->selectedLine(), 'another tool keeps its own selection');
    }

    /**
     * The parked Structure tab's selection lives in a frozen view state, not in
     * the shared widget — so following it would be writing into whichever tool
     * IS on display.
     */
    public function testAParkedStructureTabIsNotFollowed(): void
    {
        $follower = $this->follower();
        $this->dock->showList(ResultsPanelMode::Structure, 'Structure', self::symbols());
        $this->editor->getDocument()->setCursor(5, 0);
        $follower->caretMoved();
        // Park it behind another tool whose rows OVERLAP the structure's lines:
        // a leaked select would land on the Problems row at line 9, so this
        // distinguishes "guarded" from "asked the wrong widget and missed".
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', [
            ['path' => 'a.php', 'line' => 0, 'text' => '✗ first'],
            ['path' => 'a.php', 'line' => 9, 'text' => '✗ same line as farewell()'],
        ]);

        $this->editor->getDocument()->setCursor(11, 0);
        $follower->caretMoved();

        $this->assertSame(0, $this->selectedLine(), 'the displayed tool kept its own selection');
    }

    /**
     * Opening the tab while the caret is already parked inside a method must
     * select that method — the caret does not move, so a memo keyed only on
     * caret position would leave the tab showing row 0 until you typed.
     */
    public function testOpeningTheTabSelectsWhereTheCaretAlreadyIs(): void
    {
        $follower = $this->follower();
        $this->editor->getDocument()->setCursor(11, 0);
        $follower->caretMoved(); // the caret settles BEFORE the tab exists

        $tab = $this->dock->showList(ResultsPanelMode::Structure, 'Structure', self::symbols());
        $follower->onDockToolBound(new DockToolBound($tab));
        $follower->caretMoved();

        $this->assertSame(9, $this->selectedLine(), 'farewell(), without having to move the caret first');
    }

    /**
     * A caret that has not moved must not re-force the selection. Without the
     * memo this runs on every frame, so a reader who clicked another row in the
     * Structure tab would have it yanked back sixty times a second.
     */
    public function testAStationaryCaretLeavesAManuallyChosenRowAlone(): void
    {
        $follower = $this->follower();
        $this->dock->showList(ResultsPanelMode::Structure, 'Structure', self::symbols());
        $this->editor->getDocument()->setCursor(6, 0);
        $follower->caretMoved();
        $this->assertSame(4, $this->selectedLine(), 'greet(), to begin with');

        // The reader picks a different row by hand, then nothing moves the caret.
        $this->results->handleInput("\x1b[B"); // down one row
        $this->assertSame(9, $this->selectedLine(), 'they are on farewell() now');

        $follower->caretMoved();
        $follower->caretMoved();

        $this->assertSame(9, $this->selectedLine(), 'and the follower left it there');
    }
}
