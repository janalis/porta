<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FindModalReads;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\ContextMenu;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Right-click context menus across the panes: tree, editor, tabs,
 * git rows, and their placement/dismissal rules.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeContextMenusTest extends TestCase
{
    use ClearsPortaLsp;
    use FindModalReads;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testContextMenuSpillingIntoEmptyEditorDoesNotBleedBackground(): void
    {
        // Right-clicking near the right of the sidebar anchors the menu so it
        // spills across the tree→editor boundary. With no file open the editor is
        // a blank pane; the menu's clipped slice must not bleed its box background
        // across that pane. Regression: OverlayCompositor left the popup
        // background active over the tail, painting a full-width box.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no file open → the blank editor pane the box bled across
            [
                200 => function ($tui, $terminal, $ide): void {
                    // Right-press col 25, row 1: the 22-wide menu spills past the
                    // sidebar (tree cols 0–29) into the editor (col 31+).
                    $tui->handleInput("\x1b[<2;26;3M");
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'context menu opened');

                    $renderer = Services::resolve($ide, Renderer::class);
                    $editor = $tui->getById('editor');
                    $rect = $renderer->getWidgetRect($editor);
                    $rows = $editor->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, $rect->rows));
                    // A menu item row (offset a couple rows below the anchor).
                    $menuRow = $rows[3] ?? '';
                    // The menu background must not be active far into the blank pane:
                    // a reset separates the composited menu from the editor tail.
                    $this->assertSame('default', $this->netBackgroundAt($menuRow, $rect->columns - 5), 'the menu box background must not bleed across the empty editor');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitHistoryCommitContextMenuOffersCommitActions(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n"]);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d opens the git view
                },
                550 => function ($tui, $terminal, $ide): void {
                    $this->await(static fn (): bool => 'git-changes' === $tui->getFocus()?->getId());
                    $tui->handleInput('2'); // switch to the History tab
                    $this->await(static fn (): bool => null !== $tui->getById('git-history')->selectedCommit());

                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('git-history'));
                    $terminal->clearOutput();
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 8, $rect->row + 2)); // right-click the first commit row (SGR is 1-based)
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-clicking a commit opened its menu');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Cherry-pick'));
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Copy Hash', $screen);
                    $this->assertStringContainsString('Reset to Commit', $screen);
                    $tui->handleInput("\x1b"); // close the menu
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    // Keyboard parity: Ctrl+F10 on the (focused) history list.
                    $this->assertSame('git-history', $tui->getFocus()?->getId());
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~");
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'Ctrl+F10 opened the commit menu');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Copy Hash'));
                    $this->assertStringContainsString('Copy Hash', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeContextMenuCoversTheSidebarSeparator(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => static function ($tui): void {
                    // Right-click a tree entry; the menu is wide enough to spill past the sidebar.
                    $tui->handleInput("\x1b[<2;21;3M"); // right-press col 20, row 1 (first entry)
                },
                280 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'tree context menu opened');
                    $lines = $tui->getById('separator')->render(new \Symfony\Component\Tui\Render\RenderContext(1, 24));
                    $painted = array_filter($lines, static fn (string $l): bool => ' ' !== $l);
                    $this->assertNotEmpty($painted, 'the context menu paints over the separator column (no hole)');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testContextMenuHighlightsTheItemUnderTheHoveringPointer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no file open → the tree is focused
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;21;3M"); // right-click a tree entry → context menu
                },
                280 => function ($tui, $terminal, $ide): void {
                    $menu = $tui->getById('context-menu');
                    $this->assertTrue($menu->isOpen(), 'the context menu is open');

                    $overlay = Services::resolve($ide, \Porta\Overlay\State\MenuOverlayController::class);
                    $region = $overlay->contextMenuDropdown();
                    $this->assertNotNull($region, 'the menu overlay was placed');

                    $selected = new \ReflectionProperty($menu, 'selected');
                    $before = $selected->getValue($menu);

                    // Hover up from the bottom row until the highlight lands on a
                    // selectable item other than the initially-selected first one.
                    $moved = false;
                    for ($row = $region->count - 1; $row >= 0 && !$moved; --$row) {
                        $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $region->col + 3, $region->top + $row + 1));
                        $moved = $selected->getValue($menu) !== $before;
                    }
                    $this->assertTrue($moved, 'hovering a menu item moves the highlight to it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInFilesFromFolderContextMenuScopesTheSearch(): void
    {
        // `sub/` and the root both contain the marker; a folder-scoped search
        // must return only the in-folder match. Directories sort first, so `sub`
        // is the first tree entry.
        $project = new TempProject([
            'sub/Inside.php' => "<?php\n// scopedMarker inside\n",
            'Outside.php' => "<?php\n// scopedMarker at the root\n",
        ]);

        $rightClickFolder = static function ($tui, $terminal, $ide): void {
            $tui->requestRender(force: true); // force a frame so the tree rect is live under load
            $tui->processRender();
            $renderer = Services::resolve($ide, Renderer::class);
            $rect = $renderer->getWidgetRect($tui->getById('tree'));
            // Header is relative row 0; the first entry (the `sub` folder) is row 1.
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
        $screen = static fn ($tui, $terminal): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => $rightClickFolder,
                280 => function ($tui, $terminal) use ($screen): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'folder context menu opened');
                    $this->assertStringContainsString('Find in Files', $screen($tui, $terminal), 'folder menu offers Find in Files');
                    // New File → New Folder → (skip separator) → Find in Files.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                340 => function ($tui): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->isFindModalOpen(), 'the find modal opened, scoped to the folder');
                    $this->assertStringContainsString('Scope: sub', $this->modalText($tui), 'the modal is scoped to the clicked folder');
                    foreach (str_split('scopedMarker') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                650 => function ($tui): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane
                    // Assert on the results panel itself (the tree sidebar also lists
                    // Outside.php, so the whole screen is the wrong surface).
                    $panel = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 14))));
                    $this->assertStringContainsString('1 results', $tui->getById('results')->statusText(), 'only the in-folder match counts (root is out of scope)');
                    $this->assertStringContainsString('sub/Inside.php', $panel, 'the in-folder match is listed');
                    $this->assertStringNotContainsString('Outside.php', $panel, 'the root match is excluded');
                    $tui->handleInput("\r"); // jump to the match
                },
                760 => function ($tui): void {
                    $this->assertStringEndsWith('sub/Inside.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after the jump');
                    $this->assertTrue($tui->getById('results')->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testReplaceInFilesFromFolderContextMenuScopesTheReplacement(): void
    {
        // Both files contain $token; a folder-scoped replace must rewrite only the
        // in-folder file. Directories sort first, so `sub` is the first tree entry.
        $project = new TempProject([
            'sub/Inside.php' => "<?php\n\$token = 1;\n",
            'Outside.php' => "<?php\n\$token = 2;\n",
        ]);

        $rightClickFolder = static function ($tui, $terminal, $ide): void {
            $tui->requestRender(force: true); // force a frame so the tree rect is live under load
            $tui->processRender();
            $renderer = Services::resolve($ide, Renderer::class);
            $rect = $renderer->getWidgetRect($tui->getById('tree'));
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
        $screen = static fn ($tui, $terminal): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => $rightClickFolder,
                280 => function ($tui, $terminal) use ($screen): void {
                    $tui->tick();
                    $this->assertStringContainsString('Replace in Files', $screen($tui, $terminal), 'folder menu offers Replace in Files');
                    // New File → New Folder → (skip sep) → Find in Files → Replace in Files.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                360 => function ($tui): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->isFindModalOpen(), 'the find modal opened, scoped to the folder');
                    $this->assertStringContainsString('Scope: sub', $this->modalText($tui), 'the modal is scoped to the clicked folder');
                    foreach (str_split('token') as $ch) {
                        $tui->handleInput($ch); // the search term (query field)
                    }
                },
                760 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (str_split('renamed') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all (scoped)
                },
                1000 => function ($tui) use ($project): void {
                    $this->assertStringContainsString('$renamed = 1;', (string) file_get_contents($project->path('sub/Inside.php')), 'the in-folder file is replaced');
                    $this->assertStringContainsString('$token = 2;', (string) file_get_contents($project->path('Outside.php')), 'the out-of-scope file is untouched');
                },
            ],
        );
    }

    public function testEditorContextMenuCutsTheSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // press line 0 col 0
                    $tui->handleInput("\x1b[<32;43;3M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;43;3m"); // release → "hello" selected
                    $tui->handleInput("\x1b[<2;45;3M"); // right-click in the editor
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-click opened the editor menu');
                    // The selection is kept (caret not moved), so Cut acts on it.
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                    $tui->handleInput("\r"); // Enter → Cut (first item)
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'menu closed after running');
                    $this->assertSame(' world', $tui->getById('editor')->getDocument()->getLine(0), 'Cut removed the selection');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEditorContextMenuPlacesCaretWhenThereIsNoSelectionThenEscapes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[<2;40;4M"); // right-click on line 1, no selection
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $this->assertSame(1, $tui->getById('editor')->getDocument()->getCursorLine(), 'caret moved to the clicked line');
                    $tui->handleInput('z'); // an unbound key is swallowed while the menu is open
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'stray keys neither run nor close');
                    $tui->handleInput("\x1b"); // Esc closes (modal routing)
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'Esc closed the menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickClosesAnOpenApplicationMenu(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[21~"); // F10 opens the application menu
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $tui->handleInput("\x1b[<2;45;3M"); // right-click the editor
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'right-click closed the application menu');
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'and opened the context menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickOnHeaderOrEmptyTabStripOpensNothing(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // tree header row → nodeAt() null
                },
                250 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'the tree header targets no node');
                    $tui->handleInput("\x1b[<2;150;2M"); // tab bar past the last tab → no index
                },
                300 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'empty tab-strip space targets no tab');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabContextMenuCloseClosesTheClickedTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (tree row 2)
                },
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click the first tab (A)
                },
                400 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-click on a tab opened the menu');
                    $tui->handleInput("\r"); // Close (first item) → closes A
                },
                500 => function ($tui): void {
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath(), 'A closed, B shown');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeContextMenuNewFileAndNewFolder(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/keep.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/keep.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/ (a directory)
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\r"); // New File (first item) → opens the name prompt
                },
                360 => function ($tui): void {
                    $this->typeString($tui, 'new.php');
                    $tui->handleInput("\r"); // submit
                },
                460 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/ again
                },
                540 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // down → New Folder
                    $tui->handleInput("\r");
                },
                600 => function ($tui): void {
                    $this->typeString($tui, 'pkg');
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertFileExists($project->path('src/new.php'), 'New File created the file');
        $this->assertDirectoryExists($project->path('src/pkg'), 'New Folder created the directory');
    }

    public function testTreeNewEntryWithBlankNameIsIgnored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/keep.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/keep.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/
                },
                300 => static function ($tui): void {
                    $tui->handleInput("\r"); // New File
                },
                360 => static function ($tui): void {
                    $tui->handleInput("\r"); // submit an empty name → no-op
                },
                460 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/
                },
                540 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\r");
                },
                600 => static function ($tui): void {
                    $tui->handleInput('   '); // whitespace-only name → no-op
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertSame(['keep.php'], array_values(array_diff(scandir($project->path('src')), ['.', '..'])), 'no blank-named entry was created');
    }

    public function testTreeContextMenuRenameUpdatesDiskAndBuffer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                230 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                300 => static function ($tui): void {
                    // The prompt is prefilled with the current name; submitting it
                    // unchanged is a no-op.
                    $tui->handleInput("\r");
                },
                380 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('A.php'), 'renaming to the same name changed nothing');
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                460 => static function ($tui): void {
                    foreach (range(1, end: 4) as $_) {
                        $tui->handleInput("\x1b[B"); // → Rename…
                    }
                    $tui->handleInput("\r");
                },
                540 => function ($tui): void {
                    foreach (range(1, end: 10) as $_) {
                        $tui->handleInput("\x7f"); // backspace out the prefill
                    }
                    $this->typeString($tui, 'renamed.php');
                    $tui->handleInput("\r");
                },
                640 => function ($tui): void {
                    $this->assertStringEndsWith('renamed.php', (string) $tui->getById('editor')->getFilePath(), 'the open buffer followed the rename');
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'old name gone');
        $this->assertFileExists($project->path('renamed.php'), 'renamed on disk');
    }

    public function testEditorContextMenuNearTheBottomFlipsUp(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    // Right-click near the bottom of the editor: the panel would
                    // overflow, so it is flipped to sit above the pointer.
                    $tui->handleInput("\x1b[<2;45;22M");
                    $tui->tick();
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'menu opened and placed above the click without error');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeContextMenuNearTheRightEdgeStraddlesPanes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    // Right-click near the tree's right edge: the panel spills past
                    // the tree into the editor pane, exercising the per-pane clip.
                    $tui->handleInput("\x1b[<2;28;3M");
                    $tui->tick();
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'a straddling panel renders across both panes without error');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeRenameOntoAnExistingNameFlashesError(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                300 => static function ($tui): void {
                    // Rename is the 4th selectable item after New File/New Folder/Copy×2.
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                380 => function ($tui): void {
                    foreach (range(1, end: 10) as $_) {
                        $tui->handleInput("\x7f");
                    }
                    $this->typeString($tui, 'B.php'); // clashes with an existing file
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertStringContainsString('Cannot rename A.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $this->assertFileExists($project->path('A.php'), 'the clash left A.php untouched');
    }

    public function testTreeContextMenuCopyPathAndRelativePath(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\r");
                },
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                500 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\r");
                },
            ],
        );

        $raw = $terminal->getOutput();
        // The status bar truncates a long flash, so verify the full paths landed
        // on the clipboard via the OSC 52 base64 payload.
        $this->assertStringContainsString(base64_encode($project->path('A.php')), $raw, 'absolute path copied to the clipboard');
        $this->assertStringContainsString(base64_encode('A.php'), $raw, 'relative path copied to the clipboard');
        $this->assertStringContainsString('Copied A.php', AnsiUtils::stripAnsiCodes($raw), 'relative-path copy flashed');
    }

    public function testTreeContextMenuDeleteConfirmedClosesBufferAndShowsNext(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2) as well
                },
                250 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                350 => static function ($tui): void {
                    // Delete is the 6th selectable item.
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete… → confirm prompt
                },
                430 => function ($tui): void {
                    $this->typeString($tui, 'y');
                    $tui->handleInput("\r"); // confirmed
                },
                520 => function ($tui): void {
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath(), 'deleted A, showing B');
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'A.php removed from disk');
    }

    public function testTreeContextMenuDeleteLastBufferEmptiesTheEditor(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                300 => static function ($tui): void {
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => function ($tui): void {
                    $this->typeString($tui, 'yes'); // 'yes' is also accepted
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'));
    }

    public function testTreeContextMenuDeleteCancelledKeepsTheFile(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M");
                },
                300 => static function ($tui): void {
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => static function ($tui): void {
                    $tui->handleInput('n'); // decline
                    $tui->handleInput("\r");
                },
                460 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'declining returns focus to the tree');
                },
            ],
        );

        $this->assertFileExists($project->path('A.php'), 'declining the confirmation kept the file');
    }

    public function testTreeContextMenuOpenAndRefreshViaMouse(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->getById('editor')->showDocument('', new \Porta\Editor\Document(''));
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                300 => static function ($tui): void {
                    // The menu anchors at the click (SGR row 2 = the frame's top
                    // border), so item i sits at SGR row 3+i. "Open" is item 9
                    // for a file node → SGR row 12.
                    $tui->handleInput("\x1b[<0;4;13M"); // left-click "Open"
                },
                400 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath(), 'Open reopened the file');
                    // A file created on disk appears in the tree only after Refresh.
                    $project->write('late.php', "<?php\n");
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                500 => static function ($tui): void {
                    // "Refresh" is the last item (10) → SGR row 13.
                    $tui->handleInput("\x1b[<0;4;14M");
                    $tui->tick();
                },
                600 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'late.php'), 'Refresh picked up the new file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickInTheGapDismissesAnOpenContextMenu(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;45;3M"); // open the editor menu
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\x1b[<0;31;10M"); // left-click the gap → dismiss
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'a click outside the panel dismissed it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    // --- Tab close icon + reordering ------------------------------------------

    public function testCtrlF10OpensTheContextMenuForTheFocusedPane(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 in the editor
                    $tui->tick();
                    $context = Services::resolve($ide, ContextMenu::class);
                    $this->assertTrue($context->isOpen(), 'the editor context menu opened at the caret');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Go to Declaration or Usages', $screen);
                    $tui->handleInput("\x1b"); // Esc closes it
                    $this->assertFalse($context->isOpen());
                },
                350 => function ($tui, $terminal, $ide): void {
                    $tui->setFocus($tui->getById('tree'));
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the tree's selected node
                    $tui->tick();
                    $this->assertStringContainsString('New File', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'tree file operations under the selection');
                    $tui->handleInput("\x1b");
                },
                500 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_help');
                    $tui->tick();
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the help viewer: the tab menu
                    $tui->tick();
                    $this->assertStringContainsString('Close Others', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'viewer tabs get the tab context menu');
                    $tui->handleInput("\x1b");
                },
                650 => function ($tui): void {
                    // Go-to-file is the editor-hosted picker now; opening it hops
                    // off the help tab to a text buffer, and the modal picker owns
                    // input, so Ctrl+F10 opens no context menu over it.
                    $tui->handleInput("\x10"); // ctrl+p
                    $tui->handleInput("\x1b[21;5~"); // ctrl+f10
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->isSearchOverlayOpen(), 'go-to-file opened the centered picker');
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'no context menu over the picker');
                    // A right-click inside the modal picker also opens no menu.
                    $tui->handleInput("\x1b[<2;10;10M"); // right-press
                    $tui->tick();
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'right-click over the picker opens no menu');
                },
            ],
        );
    }

    /** The background colour in effect at visible column $col (net of every SGR code up to it). */
    private function netBackgroundAt(string $line, int $col): string
    {
        $slice = AnsiUtils::sliceByColumn($line, $col, 1, true); // carries all prior SGR as pending
        $bg = 'default';
        if (preg_match_all('/\x1b\[([0-9;]*)m/', $slice, $codes)) {
            foreach ($codes[1] as $params) {
                if ('' === $params || '0' === $params || '49' === $params) {
                    $bg = 'default';

                    continue;
                }
                if (preg_match('/48;5;(\d+)/', $params, $m)) {
                    $bg = '48;5;'.$m[1];
                }
            }
        }

        return $bg;
    }

    /** Type each character of $text into the currently focused prompt/editor. */
    private function typeString(Tui $tui, string $text): void
    {
        foreach (mb_str_split($text) as $char) {
            $tui->handleInput($char);
        }
    }
}
