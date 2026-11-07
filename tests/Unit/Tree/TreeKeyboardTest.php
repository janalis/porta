<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\ReferencesFollowRequested;
use Porta\Event\WorkingTreeChanged;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
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

    /** Each file of a deleted folder is told of with its text — an open one's unsaved text — for what keeps it. */
    public function testAFoldersFilesGoWithTheirText(): void
    {
        $this->project->write('src/deep/B.php', "<?php // b\n");
        $this->project->write('src/.git/HEAD', "ref: refs/heads/main\n"); // a repository's own, not the project's text
        $deleted = [];
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(FileDeleted::class, static function (FileDeleted $event) use (&$deleted): void {
            $deleted[\basename($event->path)] = $event->previousText;
        });
        $buffers = Services::resolve($this->ide, BufferService::class);
        $buffers->openFile($this->project->path('src/A.php'), focus: OpenFocus::Editor);
        $this->editor()->getDocument()->insert('// unsaved ');
        $this->tui()->setFocus($this->tree());
        $this->walkTo('src');

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        \ksort($deleted); // the order the disk lists them in is the disk's
        $this->assertSame(['A.php' => "// unsaved <?php\n", 'B.php' => "<?php // b\n", 'src' => null], $deleted);
    }

    /**
     * An open file's text is handed on in its FILE's shape — its ending and its
     * mark — because that is what a recovery writes back: held as the buffer
     * holds it, a deleted CRLF file came back from local history as LF.
     */
    public function testAnOpenFilesTextGoesWithItInTheFilesOwnShape(): void
    {
        $this->project->write('d.php', "\u{FEFF}<?php\r\n// d\r\n");
        $this->tree()->refresh();
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('d.php'), focus: OpenFocus::Editor);
        $this->editor()->getDocument()->insert('// unsaved ');
        $this->tui()->setFocus($this->tree());
        $kept = [];
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(FileDeleted::class, static function (FileDeleted $event) use (&$kept): void {
            $kept[] = $event->previousText;
        });
        $this->walkTo('d.php');

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        $this->assertSame(["\u{FEFF}// unsaved <?php\r\n// d\r\n"], $kept);
    }

    /** A deleted vendor/ is not a reason to read every file in it: past the cap, they go as files do outside Porta. */
    public function testAHugeFoldersFilesAreKeptOnlyUpToTheCap(): void
    {
        for ($i = 0; $i <= 500; ++$i) {
            $this->project->write("src/many/f{$i}.txt", "x\n");
        }
        $kept = 0;
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(FileDeleted::class, static function (FileDeleted $event) use (&$kept): void {
            $kept += null === $event->previousText ? 0 : 1;
        });
        $this->walkTo('src');

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        $this->assertSame(500, $kept);
    }

    /** A link to a folder goes as a link: the folder it points at, and its files, stay where they are. */
    public function testALinkToAFolderGoesAloneAndItsTargetsFilesAreNotToldOf(): void
    {
        $outside = new TempProject(['C.php' => "<?php // c\n"]);
        \symlink(\rtrim($outside->path(), characters: '/'), $this->project->path('lib'));
        $this->tree()->refresh();
        $deleted = [];
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(FileDeleted::class, static function (FileDeleted $event) use (&$deleted): void {
            $deleted[] = \basename($event->path);
        });
        $this->walkTo('lib');

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        $this->assertFileDoesNotExist($this->project->path('lib'));
        $this->assertFileExists($outside->path('C.php'));
        $this->assertNotContains('C.php', $deleted, 'a file that is still there is not deleted');
    }

    /** A link to a file goes as a link: the file it points at keeps its open tab, and only the link is told of as deleted. */
    public function testALinkToAnOpenFileGoesAloneAndItsTargetStaysOpen(): void
    {
        \symlink($this->project->path('a.php'), $this->project->path('link.php'));
        $this->tree()->refresh();
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Keep);
        $deleted = [];
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(FileDeleted::class, static function (FileDeleted $event) use (&$deleted): void {
            $deleted[] = $event->path;
        });
        $this->walkTo('link.php');

        $this->tui()->handleInput(self::DELETE);
        $this->type("y\r");

        $this->assertFileDoesNotExist($this->project->path('link.php'));
        $this->assertSame([\realpath($this->project->path('.')).'/link.php'], $deleted, 'the link, not what it points at');
        $this->assertTrue(Services::resolve($this->ide, BufferManager::class)->has((string) \realpath($this->project->path('a.php'))), 'the target is still open');
    }

    /** A link renames as a link: the file it points at keeps its tab, the rename told of is the link's, and no reference follows a file that never moved. */
    public function testRenamingALinkLeavesItsTargetAlone(): void
    {
        \symlink($this->project->path('a.php'), $this->project->path('link.php'));
        $this->tree()->refresh();
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Keep);
        $renamed = [];
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(FileRenamed::class, static function (FileRenamed $event) use (&$renamed): void {
            $renamed[] = [\basename($event->from), \basename($event->to)];
        });
        $followed = 0;
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(ReferencesFollowRequested::class, static function () use (&$followed): void {
            ++$followed;
        });
        $this->walkTo('link.php');

        $this->renameTo('moved.php');

        $this->assertTrue(\is_link($this->project->path('moved.php')));
        $this->assertSame([['link.php', 'moved.php']], $renamed, 'the link moved, not a.php onto itself');
        $this->assertSame(0, $followed, 'no class was moved, so no reference follows: asked, the server renames the class inside a.php');
        $this->assertTrue(Services::resolve($this->ide, BufferManager::class)->has((string) \realpath($this->project->path('a.php'))), 'the target is still open');
    }

    /**
     * A rename saves the file's unsaved edits first, because the move closes
     * the buffer and re-reads it from disk. When that save is REFUSED — the
     * file changed on disk under the edits, the first Ctrl+S's warning — the
     * move went ahead anyway and the edits were gone, replaced by the other
     * writer's text. Now nothing moves, and the edits stay.
     */
    public function testARenameWhoseSaveIsRefusedMovesNothingAndKeepsTheEdits(): void
    {
        $path = (string) \realpath($this->project->path('a.php'));
        Services::resolve($this->ide, BufferService::class)->openFile($path, OpenFocus::Keep);
        $buffer = Services::resolve($this->ide, BufferManager::class)->get($path);
        $this->assertInstanceOf(Buffer::class, $buffer);
        $buffer->document->insert('// mine');
        \file_put_contents($path, data: "<?php\n// theirs, written from outside\n");
        \clearstatcache();
        $this->ide->pollFilesystem();
        $this->tui()->setFocus($this->tree());
        $this->walkTo('a.php');

        $this->renameTo('moved.php');

        $this->assertFileExists($path, 'not moved');
        $this->assertFileDoesNotExist($this->project->path('moved.php'));
        $this->assertContains('a.php changed on disk', Toasts::all($this->ide), 'and says why');
        $this->assertStringContainsString('// mine', $buffer->document->getText(), 'the edits are still in their buffer');
        $this->assertTrue($buffer->isDirty());

        // And trying again at once does not write over the other writer: the
        // move never answered Ctrl+S's first press for the user.
        $this->tui()->setFocus($this->tree());
        $this->walkTo('a.php');
        $this->renameTo('moved.php');
        $this->assertFileExists($path, 'still not moved');
        $this->assertStringContainsString('theirs', (string) \file_get_contents($path), 'their text is still on disk');
    }

    /** A subfolder the walk cannot read is skipped, not thrown: the delete answers as it did before the walk. */
    public function testAnUnreadableSubfolderDoesNotThrowOutOfTheDelete(): void
    {
        \mkdir($this->project->path('src/locked'));
        \chmod($this->project->path('src/locked'), permissions: 0o000);
        $this->walkTo('src');

        try {
            $this->tui()->handleInput(self::DELETE);
            $this->type("y\r");
        } finally {
            \chmod($this->project->path('src/locked'), permissions: 0o755);
        }

        $this->assertSame('Cannot delete src', Toasts::last($this->ide), 'the remove itself refuses, and says so');
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

    /** @return iterable<string, array{string}> */
    public static function namesThatAreNotAName(): iterable
    {
        yield 'up a folder' => ['../Moved.php'];
        yield 'down a folder' => ['lib/Moved.php'];
        yield 'the parent' => ['..'];
        yield 'the folder itself' => ['.'];
    }

    #[DataProvider('namesThatAreNotAName')]
    public function testARenameTakesAName(string $name): void
    {
        \mkdir($this->project->path('src/lib'));
        $this->walkTo('src');
        $this->tui()->handleInput("\x1b[C"); // open it: its files are rows
        $this->walkTo('src/A.php');

        $this->renameTo($name);

        $this->assertFileExists($this->project->path('src/A.php'), 'still where it was');
        $this->assertFileDoesNotExist($this->project->path('Moved.php'));
        $this->assertFileDoesNotExist($this->project->path('src/lib/Moved.php'));
        $this->assertSame($name.' is not a usable name here', Toasts::last($this->ide));
    }

    public function testACaseOnlyRenameRenames(): void
    {
        if (!\file_exists($this->project->path('B.php'))) {
            $this->markTestSkipped('a case-sensitive disk has no collision to step around');
        }
        $this->walkTo('b.php');

        $this->renameTo('B.php');

        $names = \scandir($this->project->path(''));
        $this->assertIsArray($names);
        $this->assertContains('B.php', $names);
        $this->assertNotContains('b.php', $names);
        $this->assertSame('Renamed to B.php', Toasts::last($this->ide));
    }

    /** Case beyond ASCII, and the other normalisation of `é`: one file to the disk, still a rename. */
    public function testACaseOnlyRenameBeyondAsciiRenames(): void
    {
        \file_put_contents($this->project->path("\u{C9}l\u{E9}ment.php"), data: "<?php\n");
        if (!\file_exists($this->project->path("\u{E9}l\u{E9}ment.php"))) {
            $this->markTestSkipped('a case-sensitive disk has no collision to step around');
        }
        $this->tree()->refresh();
        $this->walkTo("\u{C9}l\u{E9}ment.php");

        $this->renameTo("\u{E9}l\u{E9}ment.php");

        $this->assertSame("Renamed to \u{E9}l\u{E9}ment.php", Toasts::last($this->ide));
    }

    /** A folder counts its own links (`.` and its children's `..`), never a second name. */
    public function testACaseOnlyRenameOfAFolderRenames(): void
    {
        if (!\file_exists($this->project->path('SRC'))) {
            $this->markTestSkipped('a case-sensitive disk has no collision to step around');
        }
        $this->walkTo('src');

        $this->renameTo('Src');

        $this->assertDirectoryExists($this->project->path('Src'));
        $this->assertSame('Renamed to Src', Toasts::last($this->ide));
    }

    /** A second hard link is another entry: renaming onto it would do nothing and say it did. */
    public function testARenameOntoAHardLinkOfItselfAsks(): void
    {
        \link($this->project->path('a.php'), $this->project->path('linked.php'));
        $this->walkTo('a.php');

        $this->renameTo('linked.php');

        $this->assertFileExists($this->project->path('a.php'), 'not renamed away');
        $this->assertStringStartsWith('Cannot rename a.php', Toasts::last($this->ide));
    }

    public function testNewFileStaysBelowItsFolder(): void
    {
        $this->walkTo('src');

        $this->tui()->handleInput(self::ALT_INSERT);
        $this->type("\r"); // New File…
        $this->type("../escape.php\r");

        $this->assertFileDoesNotExist($this->project->path('escape.php'));
        $this->assertSame('../escape.php is not a usable name here', Toasts::last($this->ide));
    }

    public function testNewFolderOnATakenNameSaysSo(): void
    {
        $this->walkTo('a.php');

        $this->tui()->handleInput(self::CTRL_N);
        $this->tui()->handleInput(self::DOWN);
        $this->type("\r"); // New Folder…
        $this->type("src\r");

        $this->assertSame('Cannot create src', Toasts::last($this->ide));
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

    private function renameTo(string $name): void
    {
        $this->tui()->handleInput(self::SHIFT_F6);
        $this->type(\str_repeat("\x7f", times: 20)); // the prompt starts from the old name
        $this->type($name."\r");
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
