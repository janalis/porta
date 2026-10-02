<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Event\WorkingTreeChanged;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Tui;

/**
 * The file tree's verbs from the keyboard: Delete (asked first, then gone —
 * the row under the cursor, or every selected file with it), New… and Rename.
 */
final class TreeKeyboardTest extends TestCase
{
    use BootsIde;

    private const string DELETE = "\x1b[3~";

    private const string UP = "\x1b[A";

    private const string DOWN = "\x1b[B";

    private const string SHIFT_DOWN = "\x1b[1;2B";

    private const string ALT_INSERT = "\x1b[2;3~";

    private const string CTRL_N = "\x0e";

    private const string SHIFT_F6 = "\x1b[17;2~";

    /** The kitty-protocol form: a plain terminal sends Ctrl+Shift+letter as Ctrl+letter. */
    private const string CTRL_SHIFT_C = "\x1b[99;6u";

    protected function setUp(): void
    {
        $this->bootIde(['composer.json' => '{}', 'a.php' => "<?php\n", 'b.php' => "<?php\n", 'c.php' => "<?php\n", 'src/A.php' => "<?php\n"]);
        $this->tui()->setFocus($this->tree());
    }

    public function testDeleteAsksThenDeletesTheFileUnderTheCursor(): void
    {
        $this->walkTo('a.php');

        $this->tui()->handleInput(self::DELETE);
        $this->assertTrue($this->editor()->isAnyPickerOpen(), 'asked first');
        $this->assertFileExists($this->project->path('a.php'));
        $this->type("y\r");

        $this->assertFileDoesNotExist($this->project->path('a.php'));
        $this->assertFileExists($this->project->path('b.php'));
        $this->assertSame($this->tree(), $this->tui()->getFocus(), 'back in the tree for the next one');
    }

    public function testAnythingButYesKeepsIt(): void
    {
        $this->walkTo('a.php');

        $this->tui()->handleInput(self::DELETE);
        $this->type("n\r");

        $this->assertFileExists($this->project->path('a.php'));
        $this->assertSame($this->tree(), $this->tui()->getFocus());
    }

    public function testEverySelectedFileGoesWithOneQuestionAndOneAnnouncement(): void
    {
        $announced = 0;
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(WorkingTreeChanged::class, static function () use (&$announced): void {
            ++$announced;
        });
        $this->walkTo('a.php');
        $this->tui()->handleInput(self::SHIFT_DOWN);
        $this->tui()->handleInput(self::SHIFT_DOWN);

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        $this->assertFileDoesNotExist($this->project->path('a.php'));
        $this->assertFileDoesNotExist($this->project->path('b.php'));
        $this->assertFileDoesNotExist($this->project->path('c.php'));
        $this->assertSame(1, $announced, 'git hears of the set once');
    }

    public function testAFolderGoesAlone(): void
    {
        $this->walkTo('src');

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        $this->assertDirectoryDoesNotExist($this->project->path('src'));
        $this->assertFileExists($this->project->path('a.php'));
    }

    public function testInTheEditorDeleteIsTheEditorsOwn(): void
    {
        $this->walkTo('a.php');
        $this->tui()->setFocus($this->editor());

        $this->tui()->handleInput(self::DELETE);

        $this->assertFalse($this->editor()->isAnyPickerOpen(), 'no question: the tree is not where the keys go');
        $this->assertFileExists($this->project->path('a.php'));
    }

    public function testAltInsertOffersANewFileInTheSelectedFolder(): void
    {
        $this->walkTo('src');

        $this->tui()->handleInput(self::ALT_INSERT);
        $this->assertTrue($this->editor()->isAnyPickerOpen(), 'what to create, asked');
        $this->type("\r"); // New File…
        $this->type("B.php\r");

        $this->assertFileExists($this->project->path('src/B.php'), 'in the selected folder');
    }

    public function testNewFileOnATakenNameRefusesInsteadOfEmptyingIt(): void
    {
        \file_put_contents($this->project->path('src/A.php'), data: "<?php\necho 1;\n");
        $this->walkTo('src');

        $this->tui()->handleInput(self::ALT_INSERT);
        $this->type("\r"); // New File…
        $this->type("A.php\r");

        $this->assertSame("<?php\necho 1;\n", \file_get_contents($this->project->path('src/A.php')), 'the existing file keeps its content');
        $this->assertStringStartsWith('Cannot create src/A.php', Toasts::last($this->ide));
    }

    public function testCtrlNOffersANewFolderToo(): void
    {
        $this->walkTo('a.php');

        $this->tui()->handleInput(self::CTRL_N);
        $this->tui()->handleInput(self::DOWN);
        $this->type("\r"); // New Folder…
        $this->type("lib\r");

        $this->assertDirectoryExists($this->project->path('lib'));
    }

    public function testAnEmptyTreeCreatesAtTheProjectRoot(): void
    {
        Probe::set($this->tree(), 'visible', []);

        $this->tui()->handleInput(self::CTRL_N);
        $this->type("\rroot.php\r");

        $this->assertFileExists($this->project->path('root.php'));
    }

    public function testShiftF6RenamesTheRowUnderTheCursor(): void
    {
        $this->walkTo('b.php');

        $this->tui()->handleInput(self::SHIFT_F6);
        $this->type("x\r"); // the prompt starts from the old name

        $this->assertFileDoesNotExist($this->project->path('b.php'));
        $this->assertFileExists($this->project->path('b.phpx'));
    }

    public function testRenameOrDeleteWithNothingSelectedDoesNothing(): void
    {
        Probe::set($this->tree(), 'visible', []);

        $this->tui()->handleInput(self::SHIFT_F6);
        $this->tui()->handleInput(self::DELETE);

        $this->assertFalse($this->editor()->isAnyPickerOpen(), 'nothing to ask about');
    }

    public function testOutsideTheTreeTheTreesKeysAreLeftAlone(): void
    {
        $this->walkTo('a.php');
        $this->tui()->setFocus($this->editor());

        $this->tui()->handleInput(self::CTRL_N);
        $this->tui()->handleInput(self::SHIFT_F6);

        $this->assertFileExists($this->project->path('a.php'));
        $this->assertFileDoesNotExist($this->project->path('a.phpx'));
    }

    public function testCtrlShiftCCopiesTheSelectedRowsPath(): void
    {
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Keep);
        $this->tui()->setFocus($this->tree());
        $this->walkTo('b.php');

        $this->tui()->handleInput(self::CTRL_SHIFT_C);

        $this->assertSame('Copied b.php', Toasts::last($this->ide), 'the row, not the open file');

        $this->tui()->setFocus($this->editor());
        $this->tui()->handleInput(self::CTRL_SHIFT_C);
        $this->assertSame('Copied a.php', Toasts::last($this->ide), 'and in the editor, the open file as before');
    }

    private function walkTo(string $relative): void
    {
        for ($step = 0; $step < 20; ++$step) {
            $this->tui()->handleInput(self::UP);
        }
        for ($step = 0; $step < 20 && $this->tree()->selectedNode()?->path !== $this->project->path($relative); ++$step) {
            $this->tui()->handleInput(self::DOWN);
        }
        $this->assertSame($this->project->path($relative), $this->tree()->selectedNode()?->path, 'the walk reached the row');
    }

    private function type(string $keys): void
    {
        foreach (\mb_str_split($keys) as $key) {
            $this->tui()->handleInput($key);
        }
    }

    private function tui(): Tui
    {
        return Services::resolve($this->ide, Tui::class);
    }

    private function tree(): FileTreeWidget
    {
        return Services::resolve($this->ide, FileTreeWidget::class);
    }

    private function editor(): CodeEditorWidget
    {
        return Services::resolve($this->ide, CodeEditorWidget::class);
    }
}
