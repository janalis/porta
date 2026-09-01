<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
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
    use RunsIde;

    public function testDoubleClickingATabRevealsTheFileInTheTree(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'src/deep/Target.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    // Open the deep file as a background tab (does not reveal it).
                    Services::resolve($ide, \Porta\Editor\BufferService::class)
                        ->openFile($project->path('src/deep/Target.php'), \Porta\Editor\OpenFocus::Keep);
                    $tui->requestRender();
                    $tui->tick();

                    $tree = $tui->getById('tree');
                    $this->assertNotSame('Target.php', $tree->selectedNode()?->name, 'opening a file does not reveal it in the tree');

                    $tabs = $tui->getById('tabs');
                    $rect = Services::resolve($ide, Renderer::class)
                        ->getWidgetRect($tabs);
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

        $this->addToAssertionCount(1);
    }

    public function testBufferCloseFlows(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1bw"); // close a.php (clean): no buffers left -> tree focused
                },
                350 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $this->assertNull($tui->getById('editor')->getFilePath());
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
                850 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertStringNotContainsString('E', (string) file_get_contents($project->path('b.php')), 'discarded edit not written');
                    $tui->handleInput("\x1bw"); // close the last one: tree gets focus
                    $tui->handleInput("\x14"); // ctrl+t back to the (empty) editor
                    $tui->handleInput("\x1bw"); // close with no active buffer: no-op
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
        );

        $this->assertStringContainsString('open a file from the tree', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testBufferSwitchReusesHighlightCache(): void
    {
        putenv('PATH=/nonexistent');
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
                500 => function ($tui): void {
                    // Switching back must render a.php's highlight from the
                    // buffer-owned cache (same version, no re-parse).
                    $editorBefore = $tui->getById('editor');
                    $versionBefore = $editorBefore->getDocument()->getVersion();
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right -> a.php
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame(1, $versionBefore, 'no phantom edits from switching');
                },
            ],
        );
    }

    public function testTabBarPositionSwitchesLiveAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $changePosition = static function ($ide, string $position): void {
            Services::resolve($ide, SettingsController::class)
                ->changeSetting('tabBarPosition', $position);
        };
        $rects = static function ($tui, $ide): array {
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
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => json_encode(['settings' => ['tabBarPosition' => 'sideways']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $tabs = $renderer->getWidgetRect($tui->getById('tabs'));
                    $editor = $renderer->getWidgetRect($tui->getById('editor'));
                    $this->assertSame(1, $tabs->rows, 'an unknown persisted value boots the top strip');
                    $this->assertLessThan($editor->row, $tabs->row);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testVerticalTabListMouseInteractions(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n",
            'B.php' => "<?php\n",
            'C.php' => "<?php\n",
            '.porta.json' => json_encode(['settings' => ['tabBarPosition' => 'left']]),
        ]);

        $tabsRect = static fn ($tui, $ide) => Services::resolve($ide, Renderer::class)
            ->getWidgetRect($tui->getById('tabs'));

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => function ($tui, $terminal, $ide) use ($project): void {
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($project->path('B.php'), OpenFocus::Editor);
                    $service->openFile($project->path('C.php'), OpenFocus::Editor);
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                },
                300 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // Left-click row 0's body → switch to A (SGR coords are 1-based).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 1));
                    $tui->tick();
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath(), 'clicking a list row switches to that tab');
                },
                450 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // The × of row 1 (B) sits in the right-aligned close zone.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + TabBarWidget::VERTICAL_WIDTH - 1, $rect->row + 2));
                    $tui->tick();
                    $this->assertSame(['A.php', 'C.php'], $this->tabNames($tui), "clicking a row's × closed that tab");
                },
                600 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // Press row 0 (A), drag onto row 1 (C), release → A moves last.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + 3, $rect->row + 2));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 2));
                    $tui->tick();
                    $this->assertSame(['C.php', 'A.php'], $this->tabNames($tui), 'dragging down the list reordered the tabs');
                },
                750 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $terminal->clearOutput();
                    $rect = $tabsRect($tui, $ide);
                    // Right-click row 0 (C) → the tab context menu.
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $this->awaitPainted($tui, $terminal, 'Close Others');
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-clicking a list row opened the tab menu');
                    $tui->handleInput("\x1b"); // Esc closes it
                },
                900 => function ($tui, $terminal, $ide): void {
                    // A content swap (the help viewer) lands inside the editor body,
                    // not beside the vertical list in the horizontal editor pane.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_help');
                    $tui->tick();
                    $this->assertContains($tui->getById('help-viewer'), $tui->getById('editor-body')->all(), 'the viewer swapped into the editor body slot');
                    $this->assertCount(2, $tui->getById('editor-pane')->all(), 'the editor pane keeps exactly the list and the body');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testKeyboardTabMenuAnchorsOnTheBottomStripsOwnRow(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => json_encode(['settings' => ['tabBarPosition' => 'bottom']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_help');
                    $tui->tick();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the help viewer: the tab menu
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'the keyboard tab menu opened');
                    // A top strip anchors one row below the tab; the bottom strip anchors on
                    // its own row so the overlay's overflow flip opens the panel above it.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $tabsRow = $renderer->getWidgetRect($tui->getById('tabs'))->row;
                    $overlay = Services::resolve($ide, \Porta\Overlay\State\MenuOverlayController::class);
                    $this->assertSame($tabsRow, Probe::get($overlay, 'contextAnchorRow'));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabCloseOthersAndCloseAllKeepUnsavedTabs(): void
    {
        putenv('PATH=/nonexistent');
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
                300 => static function ($tui): void {
                    // Make C (the active buffer) dirty, then Close Others from A's tab.
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('x');
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click tab A
                },
                380 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\x1b[B"); // down → Close Others
                    $tui->handleInput("\r");
                },
                460 => function ($tui): void {
                    // A kept (active), C kept (dirty), B closed.
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath());
                    // Now dirty A too, then Close All keeps both unsaved buffers.
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('y');
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click a tab
                },
                560 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // down → Close Others
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
        putenv('PATH=/nonexistent');
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
                    $tui->handleInput("\x1b[B"); // Close All
                    $tui->handleInput("\r");
                },
                500 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'closing every tab returns focus to the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabCloseIconClosesThatTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (tree row 2)
                },
                300 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php'], $this->tabNames($tui));
                    // Tab A ' A.php × ': its × sits at SGR col 39 (0-based 38 = rel 7).
                    $tui->handleInput("\x1b[<0;39;2M");
                },
                400 => function ($tui): void {
                    $this->assertSame(['B.php'], $this->tabNames($tui), 'clicking A\'s × closed it');
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabDragReorderRepaintsTheStripImmediately(): void
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
                300 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php'], $this->tabNames($tui));
                    // Press tab A's body and let this frame render, so the press's
                    // own invalidations (buffer switch, focus) are consumed before
                    // the reorder — the drag frame must repaint on its own.
                    $tui->handleInput("\x1b[<0;34;2M");
                    $tui->tick();
                },
                450 => function ($tui, $terminal): void {
                    $terminal->clearOutput(); // capture only the frames after the reorder
                    $tui->handleInput("\x1b[<32;45;2M"); // drag A over tab B
                    $tui->handleInput("\x1b[<0;45;2m"); // release
                    // tick(), NOT awaitPainted: the claim under test is that the
                    // strip repaints WITHOUT further input, and forcing a frame
                    // would produce the very repaint being asserted.
                    $tui->tick();
                    $this->assertSame(['B.php', 'A.php'], $this->tabNames($tui));
                    // A pure reorder mutates no widget, so the strip must be
                    // invalidated explicitly for this frame to repaint it.
                    $this->assertMatchesRegularExpression('/B\.php.*A\.php/', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the strip repainted in the new order without further input');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabDragReorders(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                250 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3)
                },
                350 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    // Press tab A's body, drag onto tab C's slot, release → A moves last.
                    $tui->handleInput("\x1b[<0;34;2M"); // press tab A (rel col 3, body)
                    $tui->handleInput("\x1b[<32;55;2M"); // drag over tab C
                    $tui->handleInput("\x1b[<0;55;2m"); // release
                },
                450 => function ($tui): void {
                    $this->assertSame(['B.php', 'C.php', 'A.php'], $this->tabNames($tui), 'A dragged to the end');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testKeyboardMovesActiveTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                250 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3) → active = C
                },
                350 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    $tui->handleInput("\x1b[1;6D"); // ctrl+shift+left → [A, C, B]
                    $tui->handleInput("\x1b[1;6D"); // → [C, A, B]
                    $tui->handleInput("\x1b[1;6D"); // C already first → no move
                },
                420 => function ($tui): void {
                    $this->assertSame(['C.php', 'A.php', 'B.php'], $this->tabNames($tui), 'C moved to the front and stopped at the end');
                    $tui->handleInput("\x1b[1;6C"); // ctrl+shift+right → [A, C, B]
                },
                500 => function ($tui): void {
                    $this->assertSame(['A.php', 'C.php', 'B.php'], $this->tabNames($tui), 'moved back right');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    // --- CLI-supplied options -------------------------------------------------

    /** The tab names in open order, read from the tab bar's provider. */
    private function tabNames(Tui $tui): array
    {
        $tabBar = $tui->getById('tabs');
        $provider = Probe::get($tabBar, 'provider');

        return array_column($provider(), 'name');
    }
}
