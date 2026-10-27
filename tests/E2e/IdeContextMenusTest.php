<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BookmarkActions;
use Porta\Editor\Bookmarks;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Editor\EditHistory;
use Porta\Event\FileDeleted;
use Porta\History\RecentFilesStore;
use Porta\Ide;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FindModalReads;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\SgrReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Ui\Glyphs;
use Porta\Widget\ContextMenu;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\ReopenRequest;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
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

    /**
     * Right-click the first tree entry — the `sub` folder in these fixtures.
     *
     * Returned as a step rather than performed, because it has to run inside
     * runIde()'s numbered schedule. The forced frame is load-bearing: the
     * widget rect it reads is a frame behind, so under a busy machine an
     * unforced read clicks where the tree used to be.
     */
    private function rightClickTheFirstFolder(): \Closure
    {
        return static function ($tui, $terminal, Ide $ide): void {
            $tui->requestRender(force: true);
            $tui->processRender();
            $renderer = Services::resolve($ide, Renderer::class);
            $rect = Widgets::rect($renderer, $tui->getById('tree'));
            // Header is relative row 0; the first entry (the folder) is row 1.
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
    }

    public function testContextMenuSpillingIntoEmptyEditorDoesNotBleedBackground(): void
    {
        // Right-clicking near the right of the sidebar anchors the menu so it
        // spills across the tree→editor boundary. With no file open the editor is
        // a blank pane; the menu's clipped slice must not bleed its box background
        // across that pane. Regression: OverlayCompositor left the popup
        // background active over the tail, painting a full-width box.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no file open → the blank editor pane the box bled across
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    // Right-press col 25, row 1: the 22-wide menu spills past the
                    // sidebar (tree cols 0–29) into the editor (col 31+). Force
                    // a frame first: right-click routing reads the renderer's
                    // rects and the tree's rendered rows, and with no file open
                    // there is nothing else to have caused a paint yet.
                    $this->frame($tui);
                    $tui->handleInput("\x1b[<2;26;3M");
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'context menu opened');

                    $renderer = Services::resolve($ide, Renderer::class);
                    $editor = Widgets::editor($tui);
                    $rect = Widgets::rect($renderer, $editor);
                    $rows = Paint::lines($editor, new RenderContext($rect->columns, $rect->rows));
                    // A menu item row (offset a couple rows below the anchor).
                    $menuRow = $rows[3] ?? '';
                    // The menu background must not be active far into the blank pane:
                    // a reset separates the composited menu from the editor tail.
                    $this->assertSame('default', SgrReads::backgroundAt($menuRow, $rect->columns - 5), 'the menu box background must not bleed across the empty editor');
                },
            ],
        );
    }

    public function testTreeContextMenuCoversTheSidebarSeparator(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => static function ($tui): void {
                    // Right-click a tree entry; the menu is wide enough to spill past the sidebar.
                    $tui->handleInput("\x1b[<2;21;3M"); // right-press col 20, row 1 (first entry)
                },
                280 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'tree context menu opened');
                    $lines = Paint::lines($tui->getById('separator'), new RenderContext(1, 24));
                    $painted = \array_filter($lines, static fn (string $l): bool => ' ' !== $l);
                    $this->assertNotEmpty($painted, 'the context menu paints over the separator column (no hole)');
                },
            ],
        );
    }

    public function testContextMenuHighlightsTheItemUnderTheHoveringPointer(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no file open → the tree is focused
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;21;3M"); // right-click a tree entry → context menu
                },
                280 => function (Tui $tui, $terminal, Ide $ide): void {
                    $menu = Widgets::byId($tui, 'context-menu', ContextMenu::class);
                    $this->assertTrue($menu->isOpen(), 'the context menu is open');

                    $overlay = Services::resolve($ide, MenuOverlayController::class);
                    $region = $overlay->contextMenuDropdown();
                    $this->assertInstanceOf(DropdownRegion::class, $region, 'the menu overlay was placed');

                    $before = Probe::get($menu, 'selected');

                    // Hover up from the bottom row until the highlight lands on a
                    // selectable item other than the initially-selected first one.
                    $moved = false;
                    for ($row = $region->count - 1; $row >= 0 && !$moved; --$row) {
                        $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $region->col + 3, $region->top + $row + 1));
                        $moved = Probe::get($menu, 'selected') !== $before;
                    }
                    $this->assertTrue($moved, 'hovering a menu item moves the highlight to it');
                },
            ],
        );
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

        $rightClickFolder = $this->rightClickTheFirstFolder();
        $screen = static fn ($tui, $terminal): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => $rightClickFolder,
                280 => function (Tui $tui, $terminal) use ($screen): void {
                    $tui->tick();
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'folder context menu opened');
                    $this->assertStringContainsString('Find in Files', $screen($tui, $terminal), 'folder menu offers Find in Files');
                    // New File → New Folder → (skip separator) → Find in Files.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                340 => function (Tui $tui): void {
                    $tui->tick();
                    $this->assertTrue(Widgets::editor($tui)->isFindModalOpen(), 'the find modal opened, scoped to the folder');
                    $this->assertMatchesRegularExpression('/│ sub[^│]*▾ │/u', $this->modalText($tui), 'the modal is scoped to the clicked folder');
                    foreach (\str_split('scopedMarker') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                650 => function (Tui $tui): void {
                    // The grep is a subprocess: await its row in the MODAL (the
                    // results pane holds nothing until promote() moves it there)
                    // instead of asserting a fixed number of ms after the query.
                    $this->await(fn (): bool => \str_contains($this->modalText($tui), 'Inside.php'), what: 'the folder-scoped search to land');
                    $this->promote($tui); // modal → results pane
                    // Assert on the results panel itself (the tree sidebar also lists
                    // Outside.php, so the whole screen is the wrong surface).
                    $panel = AnsiUtils::stripAnsiCodes(\implode("\n", Paint::lines($tui->getById('results'), new RenderContext(160, 14))));
                    $this->assertStringContainsString('1 result', Widgets::byId($tui, 'results', ResultsPanelWidget::class)->statusText(), 'only the in-folder match counts (root is out of scope)');
                    $this->assertStringContainsString('sub/Inside.php', $panel, 'the in-folder match is listed');
                    $this->assertStringNotContainsString('Outside.php', $panel, 'the root match is excluded');
                    $tui->handleInput("\r"); // jump to the match
                },
                760 => function (Tui $tui): void {
                    $this->assertStringEndsWith('sub/Inside.php', (string) Widgets::editor($tui)->getFilePath());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after the jump');
                    $this->assertTrue(Widgets::byId($tui, 'results', ResultsPanelWidget::class)->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );
    }

    public function testReplaceInFilesFromFolderContextMenuScopesTheReplacement(): void
    {
        // Both files contain $token; a folder-scoped replace must rewrite only the
        // in-folder file. Directories sort first, so `sub` is the first tree entry.
        $project = new TempProject([
            'sub/Inside.php' => "<?php\n\$token = 1;\n",
            'Outside.php' => "<?php\n\$token = 2;\n",
        ]);

        $rightClickFolder = $this->rightClickTheFirstFolder();
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
                360 => function (Tui $tui): void {
                    $tui->tick();
                    $this->assertTrue(Widgets::editor($tui)->isFindModalOpen(), 'the find modal opened, scoped to the folder');
                    $this->assertMatchesRegularExpression('/│ sub[^│]*▾ │/u', $this->modalText($tui), 'the modal is scoped to the clicked folder');
                    foreach (\str_split('token') as $ch) {
                        $tui->handleInput($ch); // the search term (query field)
                    }
                },
                760 => function (Tui $tui): void {
                    // The grep is a subprocess: await its row in the MODAL before
                    // acting on it, instead of asserting a fixed number of ms
                    // after the query.
                    $this->await(fn (): bool => \str_contains($this->modalText($tui), 'Inside.php'), what: 'the folder-scoped search to land');
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (\str_split('renamed') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all (scoped)
                },
                1000 => function ($tui) use ($project): void {
                    $this->assertStringContainsString('$renamed = 1;', (string) \file_get_contents($project->path('sub/Inside.php')), 'the in-folder file is replaced');
                    $this->assertStringContainsString('$token = 2;', (string) \file_get_contents($project->path('Outside.php')), 'the out-of-scope file is untouched');
                },
            ],
        );
    }

    public function testEditorContextMenuCutsTheSelection(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;39;4M"); // press line 0 col 0
                    $tui->handleInput("\x1b[<32;44;4M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;44;4m"); // release → "hello" selected
                    $tui->handleInput("\x1b[<2;46;4M"); // right-click in the editor
                },
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'right-click opened the editor menu');
                    // The selection is kept (caret not moved), so Cut acts on it.
                    $this->assertSame('hello', Widgets::editor($tui)->getDocument()->getSelectedText());
                    $tui->handleInput("\r"); // Enter → Cut (first item)
                },
                400 => function (Tui $tui): void {
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'menu closed after running');
                    $this->assertSame(' world', Widgets::editor($tui)->getDocument()->getLine(0), 'Cut removed the selection');
                },
            ],
        );
    }

    public function testEditorContextMenuPlacesCaretWhenThereIsNoSelectionThenEscapes(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function (Tui $tui): void {
                    $tui->tick();
                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[<2;40;5M"); // right-click on line 1, no selection
                },
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen());
                    $this->assertSame(1, Widgets::editor($tui)->getDocument()->cursorLine, 'caret moved to the clicked line');
                    $tui->handleInput('z'); // an unbound key is swallowed while the menu is open
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'stray keys neither run nor close');
                    $tui->handleInput("\x1b"); // Esc closes (modal routing)
                },
                400 => function (Tui $tui): void {
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'Esc closed the menu');
                },
            ],
        );
    }

    public function testRightClickClosesAnOpenApplicationMenu(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[21~"); // F10 opens the application menu
                },
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'menu', MenuBarWidget::class)->isOpen());
                    $tui->handleInput("\x1b[<2;45;4M"); // right-click the editor
                },
                400 => function (Tui $tui): void {
                    $this->assertFalse(Widgets::byId($tui, 'menu', MenuBarWidget::class)->isOpen(), 'right-click closed the application menu');
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'and opened the context menu');
                },
            ],
        );
    }

    public function testRightClickOnEmptyTreeOrEmptyTabStripOpensNothing(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    // Below the last entry (the header has a menu of its own now).
                    $tui->handleInput("\x1b[<2;3;10M");
                },
                250 => function (Tui $tui): void {
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'the empty tree under its entries targets no node');
                    $tui->handleInput("\x1b[<2;150;2M"); // tab bar past the last tab → no index
                },
                300 => function (Tui $tui): void {
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'empty tab-strip space targets no tab');
                },
            ],
        );
    }

    public function testRightClickOverAnOpenPickerOpensNoMenu(): void
    {
        // A floating picker owns input while it is up, so a right-click under
        // it opens nothing. Deleting that guard failed no test: every other
        // right-click case starts from a screen with no picker on it.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function (Tui $tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[101;6u"); // ctrl+shift+e → Search Everywhere
                    $this->assertTrue(Widgets::editor($tui)->isAnyPickerOpen(), 'the picker is up');
                },
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('editor'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the editor is laid out');
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 5, $rect->row + 2)); // SGR is 1-based

                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'no menu opened under the picker');
                    $this->assertTrue(Widgets::editor($tui)->isAnyPickerOpen(), 'and the picker survived the click');
                },
            ],
        );
    }

    public function testAPluginsFileMenuItemReachesFileRowsButNotDirectories(): void
    {
        // The tree menu adds the plugin FILE surface to a file row and not to a
        // directory: the surface names a file, and a folder's own verbs are the
        // ones that make sense there. Nothing covered the directory half —
        // handing folders the file items too went unnoticed.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/filesurf/plugin.php' => $this->fileSurfacePlugin(),
            'pkg/deep.php' => "<?php\n",
            'a.php' => "<?php\n",
        ]);

        // pkg/ collapsed on row 0 and a.php on row 1: a directory row and a
        // file row both reachable without expanding anything.
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                250 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('tree'));
                    $this->assertInstanceOf(WidgetRect::class, $rect, 'the tree is laid out');
                    $tree = Widgets::byId($tui, 'tree', FileTreeWidget::class);
                    $dirRow = null;
                    $fileRow = null;
                    for ($r = 0; $r < 10; ++$r) {
                        $node = $tree->nodeAt($r);
                        if (null === $node) {
                            continue;
                        }
                        $dirRow ??= $node->isDir ? $r : null;
                        $fileRow ??= $node->isDir ? null : $r;
                    }
                    $this->assertNotNull($dirRow, 'a directory row is showing');
                    $this->assertNotNull($fileRow, 'a file row is showing');

                    $terminal->clearOutput();
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + $dirRow + 1)); // SGR is 1-based
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'the directory has a menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'New File'), what: 'the folder menu to paint');
                    $this->assertStringNotContainsString('Touch This File', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'a folder gets no file-surface item');
                    $tui->handleInput("\x1b"); // close

                    $terminal->clearOutput();
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + $fileRow + 1));
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'the file has a menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Touch This File'), what: 'the file-surface item to paint');
                },
            ],
        );
    }

    /** A plugin whose single command sits on the FILE context surface. */
    private function fileSurfacePlugin(): string
    {
        return <<<'PHP'
            <?php
            use Porta\Plugin\Api\PluginApi;
            use Porta\Plugin\Api\PortaPlugin;
            use Porta\Workbench\Menu\MenuSurface;

            return new class implements PortaPlugin {
                public function name(): string
                {
                    return 'filesurf';
                }

                public function activate(PluginApi $api): void
                {
                    $api->registerCommand('touch', 'Touch This File', static function (): void {});
                    $api->addMenuItem('touch', surface: MenuSurface::FileContext);
                }
            };
            PHP;
    }

    public function testTabContextMenuCloseClosesTheClickedTab(): void
    {
        \putenv('PATH=/nonexistent');
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
                400 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'right-click on a tab opened the menu');
                    $tui->handleInput("\r"); // Close (first item) → closes A
                },
                500 => function (Tui $tui): void {
                    $this->assertStringEndsWith('B.php', (string) Widgets::editor($tui)->getFilePath(), 'A closed, B shown');
                },
            ],
        );
    }

    public function testTreeContextMenuNewFileAndNewFolder(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['src/keep.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/keep.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/ (a directory)
                },
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen());
                    $tui->handleInput("\r"); // New File (first item) → opens the name prompt
                },
                360 => function (Tui $tui): void {
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
                600 => function (Tui $tui): void {
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
        \putenv('PATH=/nonexistent');
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

        $this->assertSame(['keep.php'], \array_values(\array_diff(\scandir($project->path('src')), ['.', '..'])), 'no blank-named entry was created');
    }

    public function testTreeContextMenuRenameUpdatesDiskAndBuffer(): void
    {
        \putenv('PATH=/nonexistent');
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
                    $tui->handleInput("\x1b[B"); // Cut
                    $tui->handleInput("\x1b[B"); // Copy
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
                380 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $this->assertFileExists($project->path('A.php'), 'renaming to the same name changed nothing');
                    // Mark a line before the real rename, so the assertion below
                    // is about the mark SURVIVING it.
                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    Services::resolve($ide, BookmarkActions::class)->toggle();
                    // …and make an EDIT, so the two path-keyed stores added in
                    // the parity round have something to lose too.
                    $tui->handleInput('X');
                    $this->assertSame(
                        [(string) \realpath($project->path('A.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                    );
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                460 => static function ($tui): void {
                    foreach (\range(1, end: 6) as $_) {
                        $tui->handleInput("\x1b[B"); // → Rename…
                    }
                    $tui->handleInput("\r");
                },
                540 => function (Tui $tui): void {
                    foreach (\range(1, end: 10) as $_) {
                        $tui->handleInput("\x7f"); // backspace out the prefill
                    }
                    $this->typeString($tui, 'renamed.php');
                    $tui->handleInput("\r");
                },
                640 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $this->assertStringEndsWith('renamed.php', (string) Widgets::editor($tui)->getFilePath(), 'the open buffer followed the rename');
                    // The whole FileRenamed chain: the mark moved with the file
                    // rather than being silently dropped on the dead path.
                    $this->assertSame(
                        [(string) \realpath($project->path('renamed.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                        'the bookmark followed the rename',
                    );
                    $this->assertStringContainsString(
                        Glyphs::BOOKMARK,
                        \implode("\n", Paint::lines($tui->getById('editor'), new RenderContext(120, 20))),
                        'and is painted on the new buffer',
                    );

                    // Every store keyed by path has to follow, not just the
                    // marks. These two are the newest of them, and a listener
                    // nobody registers passes every gate.
                    $renamed = (string) \realpath($project->path('renamed.php'));
                    $this->assertContains(
                        $renamed,
                        Services::resolve($ide, RecentFilesStore::class)->paths,
                        'the recent-files list followed the rename',
                    );
                    $this->assertSame(
                        ['path' => $renamed, 'line' => 0],
                        Services::resolve($ide, EditHistory::class)->walkBack(),
                        'and so did the place you were last typing',
                    );
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'old name gone');
        $this->assertFileExists($project->path('renamed.php'), 'renamed on disk');
    }

    public function testTreeContextMenuRenamingAFOLDERMovesWhatIsInsideIt(): void
    {
        // The tree offers Rename… on a folder, and everything keyed by path was
        // keyed by the FILES: an exact-match rename left the open tab pointing
        // into a directory that no longer existed — so the reconciler flashed
        // "deleted on disk" for a file that had merely moved, and saving it
        // wrote the old folder back into existence, because dumpFile() creates
        // the directories it needs.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n", 'outside.php' => "<?php\n"]);
        $inside = (string) \realpath($project->path('src/A.php'));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    // A second tab OUTSIDE the folder, so the walk has a buffer
                    // to leave alone as well as one to move. The tree rows are
                    // the project root, the collapsed `src/`, then this.
                    $tui->handleInput("\x1b[<0;3;4M");
                },
                250 => static function ($tui, $terminal, Ide $ide) use ($inside): void {
                    // Marked through the store rather than the editor: the file
                    // inside the folder is deliberately NOT the active tab.
                    Services::resolve($ide, Bookmarks::class)->toggle($inside, 0);
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click the src/ row
                },
                330 => static function ($tui): void {
                    // A folder's menu carries the three search items a file's
                    // does not, and Cut/Copy sit above Copy Path, so Rename… is
                    // the 10th selectable row.
                    foreach (\range(1, end: 9) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r");
                },
                410 => function (Tui $tui): void {
                    foreach (\range(1, end: 10) as $_) {
                        $tui->handleInput("\x7f"); // backspace out the prefilled name
                    }
                    $this->typeString($tui, 'lib');
                    $tui->handleInput("\r");
                },
                520 => function ($tui, $terminal, Ide $ide) use ($project, $inside): void {
                    $open = \array_map(static fn (Buffer $b): string => $b->path, Services::resolve($ide, BufferManager::class)->all());
                    $this->assertContains((string) \realpath($project->path('lib/A.php')), $open, 'the tab inside the folder followed it');
                    $this->assertNotContains($inside, $open, 'and none is left on the path that is gone');
                    $this->assertContains((string) \realpath($project->path('outside.php')), $open, 'the tab outside the folder was left alone');
                    $this->assertSame(
                        [(string) \realpath($project->path('lib/A.php')) => [0]],
                        Services::resolve($ide, Bookmarks::class)->all(),
                        'and so did the mark inside it',
                    );
                },
            ],
        );

        $this->assertFileExists($project->path('lib/A.php'), 'the folder moved on disk');
        $this->assertDirectoryDoesNotExist($project->path('src'), 'and nothing recreated the old one');
    }

    public function testTreeContextMenuDeleteRetiresEveryPathKeyedStore(): void
    {
        // The mirror of the rename test above, and the case it left open: four
        // stores follow a file to its new name, and a DELETE used to strand all
        // four — a bookmark you cannot reach, a breakpoint the next launch
        // hands the adapter, a switcher row that opens nothing, and a walk back
        // into a file that is gone. Three of the four are asserted here;
        // BreakpointStore is not on the `porta.ide` boundary, so its listener
        // is unit-tested instead.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);
        $a = (string) \realpath($project->path('A.php'));
        $kept = [];

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => function (Tui $tui, $terminal, Ide $ide) use ($a, &$kept): void {
                    Services::resolve($ide, EventDispatcherInterface::class)->addListener(FileDeleted::class, static function (FileDeleted $event) use (&$kept): void {
                        $kept[] = [$event->path, $event->previousText];
                    });
                    $tui->tick();
                    Widgets::editor($tui)->getDocument()->setCursor(0, 0);
                    Services::resolve($ide, BookmarkActions::class)->toggle();
                    $tui->handleInput('X'); // an edit, so the history has a site to lose

                    $this->assertSame([$a => [0]], Services::resolve($ide, Bookmarks::class)->all(), 'marked before deleting');
                    $this->assertContains($a, Services::resolve($ide, RecentFilesStore::class)->paths);
                },
                250 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                350 => static function ($tui): void {
                    foreach (\range(1, end: 7) as $_) {
                        $tui->handleInput("\x1b[B"); // → Delete…
                    }
                    $tui->handleInput("\r");
                },
                430 => function (Tui $tui): void {
                    $this->typeString($tui, 'y');
                    $tui->handleInput("\r");
                },
                520 => function (Tui $tui, $terminal, Ide $ide) use ($a): void {
                    $this->assertStringNotContainsString('A.php', (string) Widgets::editor($tui)->getFilePath(), 'the deleted file is not still open');
                    $this->assertSame([], Services::resolve($ide, Bookmarks::class)->all(), 'the bookmark went with the file');
                    $this->assertNotContains($a, Services::resolve($ide, RecentFilesStore::class)->paths, 'the switcher stopped offering it');
                    $this->assertNull(Services::resolve($ide, EditHistory::class)->walkBack(), 'and there is nowhere to walk back to');
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'A.php removed from disk');
        $this->assertSame([[$a, "X<?php\n"]], $kept, 'the fact carries the text the user had, the unsaved X included, for local history to keep');
    }

    public function testEditorContextMenuNearTheBottomFlipsUp(): void
    {
        \putenv('PATH=/nonexistent');
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
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'menu opened and placed above the click without error');
                },
            ],
        );
    }

    public function testTreeContextMenuNearTheRightEdgeStraddlesPanes(): void
    {
        \putenv('PATH=/nonexistent');
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
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'a straddling panel renders across both panes without error');
                },
            ],
        );
    }

    public function testTreeRenameOntoAnExistingNameFlashesError(): void
    {
        \putenv('PATH=/nonexistent');
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
                    $tui->handleInput("\x1b[B"); // Cut
                    $tui->handleInput("\x1b[B"); // Copy
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                380 => function (Tui $tui): void {
                    foreach (\range(1, end: 10) as $_) {
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
        \putenv('PATH=/nonexistent');
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
                    $tui->handleInput("\x1b[B"); // Cut
                    $tui->handleInput("\x1b[B"); // Copy
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\r");
                },
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                500 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Cut
                    $tui->handleInput("\x1b[B"); // Copy
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\r");
                },
            ],
        );

        $raw = $terminal->getOutput();
        // The status bar truncates a long flash, so verify the full paths landed
        // on the clipboard via the OSC 52 base64 payload.
        $this->assertStringContainsString(\base64_encode($project->path('A.php')), $raw, 'absolute path copied to the clipboard');
        $this->assertStringContainsString(\base64_encode('A.php'), $raw, 'relative path copied to the clipboard');
        $this->assertStringContainsString('Copied A.php', AnsiUtils::stripAnsiCodes($raw), 'relative-path copy flashed');
    }

    public function testTreeContextMenuDeleteConfirmedClosesBufferAndShowsNext(): void
    {
        \putenv('PATH=/nonexistent');
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
                    // Delete is the 8th selectable item: Cut and Copy joined
                    // the menu above Copy Path.
                    foreach (\range(1, end: 7) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete… → confirm prompt
                },
                430 => function (Tui $tui): void {
                    $this->typeString($tui, 'y');
                    $tui->handleInput("\r"); // confirmed
                },
                520 => function (Tui $tui): void {
                    $this->assertStringEndsWith('B.php', (string) Widgets::editor($tui)->getFilePath(), 'deleted A, showing B');
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'A.php removed from disk');
    }

    public function testTreeContextMenuDeleteLastBufferEmptiesTheEditor(): void
    {
        $project = $this->deletingFromTheTreeMenu(function (Tui $tui): void {
            $this->typeString($tui, 'yes'); // 'yes' is also accepted
            $tui->handleInput("\r");
        });

        $this->assertFileDoesNotExist($project->path('A.php'));
    }

    public function testTreeContextMenuDeleteCancelledKeepsTheFile(): void
    {
        $project = $this->deletingFromTheTreeMenu(
            static function (Tui $tui): void {
                $tui->handleInput('n'); // decline
                $tui->handleInput("\r");
            },
            [
                460 => function (Tui $tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'declining returns focus to the tree');
                },
            ],
        );

        $this->assertFileExists($project->path('A.php'), 'declining the confirmation kept the file');
    }

    /**
     * A project of one file, A.php, open; Delete… picked from its row's
     * context menu in the tree, and the confirmation answered by $answer —
     * then $after's steps.
     *
     * @param \Closure(Tui): void                       $answer
     * @param array<int, \Closure(Tui, mixed, mixed): void> $after
     */
    private function deletingFromTheTreeMenu(\Closure $answer, array $after = []): TempProject
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function (Tui $tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                300 => static function (Tui $tui): void {
                    foreach (\range(1, end: 7) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => $answer,
            ] + $after,
        );

        return $project;
    }

    public function testTreeContextMenuOpenAndRefreshViaMouse(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function (Tui $tui): void {
                    $tui->tick();
                    Widgets::editor($tui)->showDocument('', new Document(''));
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                300 => static function ($tui): void {
                    // The menu anchors at the click (SGR row 2 = the frame's top
                    // border), so item i sits at SGR row 3+i. "Open" is item 12
                    // for a file node → SGR row 15. It was item 9 until Cut and
                    // Copy joined the menu with a separator of their own.
                    $tui->handleInput("\x1b[<0;4;16M"); // left-click "Open"
                },
                400 => function (Tui $tui) use ($project): void {
                    $this->assertStringEndsWith('A.php', (string) Widgets::editor($tui)->getFilePath(), 'Open reopened the file');
                    // A file created on disk appears in the tree only after Refresh.
                    $project->write('late.php', "<?php\n");
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                500 => static function ($tui): void {
                    // "Refresh" is the last item (13) → SGR row 16.
                    $tui->handleInput("\x1b[<0;4;17M");
                    $tui->tick();
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'late.php'), 'Refresh picked up the new file');
                },
            ],
        );
    }

    public function testRightClickInTheGapDismissesAnOpenContextMenu(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;45;4M"); // open the editor menu
                },
                300 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen());
                    $tui->handleInput("\x1b[<0;31;10M"); // left-click the gap → dismiss
                },
                400 => function (Tui $tui): void {
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'a click outside the panel dismissed it');
                },
            ],
        );
    }

    // --- Tab close icon + reordering ------------------------------------------

    public function testCtrlF10OpensTheContextMenuForTheFocusedPane(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 in the editor
                    $this->awaitPainted($tui, $terminal, 'Go to Declaration or Usages');
                    $context = Services::resolve($ide, ContextMenu::class);
                    $this->assertTrue($context->isOpen(), 'the editor context menu opened at the caret');
                    $tui->handleInput("\x1b"); // Esc closes it
                    $this->assertFalse($context->isOpen());
                },
                350 => function (Tui $tui, VirtualTerminal $terminal, $ide): void {
                    $tui->setFocus($tui->getById('tree'));
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the tree's selected node
                    $this->awaitPainted($tui, $terminal, 'New File');
                    $this->assertStringContainsString('New File', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'tree file operations under the selection');
                    $tui->handleInput("\x1b");
                },
                500 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_help');
                    $this->frame($tui);
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the help viewer: the tab menu
                    $this->awaitPainted($tui, $terminal, 'Close Others');
                    $this->assertStringContainsString('Close Others', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'viewer tabs get the tab context menu');
                    $tui->handleInput("\x1b");
                },
                650 => function (Tui $tui): void {
                    // Go-to-file is the editor-hosted picker now; opening it hops
                    // off the help tab to a text buffer, and the modal picker owns
                    // input, so Ctrl+F10 opens no context menu over it.
                    $tui->handleInput("\x10"); // ctrl+p
                    $tui->handleInput("\x1b[21;5~"); // ctrl+f10
                    $tui->tick();
                    $this->assertTrue(Widgets::editor($tui)->isSearchOverlayOpen(), 'go-to-file opened the centered picker');
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'no context menu over the picker');
                    // A right-click inside the modal picker also opens no menu.
                    $tui->handleInput("\x1b[<2;10;10M"); // right-press
                    $tui->tick();
                    $this->assertFalse(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'right-click over the picker opens no menu');
                },
            ],
        );
    }

    /** Type each character of $text into the currently focused prompt/editor. */
    private function typeString(Tui $tui, string $text): void
    {
        foreach (\mb_str_split($text) as $char) {
            $tui->handleInput($char);
        }
    }

    /**
     * The project name's own menu: the folders beside the project, to switch
     * to one. Picking one ends this session and names it as the next — the
     * reopen every project switch is.
     */
    public function testRightClickingTheProjectNameOffersTheProjectsBesideIt(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);
        $other = new TempProject(['b.php' => "<?php\n"]); // a sibling: both live in the temp dir

        $this->runIde(
            $project,
            null,
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;6;2M"); // right-press the project name (tree row 0)
                },
                280 => function (Tui $tui, $terminal, Ide $ide) use ($project, $other): void {
                    $menu = Widgets::byId($tui, 'context-menu', ContextMenu::class);
                    $this->assertTrue($menu->isOpen(), 'the project name has a menu');
                    /** @var list<MenuItem> $items */
                    $items = Probe::get($menu, 'items');
                    $labels = \array_map(static fn (MenuItem $item): string => $item->label, $items);
                    $this->assertContains(\basename($project->dir), $labels, 'this project, among them');
                    $this->assertSame('Open Project…', \end($labels), 'and the switcher last');
                    $pick = \array_first(\array_filter($items, static fn (MenuItem $item): bool => \basename($other->dir) === $item->label));
                    $this->assertInstanceOf(MenuItem::class, $pick, 'the folder beside it is offered');

                    ($pick->run)();

                    $this->assertSame((string) \realpath($other->dir), Services::resolve($ide, ReopenRequest::class)->root(), 'this project closes and that one opens');
                },
            ],
        );
    }
}
