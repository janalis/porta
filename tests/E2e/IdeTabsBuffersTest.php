<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Editor\PreviewMode;
use Porta\Ide;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Widget\ContextMenu;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Tab strip and buffer lifecycle: switching, closing, reordering,
 * the vertical tab list and tab-bar positions.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeTabsBuffersTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use PreservesPortaHome;
    use RunsIde;
    use ScreenReads;

    public function testDoubleClickingATabRevealsTheFileInTheTree(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'src/deep/Target.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    // Open the deep file as a background tab (does not reveal it).
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('src/deep/Target.php'), OpenFocus::Keep);
                    $tui->requestRender(force: true);
                    $tui->processRender();

                    $tree = Widgets::byId($tui, 'tree', FileTreeWidget::class);
                    $this->assertNotSame('Target.php', $tree->selectedNode()?->name, 'opening a file does not reveal it in the tree');

                    $tabs = Widgets::byId($tui, 'tabs', TabBarWidget::class);
                    $rect = Widgets::rectIn($ide, $tabs);
                    $anchor = $tabs->tabAnchor(1); // the Target.php tab
                    $this->assertNotNull($anchor);
                    $col = $rect->col + $anchor['col'] + 2;
                    $row = $rect->row + $anchor['row'] + 1;
                    // A double-click: two press/release pairs at the same cell.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $row));
                    $tui->tick();

                    $this->assertSame('Target.php', $tree->selectedNode()?->name, 'the double-click revealed and selected the deep file');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'focus stayed in the editor');
                },
            ],
        );
    }

    public function testBufferCloseFlows(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1bw"); // close a.php (clean): no buffers left -> tree focused
                },
                350 => function (Tui $tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $this->assertNull(Widgets::editor($tui)->getFilePath());
                    // Reopen both files (opening focuses the editor, so refocus the tree)
                    $tui->handleInput("\r"); // a.php
                    $tui->handleInput("\x14"); // ctrl+t back to tree
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // b.php
                },
                550 => static function ($tui): void {
                    $tui->handleInput('E'); // dirty b.php
                    $tui->handleInput("\x1bw"); // close: opens the unsaved-changes dialog
                },
                700 => function ($tui, $terminal): void {
                    $this->assertTrue(EditorState::pickerOpen($tui->getById('editor')));
                    $this->assertStringContainsString('Unsaved changes in b.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b"); // cancel via escape: buffer stays
                    $tui->handleInput("\x1bw"); // reopen the dialog
                    $tui->handleInput("\x1b[B"); // -> Discard & Close
                    $tui->handleInput("\r");
                },
                850 => function (Tui $tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) Widgets::editor($tui)->getFilePath());
                    $this->assertStringNotContainsString('E', (string) \file_get_contents($project->path('b.php')), 'discarded edit not written');
                    $tui->handleInput("\x1bw"); // close the last one: tree gets focus
                    $tui->handleInput("\x14"); // ctrl+t back to the (empty) editor
                    $tui->handleInput("\x1bw"); // close with no active buffer: no-op
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
        );

        $this->assertStringContainsString('open a file from the tree', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testCtrlUJumpsToTheSuperMethodAndSaysWhichOne(): void
    {
        // The binding reaching the action is its own fact: a command can be
        // registered, adopted, menu-listed and covered, and still never answer
        // the key — which is exactly how the last feature nearly shipped.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'src/Base.php' => "<?php\nnamespace App;\nabstract class Base\n{\n    public function handle(): void\n    {\n    }\n}\n",
            'src/Child.php' => "<?php\nnamespace App;\nfinal class Child extends Base\n{\n    public function handle(): void\n    {\n    }\n}\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Child.php'),
            [
                300 => static function (Tui $tui): void {
                    Widgets::editor($tui)->getDocument()->setCursor(4, 8);
                    $tui->handleInput("\x15"); // ctrl+u
                },
                500 => function (Tui $tui, $terminal): void {
                    $editor = Widgets::editor($tui);

                    $this->assertStringEndsWith('Base.php', (string) $editor->getFilePath(), 'the parent file is open');
                    $this->assertSame(4, $editor->getDocument()->cursorLine, 'on its declaration');
                    $this->assertStringContainsString(
                        'Super method: Base::handle',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'and it names what it found, because the jump alone does not say why',
                    );
                },
            ],
        );
    }

    public function testRecentFilesSaysSoWhenThereIsNowhereToSwitch(): void
    {
        // One file, one session: the switcher has nothing to offer, and it used
        // to swallow the keystroke whole. On the first file of a session that
        // reads as a broken binding rather than as an empty list.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, $terminal): void {
                    $terminal->clearOutput();
                    $tui->handleInput("\x05"); // ctrl+e
                    $this->frame($tui);

                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')), 'no picker with nowhere to go');
                    $this->assertStringContainsString(
                        'No other files to switch to',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'and it says so',
                    );
                },
            ],
        );
    }

    public function testRecentFilesOffersAFileThatIsNoLongerOpen(): void
    {
        // The half a BufferManager cannot answer: closing the tab erases the
        // file from `recent()`, and it is usually the one you want back. Driven
        // through the running IDE because the store, the recorder and the
        // switcher are three pieces and only the join is the feature.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('b.php'), OpenFocus::Editor);
                },
                300 => function (Tui $tui): void {
                    $this->assertStringEndsWith('b.php', (string) Widgets::editor($tui)->getFilePath());
                    $tui->handleInput("\x1bw"); // alt+w: close b.php, leaving only a.php open
                },
                400 => function (Tui $tui, $terminal): void {
                    $this->assertStringEndsWith('a.php', (string) Widgets::editor($tui)->getFilePath());
                    $terminal->clearOutput();
                    $tui->handleInput("\x05"); // ctrl+e
                },
                500 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->assertTrue(EditorState::pickerOpen($tui->getById('editor')));
                    // The CARD, not the screen: `b.php` is in the file tree
                    // beside the picker, so a screen-wide assertion passes
                    // whether the switcher offers it or nothing at all —
                    // measured, by replacing every label and watching this
                    // stay green.
                    $card = $this->card($this->screenGrid($tui, $terminal), 'Recent Files');
                    $this->assertStringContainsString('b.php', $card, 'the closed file is still on offer');
                    // Pre-selected, exactly as the previously-open file would be.
                    $tui->handleInput("\r");
                },
                600 => function (Tui $tui): void {
                    $this->assertStringEndsWith('b.php', (string) Widgets::editor($tui)->getFilePath(), 'picking it opened it again');
                },
            ],
        );
    }

    public function testAScratchFileIsMintedOutsideTheProjectAndOpened(): void
    {
        // Through the running IDE: the action, its binding, the language
        // picker and the file all have to meet, and a command nothing dispatches
        // passes every other gate green.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);
        $home = new TempProject([], prefix: 'porta-test-home-');
        \putenv('PORTA_HOME='.$home->dir);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[115;8u"); // ctrl+alt+shift+s
                },
                300 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('New Scratch File', $screen);
                    $this->assertStringContainsString('PHP', $screen);
                    $tui->handleInput("\r"); // PHP is first and preselected
                },
                400 => function (Tui $tui) use ($project, $home): void {
                    $path = $home->path('.porta/scratches/scratch_1.php');
                    $this->assertFileExists($path);
                    // Ends-with, not same: opening realpath()s, and on macOS
                    // the temp dir gains a `/private` prefix on the way.
                    $active = (string) Widgets::editor($tui)->getFilePath();
                    $this->assertStringEndsWith('/.porta/scratches/scratch_1.php', $active, 'the scratch is the active buffer');
                    $this->assertStringStartsNotWith($project->dir, $active, 'and it is not in the project');
                },
            ],
        );
    }

    public function testBufferSwitchReusesHighlightCache(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x14");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php
                },
                500 => function (Tui $tui): void {
                    // Switching back must render a.php's highlight from the
                    // buffer-owned cache (same version, no re-parse).
                    $editorBefore = Widgets::editor($tui);
                    $versionBefore = $editorBefore->getDocument()->version;
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right -> a.php
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) Widgets::editor($tui)->getFilePath());
                    $this->assertSame(1, $versionBefore, 'no phantom edits from switching');
                },
            ],
        );
    }

    public function testTabBarPositionSwitchesLiveAndPersists(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $changePosition = static function (Ide $ide, string $position): void {
            Services::resolve($ide, SettingsController::class)
                ->changeSetting('tabBarPosition', $position);
        };
        $rects = static function ($tui, Ide $ide): array {
            $renderer = Services::resolve($ide, Renderer::class);

            return [$renderer->getWidgetRect($tui->getById('tabs')), $renderer->getWidgetRect($tui->getById('editor'))];
        };

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($rects): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(1, $tabs->rows, 'top: a one-row strip');
                    $this->assertLessThan($editor->row, $tabs->row, 'top: the strip sits above the editor');
                },
                400 => static function ($tui, $terminal, $ide) use ($changePosition): void {
                    $changePosition($ide, position: 'bottom');
                    $tui->tick();
                },
                600 => function ($tui, $terminal, $ide) use ($rects, $changePosition): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(1, $tabs->rows, 'bottom: still a one-row strip');
                    $this->assertGreaterThan($editor->row + $editor->rows - 1, $tabs->row, 'bottom: the strip sits below the editor');
                    $changePosition($ide, position: 'left');
                    $tui->tick();
                },
                800 => function ($tui, $terminal, $ide) use ($rects, $changePosition): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(TabBarWidget::VERTICAL_WIDTH, $tabs->columns, 'left: a fixed-width vertical list');
                    $this->assertGreaterThan(1, $tabs->rows, 'left: the list fills the pane height');
                    $this->assertLessThan($editor->col, $tabs->col, 'left: the list sits left of the editor');
                    $changePosition($ide, position: 'right');
                    $tui->tick();
                },
                1000 => function ($tui, $terminal, $ide) use ($rects): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(TabBarWidget::VERTICAL_WIDTH, $tabs->columns, 'right: the fixed-width list again');
                    $this->assertGreaterThan($editor->col, $tabs->col, 'right: the list sits right of the editor');
                },
            ],
        );

        $this->assertSame('right', IdeConfig::load($project->dir)->settings['tabBarPosition'], 'the position persisted');
    }

    public function testAnUnknownTabBarPositionValueFallsBackToTop(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['settings' => ['tabBarPosition' => 'sideways']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $renderer = Services::resolve($ide, Renderer::class);
                    $tabs = Widgets::rect($renderer, $tui->getById('tabs'));
                    $editor = Widgets::rect($renderer, $tui->getById('editor'));
                    $this->assertSame(1, $tabs->rows, 'an unknown persisted value boots the top strip');
                    $this->assertLessThan($editor->row, $tabs->row);
                },
            ],
        );
    }

    public function testVerticalTabListMouseInteractions(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n",
            'B.php' => "<?php\n",
            'C.php' => "<?php\n",
            'notes.md' => "# Notes\n",
            '.porta.json' => \json_encode(['settings' => ['tabBarPosition' => 'left']], \JSON_THROW_ON_ERROR),
        ]);

        $tabsRect = static fn (Tui $tui, Ide $ide): WidgetRect => Widgets::rectIn($ide, $tui->getById('tabs'));

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($project->path('B.php'), OpenFocus::Editor);
                    $service->openFile($project->path('C.php'), OpenFocus::Editor);
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                },
                300 => function (Tui $tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // Left-click row 0's body → switch to A (SGR coords are 1-based).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 1));
                    $tui->tick();
                    $this->assertStringEndsWith('A.php', (string) Widgets::editor($tui)->getFilePath(), 'clicking a list row switches to that tab');
                },
                450 => function (Tui $tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // The ✖ of row 1 (B) sits in the right-aligned close zone.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + TabBarWidget::VERTICAL_WIDTH - 1, $rect->row + 2));
                    $tui->tick();
                    $this->assertSame(['A.php', 'C.php'], $this->tabNames($tui), "clicking a row's ✖ closed that tab");
                },
                600 => function (Tui $tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // Press row 0 (A), drag onto row 1 (C), release → A moves last.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + 3, $rect->row + 2));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 2));
                    $tui->tick();
                    $this->assertSame(['C.php', 'A.php'], $this->tabNames($tui), 'dragging down the list reordered the tabs');
                },
                750 => function (Tui $tui, VirtualTerminal $terminal, $ide) use ($tabsRect): void {
                    $terminal->clearOutput();
                    $rect = $tabsRect($tui, $ide);
                    // Right-click row 0 (C) → the tab context menu.
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $this->awaitPainted($tui, $terminal, 'Close Others');
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'right-clicking a list row opened the tab menu');
                    $tui->handleInput("\x1b"); // Esc closes it
                },
                900 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    // A content swap (a markdown file shown as its preview) lands
                    // inside the editor body, not beside the vertical list in the
                    // horizontal editor pane.
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($project->path('notes.md'), OpenFocus::Editor);
                    $active = Services::resolve($ide, BufferManager::class)->active();
                    $this->assertInstanceOf(Buffer::class, $active);
                    $service->arrangePreview($active, PreviewMode::Preview);
                    $tui->tick();
                    $this->assertContains($tui->getById('markdown-preview'), $tui->getById('editor-body')->all(), 'the preview swapped into the editor body slot');
                    $this->assertCount(2, $tui->getById('editor-pane')->all(), 'the editor pane keeps exactly the list and the body');
                },
            ],
        );
    }

    public function testKeyboardTabMenuAnchorsOnTheBottomStripsOwnRow(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['settings' => ['tabBarPosition' => 'bottom']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_help');
                    $tui->tick();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the help viewer: the tab menu
                    $tui->tick();
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'the keyboard tab menu opened');
                    // A top strip anchors one row below the tab; the bottom strip anchors on
                    // its own row so the overlay's overflow flip opens the panel above it.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $tabsRow = $renderer->getWidgetRect($tui->getById('tabs'))->row;
                    $overlay = Services::resolve($ide, MenuOverlayController::class);
                    $this->assertSame($tabsRow, Probe::get($overlay, 'contextAnchorRow'));
                },
            ],
        );
    }

    public function testTabCloseOthersAndCloseAllKeepUnsavedTabs(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                120 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3)
                },
                300 => static function (Tui $tui): void {
                    // Make C (the active buffer) dirty, then Close Others from A's tab.
                    $editor = Widgets::editor($tui);
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('x');
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click tab A
                },
                380 => function (Tui $tui): void {
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen());
                    $tui->handleInput("\x1b[B"); // down → Close Others
                    $tui->handleInput("\r");
                },
                460 => function (Tui $tui): void {
                    // A kept (active), C kept (dirty), B closed.
                    $this->assertStringEndsWith('A.php', (string) Widgets::editor($tui)->getFilePath());
                    // Now dirty A too, then Close All keeps both unsaved buffers.
                    $editor = Widgets::editor($tui);
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('y');
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click a tab
                },
                560 => static function ($tui): void {
                    // Close, Close Others, Close to the Left, Close to the
                    // Right, Close All — four steps down to the last.
                    $tui->handleInput("\x1b[B"); // down → Close Others
                    $tui->handleInput("\x1b[B"); // down → Close to the Left
                    $tui->handleInput("\x1b[B"); // down → Close to the Right
                    $tui->handleInput("\x1b[B"); // down → Close All
                    $tui->handleInput("\r");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('1 unsaved tab kept open', $screen, 'Close Others kept the one dirty tab');
        $this->assertStringContainsString('2 unsaved tabs kept open', $screen, 'Close All kept both dirty tabs');
    }

    public function testTabCloseAllClosesEveryCleanTab(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click a tab
                },
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // Close Others
                    $tui->handleInput("\x1b[B"); // Close to the Left
                    $tui->handleInput("\x1b[B"); // Close to the Right
                    $tui->handleInput("\x1b[B"); // Close All
                    $tui->handleInput("\r");
                },
                500 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'closing every tab returns focus to the tree');
                },
            ],
        );
    }

    public function testTabCloseIconClosesThatTab(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (tree row 2)
                },
                300 => function (Tui $tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php'], $this->tabNames($tui));
                    // Tab A ' A.php ✖ ': its ✖ sits at SGR col 39 (0-based 38 = rel 7).
                    $tui->handleInput("\x1b[<0;39;2M");
                },
                400 => function (Tui $tui): void {
                    $this->assertSame(['B.php'], $this->tabNames($tui), 'clicking A\'s ✖ closed it');
                    $this->assertStringEndsWith('B.php', (string) Widgets::editor($tui)->getFilePath());
                },
            ],
        );
    }

    public function testTabDragReorderRepaintsTheStripImmediately(): void
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
                300 => function (Tui $tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php'], $this->tabNames($tui));
                    // Press tab A's body and let this frame render, so the press's
                    // own invalidations (buffer switch, focus) are consumed before
                    // the reorder — the drag frame must repaint on its own.
                    $tui->handleInput("\x1b[<0;34;2M");
                    $tui->tick();
                },
                450 => function (Tui $tui, $terminal): void {
                    $terminal->clearOutput(); // capture only the frames after the reorder
                    $tui->handleInput("\x1b[<32;45;2M"); // drag A over tab B
                    $tui->handleInput("\x1b[<0;45;2m"); // release
                    // Ticks, NOT awaitPainted: the claim under test is that the
                    // strip repaints WITHOUT further input, and forcing a frame
                    // would produce the very repaint being asserted. Awaited,
                    // because a tick inside the frame cap renders nothing yet.
                    $this->await(
                        static function () use ($tui, $terminal): bool {
                            $tui->tick();

                            return 1 === \preg_match('/B\.php.*A\.php/', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                        },
                        what: 'the reordered strip to repaint on a tick',
                    );
                    $this->assertSame(['B.php', 'A.php'], $this->tabNames($tui));
                    // A pure reorder mutates no widget, so the strip must be
                    // invalidated explicitly for this frame to repaint it.
                    $this->assertMatchesRegularExpression('/B\.php.*A\.php/', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the strip repainted in the new order without further input');
                },
            ],
        );
    }

    /**
     * A, B and C open in that order — where every tab-ORDERING case starts,
     * because a reorder can only be read against a known starting order.
     *
     * Opened by clicking the tree rather than by argument: the tab strip's
     * order is the order files were opened in, and passing three paths to
     * runIde() would only open the first.
     *
     * @return array<int, \Closure>
     */
    private function openThreeTabsInOrder(): array
    {
        return [
            150 => static function ($tui): void {
                $tui->tick();
                $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
            },
            250 => static function ($tui): void {
                $tui->tick();
                $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3) → active = C
            },
        ];
    }

    public function testTabDragReorders(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                ...$this->openThreeTabsInOrder(),
                350 => function (Tui $tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    // Press tab A's body, drag onto tab C's slot, release → A moves last.
                    $tui->handleInput("\x1b[<0;34;2M"); // press tab A (rel col 3, body)
                    $tui->handleInput("\x1b[<32;55;2M"); // drag over tab C
                    $tui->handleInput("\x1b[<0;55;2m"); // release
                },
                450 => function (Tui $tui): void {
                    $this->assertSame(['B.php', 'C.php', 'A.php'], $this->tabNames($tui), 'A dragged to the end');
                },
            ],
        );
    }

    public function testKeyboardMovesActiveTab(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                ...$this->openThreeTabsInOrder(),
                350 => function (Tui $tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    $tui->handleInput("\x1b[1;6D"); // ctrl+shift+left → [A, C, B]
                    $tui->handleInput("\x1b[1;6D"); // → [C, A, B]
                    $tui->handleInput("\x1b[1;6D"); // C already first → no move
                },
                420 => function (Tui $tui): void {
                    $this->assertSame(['C.php', 'A.php', 'B.php'], $this->tabNames($tui), 'C moved to the front and stopped at the end');
                    $tui->handleInput("\x1b[1;6C"); // ctrl+shift+right → [A, C, B]
                },
                500 => function (Tui $tui): void {
                    $this->assertSame(['A.php', 'C.php', 'B.php'], $this->tabNames($tui), 'moved back right');
                },
            ],
        );
    }

    // --- CLI-supplied options -------------------------------------------------

    /** The tab names in open order, read from the tab bar's provider. */
    private function tabNames(Tui $tui): array
    {
        $tabBar = Widgets::byId($tui, 'tabs', TabBarWidget::class);
        $provider = Probe::get($tabBar, 'provider');

        return \array_column($provider(), 'name');
    }

    /**
     * Folder tabs, wired end to end on a 40-row terminal: the lid above a ✖
     * and the foot below it are the tab, so a click there selects it, and
     * only the ✖ on the label row closes — the geometry the unit tests pin,
     * through the real router.
     */
    public function testARoomyTabsHalfSelectsAndItsLabelRowCloses(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)->openFile($project->path('b.php'), OpenFocus::Keep);
                    $this->frame($tui);
                    $tabs = Widgets::byId($tui, 'tabs', TabBarWidget::class);
                    $rect = Widgets::rectIn($ide, $tabs);
                    $this->assertSame(2, $rect->rows, 'forty rows: pills, the labels over their baseline');
                    $zone = Probe::get($tabs, 'lastLayout')[0]; // a.php, the tab not shown
                    $this->assertSame(0, $zone['labelRow'], 'its label row is the first');
                    $shown = Probe::get($tabs, 'lastLayout')[1]; // b.php
                    $this->assertSame(
                        \str_repeat('▄', $shown['width'] - 2),
                        \mb_substr($this->screenGrid($tui, $terminal)[$rect->row - 1], $rect->col + $shown['start'] + 1, $shown['width'] - 2),
                        'the shown tab\'s upper half is drawn into the rule over the strip',
                    );
                    $col = $rect->col + $zone['closeStart'] + 1;

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $rect->row + 2)); // the baseline below its ✖
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $rect->row + 2));
                    $this->frame($tui);
                    $this->assertStringEndsWith('a.php', (string) Widgets::editor($tui)->getFilePath(), 'the half below the ✖ selected the tab');
                    $this->assertCount(2, Services::resolve($ide, BufferManager::class)->all(), 'and closed nothing');

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $rect->row + 1)); // the ✖ itself
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $rect->row + 1));
                    $this->frame($tui);
                    $this->assertCount(1, Services::resolve($ide, BufferManager::class)->all(), 'the ✖ on the label row closed it');
                },
            ],
            rows: 40,
        );
    }

    /**
     * Side folder tabs, wired end to end on a 40-row terminal: each tab is
     * three rows to a click, the row over its ✖ selects rather than closes,
     * and the ✖ on its middle row closes — through the real router.
     */
    public function testASideTabIsItsRowAndTheGapUnderItIsNoTab(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            'b.php' => "<?php\n",
            '.porta.json' => \json_encode(['settings' => ['tabBarPosition' => 'left']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)->openFile($project->path('b.php'), OpenFocus::Keep);
                    $this->frame($tui);
                    $tabs = Widgets::byId($tui, 'tabs', TabBarWidget::class);
                    $rect = Widgets::rectIn($ide, $tabs);
                    $zone = Probe::get($tabs, 'lastLayout')[0]; // a.php, the tab not shown
                    $this->assertSame(
                        ['row' => 0, 'rows' => 1, 'labelRow' => 0],
                        ['row' => $zone['row'], 'rows' => $zone['rows'], 'labelRow' => $zone['labelRow']],
                        'forty rows: a side tab is a row, as a tree entry is',
                    );
                    $this->assertSame(
                        '▀',
                        \mb_substr($this->screenGrid($tui, $terminal)[$rect->row - 1], $rect->col + $rect->columns - 1, length: 1),
                        'the strip\'s line ends at the menu bar\'s half: no join',
                    );
                    $col = $rect->col + $zone['closeStart'] + 1;

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $rect->row + 2)); // the gap under its ✖
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $rect->row + 2));
                    $this->frame($tui);
                    $this->assertStringEndsWith('b.php', (string) Widgets::editor($tui)->getFilePath(), 'the gap is no tab: nothing is selected');
                    $this->assertCount(2, Services::resolve($ide, BufferManager::class)->all(), 'and nothing closed');

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $rect->row + 1)); // the ✖ itself
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $rect->row + 1));
                    $this->frame($tui);
                    $this->assertCount(1, Services::resolve($ide, BufferManager::class)->all(), 'the ✖ on the tab\'s row closed it');
                },
            ],
            rows: 40,
        );
    }
}
