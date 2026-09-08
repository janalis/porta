<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\TreeFileActivated;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Ui\ClickIntent;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Selecting more than one file in the tree: the gestures, what they leave
 * selected, and what a rebuild does to it.
 *
 * A pair is what "compare these two files" acts on, so every case here is
 * really the same question — after this gesture, WHICH files would the command
 * see?
 */
final class FileTreeMultiSelectTest extends TestCase
{
    use RendersWidget;

    private function tree(EventDispatcher $events = new EventDispatcher()): FileTreeWidget
    {
        // Three files, no directories: rows 1..3 under the header.
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n", 'c.php' => "<?php\n"]);
        $this->project = $project;

        return new FileTreeWidget($project->dir, events: $events);
    }

    private TempProject $project;

    public function testAPlainClickSelectsExactlyOneFileAndOpensIt(): void
    {
        $opened = [];
        $events = new EventDispatcher();
        $events->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened[] = basename($event->path);
        });
        $tree = $this->tree($events);

        $tree->clickRow(1);

        $this->assertSame(['a.php'], array_map(basename(...), $tree->selectedPaths()));
        $this->assertSame(['a.php'], $opened, 'a plain click still opens the file');
    }

    public function testCtrlClickAddsAFileWithoutOpeningIt(): void
    {
        $opened = [];
        $events = new EventDispatcher();
        $events->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened[] = basename($event->path);
        });
        $tree = $this->tree($events);

        $tree->clickRow(1);
        $tree->clickRow(3, ClickIntent::Add);

        $this->assertSame(['a.php', 'c.php'], array_map(basename(...), $tree->selectedPaths()));
        $this->assertSame(['a.php'], $opened, 'the modified click selects, it does not open — the editor is what you compare against');
    }

    public function testCtrlClickingASelectedFileTakesItBackOut(): void
    {
        $tree = $this->tree();

        $tree->clickRow(1);
        $tree->clickRow(2, ClickIntent::Add);
        $tree->clickRow(2, ClickIntent::Add);

        $this->assertSame(['a.php'], array_map(basename(...), $tree->selectedPaths()));
    }

    public function testShiftClickTakesTheWholeRangeFromTheLastOne(): void
    {
        $tree = $this->tree();

        $tree->clickRow(1);
        $tree->clickRow(3, ClickIntent::Range);

        $this->assertSame(['a.php', 'b.php', 'c.php'], array_map(basename(...), $tree->selectedPaths()));
    }

    public function testAShiftClickBackUpShrinksTheRangeRatherThanGrowingIt(): void
    {
        $tree = $this->tree();

        $tree->clickRow(1);
        $tree->clickRow(3, ClickIntent::Range);
        $tree->clickRow(2, ClickIntent::Range);

        $this->assertSame(['a.php', 'b.php'], array_map(basename(...), $tree->selectedPaths()), 'the range is replaced, not accumulated');
    }

    public function testSelectedPathsComeBackInTreeOrderHoweverTheyWerePicked(): void
    {
        // "The first file" has to mean the same thing whichever way the pair
        // was built, or a compare silently swaps its sides.
        $tree = $this->tree();

        $tree->clickRow(3);
        $tree->clickRow(1, ClickIntent::Add);

        $this->assertSame(['a.php', 'c.php'], array_map(basename(...), $tree->selectedPaths()));
    }

    public function testShiftArrowsExtendTheSelectionAndAPlainArrowDropsBackToOne(): void
    {
        $tree = $this->tree();
        $tree->setFocused(true);

        $tree->handleInput("\x1b[1;2B"); // shift+down
        $this->assertSame(['a.php', 'b.php'], array_map(basename(...), $tree->selectedPaths()), 'the cursor started on a.php');

        $tree->handleInput("\x1b[1;2B");
        $this->assertSame(['a.php', 'b.php', 'c.php'], array_map(basename(...), $tree->selectedPaths()));

        $tree->handleInput("\x1b[A"); // a plain up-arrow
        $this->assertSame(['b.php'], array_map(basename(...), $tree->selectedPaths()), 'walking the tree with no modifier is not a way to build a pair by accident');
    }

    public function testAFileThatLeavesTheTreeLeavesTheSelection(): void
    {
        $tree = $this->tree();
        $tree->clickRow(1);
        $tree->clickRow(2, ClickIntent::Add);

        unlink($this->project->path('b.php'));
        $tree->refresh();

        $this->assertSame(['a.php'], array_map(basename(...), $tree->selectedPaths()), 'a deleted file cannot stay half of a pair');
    }

    public function testASelectedRowThatIsNotTheCursorIsVisiblyMarked(): void
    {
        // The pair has to be READABLE: without a style of its own the second
        // file is selected and looks exactly like every other row.
        $tree = $this->tree();
        AttachedStyles::attach($tree); // the selection shades resolve through the themed sheet
        $tree->setFocused(true);
        $tree->clickRow(1);
        $tree->clickRow(3, ClickIntent::Add);

        $lines = $tree->render(new RenderContext(30, 10));
        $cursorRow = $lines[1];
        $selectedRow = $lines[3];
        $plainRow = $lines[2];

        $this->assertNotSame(self::style($plainRow), self::style($selectedRow), 'the second file of the pair is styled');
        $this->assertNotSame(self::style($cursorRow), self::style($selectedRow), 'and not styled as the cursor row, which is somewhere else');
    }

    /** The leading escape sequence of a rendered row, which is its style. */
    private static function style(string $line): string
    {
        preg_match('/^(?:\x1b\[[0-9;]*m)*/', $line, $matches);

        return $matches[0] ?? '';
    }
}
