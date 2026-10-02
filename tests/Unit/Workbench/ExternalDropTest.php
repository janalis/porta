<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Tui;

/**
 * Files dropped in from outside, as every terminal but kitty delivers them: a
 * bracketed paste of their paths, into whatever has focus — and the rules
 * table {@see ExternalDrop} keeps for each zone.
 *
 * Booted rather than doubled: the zone is read off the real focus and the
 * real pickers, and what a drop DOES is the tree's copy, the buffer service's
 * open and the project switcher — the assembly is the thing under test.
 */
final class ExternalDropTest extends TestCase
{
    use BootsIde;

    /** Where the dropped files come from: somewhere that is not the project. */
    private TempProject $outside;

    protected function setUp(): void
    {
        $this->outside = new TempProject(['x.php' => "<?php\n// x\n", 'a b.txt' => '', 'lib/y.php' => '', 'lib/z.php' => '', 'lib/.DS_Store' => "\0"]);
    }

    public function testAPathPastedIntoTheTreeIsCopiedIntoTheSelectedFolderKeepingBoth(): void
    {
        $ide = $this->boot();
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('x.php'));
        $this->paste($this->outside->path('x.php'));
        $this->paste($this->project->path('src/A.php'));

        $this->assertSame("<?php\n// x\n", \file_get_contents($this->project->path('src/x.php')), 'copied into the folder of the selected file');
        $this->assertFileExists($this->project->path('src/x (2).php'), 'the second drop keeps both');
        $this->assertFileExists($this->project->path('src/A (2).php'), 'a file dropped into its own folder is duplicated, not skipped');
        $this->assertFileExists($this->outside->path('x.php'), 'a drop from outside copies, never moves');
        $this->assertSame('Copied A (2).php', Toasts::last($ide));
    }

    public function testADroppedSymlinkKeepsItsOwnName(): void
    {
        $this->boot();
        \symlink($this->outside->path('x.php'), $this->outside->path('link.php'));
        $this->tree()->revealPath($this->project->path('src/A.php'));
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('link.php'));

        $this->assertSame("<?php\n// x\n", \file_get_contents($this->project->path('src/link.php')), 'named for the link, holding what it points at');
    }

    public function testATreeWithNothingSelectedHasNoFolderToCopyInto(): void
    {
        $ide = $this->boot();
        Probe::set($this->tree(), 'visible', []);
        $this->tui()->setFocus($this->tree());

        $this->paste($this->outside->path('x.php'));

        $this->assertSame('Drop on a folder to copy into it', Toasts::last($ide));
    }

    public function testATreeDropWithNoFolderUnderItSaysWhereToDrop(): void
    {
        $ide = $this->boot();

        Services::resolve($ide, ExternalDrop::class)->drop([(string) \realpath($this->outside->path('x.php'))], DropZone::Tree);

        $this->assertSame('Drop on a folder to copy into it', Toasts::last($ide));
    }

    public function testAPathPastedIntoAnOpenFileAsksAndCanInsertIt(): void
    {
        $this->boot(open: 'src/A.php');
        $this->tui()->setFocus($this->editor());
        $this->paste(\str_replace(search: ' ', replace: '\ ', subject: $this->outside->path('a b.txt')));
        $this->assertTrue($this->editor()->isAnyPickerOpen(), 'a drop and a pasted path are the same bytes: ask');
        $this->tui()->handleInput("\r");

        $this->assertStringStartsWith($this->outside->path('a b.txt').'<?php', $this->editor()->getDocument()->getText(), 'inserted as the plain path, the shell quoting gone');
        $this->assertSame((string) \realpath($this->project->path('src/A.php')), $this->buffers()->active()?->path, 'and nothing opened');
    }

    public function testSeveralPathsDroppedOnAnOpenFileAreAskedAboutTogether(): void
    {
        $this->boot(open: 'src/A.php');
        $this->tui()->setFocus($this->editor());

        $this->paste($this->outside->path('x.php').' '.$this->outside->path('lib'));

        $overlay = Probe::get(Probe::object($this->editor(), 'overlayHost'), 'overlay');
        $this->assertIsArray($overlay);
        $this->assertSame('2 items dropped', $overlay['title']);
        $this->assertSame(['Insert Paths', 'Open'], \array_column($overlay['items'], 'label'));
    }

    public function testAPathPastedIntoAnOpenFileCanOpenItInstead(): void
    {
        $this->boot(open: 'src/A.php');
        $this->tui()->setFocus($this->editor());

        $this->paste($this->outside->path('x.php'));
        $this->tui()->handleInput("\x1b[B");
        $this->tui()->handleInput("\r");

        $this->assertSame((string) \realpath($this->outside->path('x.php')), $this->buffers()->active()?->path);
    }

    public function testAPathPastedIntoAnEmptyEditorOpensIt(): void
    {
        $this->boot();
        $this->tui()->setFocus($this->editor());

        $this->paste($this->outside->path('x.php'));

        $this->assertSame((string) \realpath($this->outside->path('x.php')), $this->buffers()->active()?->path);
        $this->assertSame("<?php\n// x\n", $this->editor()->getDocument()->getText(), 'the drop was spent: the file it opened was not pasted into as well');
    }

    public function testADropOnTheWelcomeCardOpensIt(): void
    {
        $ide = $this->boot();
        Services::resolve($ide, ViewRegistry::class)->open('welcome');

        $this->paste($this->outside->path('x.php'));

        $this->assertSame((string) \realpath($this->outside->path('x.php')), $this->buffers()->active()?->path, 'the card stands in for the empty editor');
        $this->assertFalse(Services::resolve($ide, ViewRegistry::class)->isOpen('welcome'), 'and steps aside for what was dropped on it');
    }

    public function testAFolderOfTheProjectIsRevealedInTheTree(): void
    {
        $ide = $this->boot();

        Services::resolve($ide, ExternalDrop::class)->drop([(string) \realpath($this->project->path('src'))], DropZone::Empty);

        $this->assertSame($this->project->path('src'), $this->tree()->selectedNode()?->path);
        $this->assertSame($this->tree(), $this->tui()->getFocus());
    }

    public function testAnOutsideFolderBecomesTheProjectWhenNothingIsOpen(): void
    {
        $ide = $this->boot();

        Services::resolve($ide, ExternalDrop::class)->drop([(string) \realpath($this->outside->path('lib'))], DropZone::Empty);

        $this->assertSame(\realpath($this->outside->path('lib')), $ide->reopenAt());
    }

    public function testAnOutsideFolderOpensItsFilesBesideWhatIsOpen(): void
    {
        $ide = $this->boot(open: 'src/A.php');

        Services::resolve($ide, ExternalDrop::class)->drop([(string) \realpath($this->outside->path('lib'))], DropZone::Empty);

        $this->assertNull($ide->reopenAt(), 'an open file is a session worth keeping');
        $this->assertCount(3, $this->buffers()->all(), 'y.php and z.php, beside A.php — not the folder\'s hidden .DS_Store');
    }

    public function testADropOfAFileAndAFolderNeverReplacesTheSession(): void
    {
        $ide = $this->boot();

        Services::resolve($ide, ExternalDrop::class)->drop(
            [(string) \realpath($this->outside->path('x.php')), (string) \realpath($this->outside->path('lib'))],
            DropZone::Empty,
        );

        $this->assertNull($ide->reopenAt());
        $this->assertCount(3, $this->buffers()->all());
    }

    public function testABigFolderOpensItsFirstFilesAndSaysSo(): void
    {
        $files = [];
        for ($i = 1; $i <= (ExternalDrop::MAX_FOLDER_FILES + 5); ++$i) {
            $files[\sprintf('many/f%02d.txt', $i)] = '';
        }
        $many = new TempProject($files);
        $ide = $this->boot(open: 'src/A.php');

        Services::resolve($ide, ExternalDrop::class)->drop([(string) \realpath($many->path('many'))], DropZone::Empty);

        $this->assertCount(ExternalDrop::MAX_FOLDER_FILES + 1, $this->buffers()->all());
        $this->assertSame('Opened 20 of 25 files', Toasts::last($ide));
    }

    public function testAPasteThatIsNotADropIsLeftToThePastePaths(): void
    {
        $this->boot(open: 'src/A.php');
        $this->tui()->setFocus($this->editor());

        $this->paste('hello world');

        $this->assertFalse($this->editor()->isAnyPickerOpen());
        $this->assertStringStartsWith('hello world', $this->editor()->getDocument()->getText(), 'pasted as text');
    }

    public function testAPathPastedIntoAPickerIsTypedThereAsPlainText(): void
    {
        $this->boot(open: 'src/A.php');
        $this->tui()->setFocus($this->tree());
        $typed = null;
        $this->editor()->openPrompt('Name', '', static function (string $value) use (&$typed): void {
            $typed = $value;
        });

        $this->paste(\str_replace(search: ' ', replace: '\\ ', subject: $this->outside->path('a b.txt')));
        $this->tui()->handleInput("\r");

        $this->assertSame($this->outside->path('a b.txt'), $typed, 'the shell quoting is gone, the spelling is as dropped');
        $this->assertFileDoesNotExist($this->project->path('src/a b.txt'), 'and the tree under it copied nothing');
    }

    public function testAPathPastedIntoAFieldThatHasTheKeysIsTypedAsPlainText(): void
    {
        $this->boot(open: 'src/A.php');
        $this->tui()->setFocus(null);

        $this->paste($this->outside->path('x.php'));

        $this->assertFalse($this->editor()->isAnyPickerOpen(), 'not the editor\'s question');
        $this->assertCount(1, $this->buffers()->all(), 'and nothing opened');
    }

    public function testThePanelsShellGetsThePasteAsTheTerminalQuotedIt(): void
    {
        $this->boot();
        $panel = Services::resolve($this->ide, TerminalPanelWidget::class);
        Probe::set($panel, 'open', true);
        $forwarded = [];
        $panel->onInputForward(static function (string $data) use (&$forwarded): void {
            $forwarded[] = $data;
        });
        $this->tui()->setFocus($panel);
        $pasted = \str_replace(search: ' ', replace: '\\ ', subject: $this->outside->path('a b.txt'));

        $this->paste($pasted);

        $this->assertSame(["\x1b[200~".$pasted."\x1b[201~"], $forwarded, 'a shell wants the quoting');
    }

    private function boot(?string $open = null): Ide
    {
        $ide = $this->bootIde(['composer.json' => '{}', 'src/A.php' => "<?php\n", 'top.php' => "<?php\n"]);
        if (null !== $open) {
            Services::resolve($ide, BufferService::class)->openFile($this->project->path($open), OpenFocus::Editor);
        }

        return $ide;
    }

    private function paste(string $text): void
    {
        $this->tui()->handleInput("\x1b[200~".$text."\x1b[201~");
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

    private function buffers(): BufferManager
    {
        return Services::resolve($this->ide, BufferManager::class);
    }
}
