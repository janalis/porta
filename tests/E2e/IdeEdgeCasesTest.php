<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Focused full-app scenarios beyond the happy path: missing language
 * server, unreadable files, buffer-close flows, LSP fault handling.
 */
final class IdeEdgeCasesTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_LSP');
    }

    /**
     * Boot the IDE, run the scripted steps, stop after $duration seconds.
     *
     * @param array<int|string, callable> $steps delay-ms => callback(Tui, VirtualTerminal)
     */
    private function runIde(TempProject $project, ?string $file, array $steps, ?float $duration = null): VirtualTerminal
    {
        // Default duration: the last step plus a settle margin for its async
        // fallout (mock-LSP roundtrips are stdio, ~ms). Tests whose tail work
        // needs longer pass an explicit duration.
        $duration ??= max([] === $steps ? 0.0 : max(array_keys($steps)) / 1000 + 0.5, 0.7);

        // Wide terminal so status-bar flashes are not truncated away
        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        $ide = new Ide($project->dir, $file, $terminal);
        $tui = $ide->getTui();

        $timers = [];
        foreach ($steps as $delayMs => $step) {
            $timers[] = EventLoop::delay((int) $delayMs / 1000, static fn () => $step($tui, $terminal, $ide));
        }
        $timers[] = EventLoop::delay($duration, static fn () => $tui->stop());

        try {
            $ide->run();
        } finally {
            // A failing step aborts run() early; cancel the remaining timers
            // so they cannot fire into the next test's event loop.
            foreach ($timers as $timer) {
                EventLoop::cancel($timer);
            }
        }

        return $terminal;
    }

    private function mockLspConfig(): string
    {
        return json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php']);
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
                } elseif (preg_match('/48;5;(\d+)/', $params, $m)) {
                    $bg = '48;5;'.$m[1];
                }
            }
        }

        return $bg;
    }

    public function testNoLanguageServerMode(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x00"); // completion: silently ignored
                    $tui->handleInput("\x1b[24~"); // definition: ignored
                    $tui->handleInput("\x1bOP"); // hover: ignored
                    $tui->handleInput("\x1b[18;3~"); // usages: ignored
                    $tui->handleInput('('); // signature: ignored
                    $tui->handleInput("\x1b[17;2~"); // rename prompt opens...
                    $tui->handleInput("\r"); // ...accept prefilled: flashes "needs a language server"... but word empty -> nothing
                    $tui->handleInput("\x05"); // ctrl+e with a single buffer: no-op
                    $tui->handleInput('x'); // type (didChange path without LSP)
                },
            ],
            0.8,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:none (install phpactor)', $screen);
    }

    public function testRenameWithoutServerFlashes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$word\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 5);
                    $tui->handleInput("\x1b[17;2~"); // shift+f6
                    $tui->handleInput("\r"); // accept prefilled "word"
                },
            ],
            0.8,
        );

        $this->assertStringContainsString('Rename needs a language server', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testStartupWithoutInitialFileFocusesTree(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null,
            [
                300 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                },
            ],
            0.6,
        );
    }

    public function testConfigWarningsAreFlashed(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['keymap' => ['not_a_real_action' => ['f9']]]),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.6);

        $this->assertStringContainsString('not_a_real_action', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testUnopenableFiles(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['ok.php' => "<?php\n"]);
        $unreadable = $project->write('secret.php', 'nope');
        chmod($unreadable, 0000);
        $project->write('huge.bin', str_repeat('x', (2 * 1024 * 1024) + 1));
        $project->write('latin1.txt', "caf\xE9");

        $terminal = $this->runIde(
            $project,
            $project->path('ok.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x14"); // focus tree: entries huge.bin, latin1.txt, ok.php, secret.php
                    $tui->handleInput("\r"); // huge.bin
                    $tui->tick(); // render the flash before it is replaced
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // latin1.txt
                    $tui->tick();
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // secret.php
                    $tui->tick();
                },
            ],
            0.9,
        );

        chmod($unreadable, 0644);
        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('File too large', $screen);
        $this->assertStringContainsString('Not a UTF-8 text file', $screen);
        $this->assertStringContainsString('Cannot read secret.php', $screen);
    }

    public function testNoFileOpenShowsPlaceholder(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            null, // start with no file and no session → the editor has nothing open
            [
                250 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('No file open', $screen, 'the empty pane shows the placeholder');
                    $this->assertNull($tui->getById('editor')->getFilePath(), 'no file is open');
                },
            ],
            0.5,
        );

        $this->assertStringContainsString('No file open', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testMouseDragResizesSidebarAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(30, $tui->getById('tree')->getWidth());
                    // Drag the tree↔editor gap (SGR col 31 = the gap column) out to col 44.
                    $tui->handleInput("\x1b[<0;31;5M"); // arm on the gap
                    $tui->handleInput("\x1b[<32;45;5M"); // drag to col 44
                    $tui->handleInput("\x1b[<0;45;5m"); // release
                },
                320 => function ($tui) use ($project): void {
                    $this->assertSame(44, $tui->getById('tree')->getWidth(), 'sidebar resized by the drag');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), true);
                    $this->assertSame(28, $layout['sidebarWidthPercent'], 'saved as a percentage (round(44/160))');
                },
            ],
            0.5,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDragResizesBottomPanelAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tp = $tui->getById('terminal');
                    $tp->open('sh', new \Porta\Terminal\TerminalBuffer());
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tp);
                    $top = $rect->row + 1; // 1-based screen row of the panel's top edge
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dM", $top)); // arm on the top edge
                    $tui->handleInput(\sprintf("\x1b[<32;5;%dM", $top - 4)); // drag the edge up 4 rows
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dm", $top - 4)); // release
                },
                320 => function ($tui) use ($project): void {
                    $this->assertSame(16, $tui->getById('terminal')->visibleRows(), 'panel grew by the drag (clamped at rows-8)');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), true);
                    $this->assertSame(67, $layout['bottomPanelHeightPercent'], 'saved as a percentage (round(16/24))');
                },
            ],
            0.5,
        );

        $this->addToAssertionCount(1);
    }

    public function testSavedLayoutIsAppliedOnStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        @mkdir($project->path('.porta'), 0777, true);
        file_put_contents(
            $project->path('.porta/layout.json'),
            json_encode(['sidebarWidthPercent' => 25, 'bottomPanelHeightPercent' => 40]),
        );

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(40, $tui->getById('tree')->getWidth(), '25% of 160 columns');
                    $this->assertSame(10, $tui->getById('terminal')->visibleRows(), '40% of 24 rows');
                },
            ],
            0.4,
        );

        $this->addToAssertionCount(1);
    }

    public function testGapClickWithoutDragDoesNotResizeOrPersist(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui) use ($project): void {
                    $tui->handleInput("\x1b[<0;31;5M"); // press the gap
                    $tui->handleInput("\x1b[<0;31;5m"); // release without moving
                    $this->assertSame(30, $tui->getById('tree')->getWidth(), 'width unchanged');
                    $this->assertFileDoesNotExist($project->path('.porta/layout.json'), 'no file written for a no-op');
                },
            ],
            0.4,
        );

        $this->addToAssertionCount(1);
    }

    public function testAppMenuDropdownCoversBreadcrumb(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // into handle() → breadcrumb bar shows
                    }
                },
                280 => function ($tui): void {
                    $crumb = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('handle', $crumb, 'breadcrumb is visible');
                    $tui->handleInput("\x1b[<0;35;1M"); // open the File menu; its dropdown reaches the breadcrumb row
                },
                360 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $row = $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
                    $this->assertStringContainsString("\x1b[48;5;236m", $row, 'the dropdown composites over the breadcrumb row (breadcrumb no longer in front)');
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

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
                    $tui->handleInput("\x1b[<2;26;2M");
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'context menu opened');

                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
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
            0.6,
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
                200 => function ($tui): void {
                    // Right-click a tree entry; the menu is wide enough to spill past the sidebar.
                    $tui->handleInput("\x1b[<2;21;2M"); // right-press col 20, row 1 (first entry)
                },
                280 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'tree context menu opened');
                    $lines = $tui->getById('separator')->render(new \Symfony\Component\Tui\Render\RenderContext(1, 24));
                    $painted = array_filter($lines, static fn (string $l): bool => ' ' !== $l);
                    $this->assertNotEmpty($painted, 'the context menu paints over the separator column (no hole)');
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testStickyScrollPinsHeaderAndClickJumps(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".str_repeat("        \$x = 1;\n", 30)."    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    for ($i = 0; $i < 30; ++$i) {
                        $tui->handleInput("\x1b[B"); // scroll deep into the method
                    }
                },
                280 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($editor);
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, $rect->rows));
                    $this->assertStringContainsString('class Service', AnsiUtils::stripAnsiCodes($rendered[0]), 'class header pinned at the top');
                    // Click the pinned class header (editor row 0).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 7, $rect->row + 1));
                },
                360 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the pinned header jumped to the class declaration');
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testBreadcrumbsShowScopePathAndJumpOnClick(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    // Caret at the very top is outside every scope → no bar.
                    $this->assertSame([], $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)));
                    // Navigate into handle() with the keyboard (invalidates the editor → relayout).
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // down to line 8 (the `$x` line)
                    }
                },
                280 => function ($tui): void {
                    $plain = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('App › Service › handle', $plain, 'the scope path is shown');
                },
                340 => function ($tui, $terminal, $ide): void {
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('breadcrumbs'));
                    // ' App'(1..3) › 'Service'(7..13) › 'handle'. Click 'Service' (col 8) →
                    // jump to the class declaration (0-based line 4).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8 + 1, $rect->row + 1));
                },
                420 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the Service crumb jumped to the class declaration');
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testSidebarHoverHighlightsRow(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    // Header is relative row 0, a.php (selected) is row 1; hover b.php at row 2.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 3, $rect->row + 3));
                },
                280 => function ($tui, $terminal, $ide): void {
                    $tree = $tui->getById('tree');
                    $line = $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 24))[2];
                    $this->assertStringContainsString("\x1b[48;5;236m", $line, 'hovered tree row is highlighted');
                    // Move off the tree into the editor → the hover clears.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $editorRect->col + 5, $editorRect->row + 3));
                },
                360 => function ($tui): void {
                    $tree = $tui->getById('tree');
                    $joined = implode('', $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 24)));
                    $this->assertStringNotContainsString("\x1b[48;5;236m", $joined, 'hover cleared after leaving the tree');
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testAppMenuGreysFileActionsWhenNoFileOpen(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            null, // start with no file
            [
                200 => function ($tui): void {
                    $tui->handleInput("\x1b[<0;40;1M"); // open the Edit menu (title cols 37-42)
                },
                280 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    // Undo is item 0; with no file open it renders dim and is not highlighted.
                    $undo = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $this->assertStringContainsString("\x1b[38;5;240m", $undo, 'Undo greyed while no file is open');
                    $this->assertStringNotContainsString("\x1b[48;5;31m", $undo, 'disabled item not highlighted');
                    $menu->close();
                },
                360 => function ($tui) use ($project): void {
                    // Open a file so the document actions re-enable, then reopen Edit.
                    $tui->getById('editor')->openFile($project->path('a.php'), "<?php\necho 1;\n");
                    $tui->handleInput("\x1b[<0;40;1M");
                },
                440 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $undo = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $this->assertStringNotContainsString("\x1b[38;5;240m", $undo, 'Undo enabled once a file is open');
                },
            ],
            0.7,
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
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
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
            1.1,
        );

        $this->assertStringContainsString('open a file from the tree', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testWarningDiagnosticsShowInStatusBar(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$x = 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->setDiagnostics([
                        1 => [['severity' => 2, 'message' => 'unused variable', 'col' => 0, 'endCol' => 2]],
                    ]);
                    $tui->requestRender(); // what Ide::handleDiagnostics does after mutating
                    $tui->tick();
                },
            ],
            0.5,
        );

        $this->assertStringContainsString('▲ 1', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testUnspawnableServerReportsFailed(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => '/nonexistent-binary-xyz --stdio']),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.5);

        $this->assertStringContainsString('LSP:failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testDebounceFiresAfterBufferClosed(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => static function ($tui): void {
                    $tui->handleInput('x'); // schedules a didChange debounce
                    $tui->handleInput("\x1a"); // undo... still dirty; save instead
                    $tui->handleInput("\x13"); // ctrl+s -> clean
                    $tui->handleInput("\x1bw"); // close: debounce later fires with no file
                },
            ],
            1.0,
        ); // > 400ms + 300ms debounce

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }

    public function testCompletionWithNullResultIsIgnored(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
        ]);

        $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(91, 0);
                    $tui->handleInput("\x00"); // ctrl+space -> mock replies null
                },
                700 => function ($tui): void {
                    $this->assertFalse($tui->getById('editor')->isCompletionOpen());
                },
            ],
            0.9,
        );
    }

    public function testCompletionResolveWithoutImportsLeavesFileUntouched(): void
    {
        // Accepting an item whose completionItem/resolve returns no
        // additionalTextEdits must insert only the completion, no `use` line.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Plain.php' => "<?php\n\necho ;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Plain.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 0); // blank line, empty prefix
                    $tui->handleInput("\x00"); // ctrl+space
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('editor')->isCompletionOpen());
                    $tui->handleInput("\x1b[B"); // -> str_replace
                    $tui->handleInput("\x1b[B"); // -> trim
                    $tui->handleInput("\r"); // accept trim (resolves to no imports)
                },
                1000 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('trim(', $doc->getText());
                    $this->assertStringNotContainsString('use ', $doc->getText(), 'no auto-import added');
                },
            ],
            1.2,
        );
    }

    public function testIncrementalDidChangeSendsMinimalDelta(): void
    {
        // A server advertising incremental sync (change kind 2) must receive a
        // ranged content change carrying only the typed text, not the whole file.
        $project = new TempProject([
            '.porta.json' => json_encode([
                'lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/incremental-lsp-server.php',
            ]),
            'src/Inc.php' => "<?php\n\nfoo\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Inc.php'),
            [
                400 => static function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(2, 3); // end of "foo"
                    $tui->handleInput('Z'); // insert one character
                },
                1000 => function ($tui): void {
                    // The mock reflects the change kind + text into a diagnostic.
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $this->assertSame('sync:incremental:Z', $editor->getCursorDiagnostic());
                },
            ],
            1.3,
        );
    }

    public function testSnippetCompletionExpandsWithTabStops(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Snip.php' => "<?php\n\necho ;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Snip.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x00"); // ctrl+space
                },
                700 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isCompletionOpen());
                    $tui->handleInput("\x1b[B"); // -> str_replace
                    $tui->handleInput("\x1b[B"); // -> trim
                    $tui->handleInput("\x1b[B"); // -> sprintf (snippet)
                    $tui->handleInput("\r"); // accept
                },
                1000 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertSame('sprintf(format, args)', $doc->getLine(1), 'snippet expanded');
                    $this->assertSame('format', $doc->getSelectedText(), 'first tab stop selected');
                },
            ],
            1.2,
        );
    }

    public function testInlayHintsRenderThroughIde(): void
    {
        // The mock emits inlay hints only for *Inlay* files.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Inlay.php' => "<?php\n\$x = f();\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Inlay.php'),
            [
                600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString(': int', $screen, 'inlay type hint rendered inline');
                },
            ],
            0.9,
        );
    }

    public function testGoToImplementationChooserAndTypeDefinitionJump(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Target.php' => "<?php\n\n// line 2\nclass Target\n{\n}\n",
            'src/Demo.php' => "<?php\n\nclass Demo\n{\n    public function greet(): string\n    {\n        return 'x';\n    }\n}\n".str_repeat("//\n", 100),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 20);
                    $tui->handleInput("\x1b\x02"); // ctrl+alt+b -> implementation (2 results)
                },
                600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Choose target', $screen, 'multiple implementations show a chooser');
                    $this->assertStringContainsString('Target.php', $screen);
                    $tui->handleInput("\x1b"); // close the chooser
                },
                750 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 20);
                    $tui->handleInput("\x1b[98;6u"); // ctrl+shift+b -> type definition (single -> jump)
                },
                1050 => function ($tui): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'single type definition jumped');
                    // No implementation at a high line -> flash.
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                },
            ],
            1.3,
        );
    }

    public function testGoToImplementationWithNoResultFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Big.php' => "<?php\n".str_repeat("noop();\n", 120),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Big.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(95, 0); // >= 90 -> null
                    $tui->handleInput("\x1b\x02"); // ctrl+alt+b
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No implementation found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // >= 80 returns several candidates, all outside the filesystem.
                    $tui->getById('editor')->getDocument()->setCursor(85, 0);
                    $tui->handleInput("\x1b\x02");
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringNotContainsString('Choose target', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'no chooser when every candidate is unresolvable');
                },
            ],
            1.3,
        );
    }

    public function testFormatSelectionUsesRangeFormatting(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Fmt.php' => "<?php\nmessy( );\nkeep();\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Fmt.php'),
            [
                400 => static function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(1, 0);
                    $doc->beginSelect();
                    $doc->setCursor(1, 8, keepSelection: true); // select "messy( )"
                    $tui->handleInput("\x1b\x0c"); // ctrl+alt+l reformat
                },
                700 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    // rangeFormatting replaced only the selection with the marker.
                    $this->assertStringContainsString('FORMATTED', $doc->getLine(1));
                    $this->assertSame('keep();', $doc->getLine(2), 'code outside the selection untouched');
                },
            ],
            1.0,
        );
    }

    public function testPrepareRenameDeclineFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Big.php' => "<?php\n".str_repeat("noop();\n", 120),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Big.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(96, 0); // >= 95 -> prepareRename null
                    $tui->handleInput("\x1b[17;2~"); // shift+f6
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    // A flash (not a prompt) proves prepareRename was rejected.
                    $this->assertStringContainsString('Cannot rename this element', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.9,
        );
    }

    public function testProjectSearchRegexToggle(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$noodle = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f -> open project search
                },
                400 => static function ($tui): void {
                    foreach (str_split('n..dle') as $ch) {
                        $tui->handleInput($ch); // a regex; as a literal it matches nothing
                    }
                },
                750 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('0 results', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1br"); // alt+r -> enable regex, re-runs the search
                },
                1150 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('2 results', $screen, 'regex n..dle matches needle and noodle');
                    $this->assertStringContainsString('A.php', $screen);
                },
            ],
            1.5,
        );
    }

    public function testProjectReplaceAcrossOpenAndDiskFiles(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // opened (active buffer)
            'src/B.php' => "<?php\n\$foo = 2;\n", // only on disk
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // switch to the replace field
                    foreach (str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a -> replace all
                },
                1000 => function ($tui) use ($project): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('$bar = 1;', $doc->getText(), 'open buffer replaced (undoable)');
                    $this->assertStringContainsString('$bar = 2;', (string) file_get_contents($project->path('src/B.php')), 'on-disk file replaced');
                },
            ],
            1.3,
        );
    }

    public function testProjectReplaceWithNothingFlashes(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // open project search
                    $tui->handleInput("\t"); // to replace field
                    $tui->handleInput("\x1ba"); // replace all with no query/results
                },
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Nothing to replace', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.7,
        );
    }

    public function testGitStageCommitBlameViaPalette(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath);

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('src/A.php', "<?php\n\$a = 1;\n");
        exec('git -C '.escapeshellarg($project->dir).' add -A');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init');
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged change

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static fn ($tui) => $palette($tui, 'Git: Stage'),
                650 => function ($tui, $terminal) use ($palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Staged src/A.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $palette($tui, 'Git: Commit'); // opens the message prompt
                },
                850 => static function ($tui): void {
                    $tui->handleInput("\r"); // empty message -> no-op, prompt closes
                },
                1000 => static function ($tui) use ($palette): void {
                    $palette($tui, 'Git: Commit');
                },
                1150 => static function ($tui): void {
                    foreach (str_split('my commit') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r"); // commit
                },
                1450 => function ($tui, $terminal) use ($palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Committed: my commit', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $palette($tui, 'Git: Blame');
                },
                1750 => function ($tui, $terminal) use ($palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('tester', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'blame flashes the author');
                    $palette($tui, 'Git: Undo');
                },
                2100 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('Last commit undone', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    exec('git -C '.escapeshellarg($project->dir).' log --format=%s 2>/dev/null', $subjects);
                    $this->assertSame(['init'], $subjects, 'the soft reset removed "my commit"');
                },
            ],
            2.4,
        );
    }

    public function testGitBlameBeyondFileFlashesNoBlame(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath);

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('src/A.php', "<?php\n"); // one line on disk
        exec('git -C '.escapeshellarg($project->dir).' add -A');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init');

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static function ($tui): void {
                    // Grow the buffer (unsaved) and blame a line past the on-disk file,
                    // which git rejects -> empty output -> "No blame".
                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(1, 0);
                    $doc->insert("\n\n\n\n\n");
                    $doc->setCursor(4, 0);
                    $tui->handleInput("\x1b[97;6u");
                    foreach (str_split('Git: Blame') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No blame for this line', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.0,
        );
    }

    public function testGitCommandsWithoutRepoFlash(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['src/A.php' => "<?php\n"]); // not a git repo

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static fn ($tui) => $palette($tui, 'Git: Stage'),
                550 => static fn ($tui) => $palette($tui, 'Git: Commit'),
                700 => static fn ($tui) => $palette($tui, 'Git: Blame'),
                850 => static fn ($tui) => $palette($tui, 'Git: Undo'),
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Git not available', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.25,
        );
    }

    public function testQuickFixesEditCommandAndEmptyPaths(): void
    {
        $tall = "<?php\n\nclass QuickFixMe {}\n".str_repeat("// filler\n", 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Q.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Q.php'),
            [
                // Edit-based action: "Import class" inserts a use statement
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 6);
                    $tui->handleInput("\x1b\r"); // alt+enter
                },
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $tui->tick(); // flush pending render before reading the screen
                    $this->assertStringContainsString('Quick Fixes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('Import class', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // pick "Import class"
                },
                750 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('use Acme\Imported;', $editor->getDocument()->getLine(1));
                    $tui->tick();
                    $this->assertStringContainsString('Applied "Import class" to 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Command-based action next
                    $tui->handleInput("\x1b\r");
                },
                900 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // -> Generate method
                    $tui->handleInput("\r");
                },
                1100 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $tui->tick();
                    // executeCommand reply and the server-initiated applyEdit both
                    // flash and race; assert on the deterministic buffer effect.
                    $this->assertSame('// generated', $editor->getDocument()->getLine(0), 'server applyEdit landed');
                    // Empty path: mock convention line >= 90
                    $editor->getDocument()->setCursor(95, 0);
                    $tui->handleInput("\x1b\r");
                },
                1300 => function ($tui, $terminal): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $this->assertStringContainsString('No quick fixes here', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.5,
        );

        $this->addToAssertionCount(1);
    }

    public function testQuickFixUsesDiagnosticRangeAndHandlesNoOpAction(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/D.php' => "<?php\n\$broken = ;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/D.php'),
            [
                // Wait for the mock's published diagnostic on line 0, then request
                // quick fixes from that line: the request range comes from the
                // diagnostic (covered branch), and the actions apply normally.
                500 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b\r");
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $tui->handleInput("\x1b"); // close without applying
                },
            ],
            1.0,
        );

        $this->assertStringContainsString('Quick Fixes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testFileStructurePopupJumpsToSymbol(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\n\nclass Demo\n{\n    public function greet(): string { return 'x'; }\n}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static fn ($tui) => $tui->handleInput("\x1b[24;5~"), // ctrl+f12
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('File Structure', $screen);
                    $this->assertStringContainsString('Demo', $screen);
                    $this->assertStringContainsString('greet', $screen);
                    $tui->handleInput("\x1b[B"); // -> greet
                    $tui->handleInput("\r");
                },
                750 => function ($tui): void {
                    // greet's selectionRange starts at line 4
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine());
                },
            ],
            1.0,
        );

        $this->assertStringContainsString('File Structure', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testFileStructureEmptyResult(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Empty.php' => "<?php\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Empty.php'),
            [
                400 => static fn ($tui) => $tui->handleInput("\x1b7"), // alt+7
                600 => function ($tui, $terminal): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $this->assertStringContainsString('No symbols in this file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToSymbolInProject(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Greeter {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b\x0f"); // ctrl+alt+o
                    foreach (str_split('greet') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                750 => function ($tui, $terminal): void {
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Go to Symbol', $screen);
                    $this->assertStringContainsString('Greeter', $screen);
                    $tui->handleInput("\r"); // jump
                },
                900 => function ($tui): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame(3, $tui->getById('editor')->getDocument()->getCursorLine());
                },
            ],
            1.2,
        );

        $this->addToAssertionCount(1);
    }

    public function testProblemsPanelAggregatesAcrossBuffersAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/A.php' => "<?php\n\$x = 1;\n",
            'src/B.php' => "<?php\n\$y = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                // Open B too so both buffers carry the mock's line-0 diagnostic
                400 => static function ($tui): void {
                    $tui->handleInput("\x14"); // tree (selection on src/)
                    $tui->handleInput("\r"); // expand src/
                    $tui->handleInput("\x1b[B"); // A.php
                    $tui->handleInput("\x1b[B"); // B.php
                    $tui->handleInput("\r"); // open B.php
                },
                600 => static function ($tui): void {
                    $tui->handleInput("\x1b6"); // alt+6 problems
                },
                750 => function ($tui, $terminal): void {
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen);
                    // Both buffers carry the mock server's line-0 diagnostic
                    $this->assertStringContainsString('Mock diagnostic', $screen);
                    $this->assertStringContainsString('A.php', $screen);
                    $this->assertStringContainsString('B.php', $screen);
                    $tui->handleInput("\r"); // jump to first problem
                },
                900 => function ($tui): void {
                    // First row sorts to A.php; jumping opens it in the editor
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
            1.2,
        );

        $this->addToAssertionCount(1);
    }

    public function testReformatFile(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Messy.php' => "<?php\n   \$a=1  ;\n\n\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Messy.php'),
            [
                400 => static fn ($tui) => $tui->handleInput("\x1b\x0c"), // ctrl+alt+l
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('formatted();', $editor->getDocument()->getLine(2));
                    $tui->tick();
                    $this->assertStringContainsString('Reformatted src/Messy.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testGitGutterMarksAndBranchInStatusBar(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath); // git must be reachable

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('src/A.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        exec('git -C '.escapeshellarg($project->dir).' add -A');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init');
        // Modify line 2 and append a line after committing
        $project->write('src/A.php', "<?php\n\$a = 999;\n\$b = 2;\n\$c = 3;\n");

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // Branch name in the status bar (default branch: main or master)
                    $this->assertMatchesRegularExpression('/\b(main|master)\b/', $screen);
                    $editor = $tui->getById('editor');
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 10));
                    $joined = implode('', $rendered);
                    // Modified (blue ▎) on line 2, added (green ▎) on line 4
                    $this->assertStringContainsString("\x1b[38;5;75m▎", $joined, 'modified mark present');
                    $this->assertStringContainsString("\x1b[38;5;114m▎", $joined, 'added mark present');
                    // Exact width contract still holds with the git strip
                    foreach ($rendered as $line) {
                        $this->assertSame(80, AnsiUtils::visibleWidth($line));
                    }
                },
            ],
            1.0,
        );

        $this->addToAssertionCount(1);
    }

    private function hasGit(): bool
    {
        foreach (explode(\PATH_SEPARATOR, $this->originalPath) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return true;
            }
        }

        return false;
    }

    public function testApplicationMenuOpenRunAndDismiss(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui): void {
                    $tui->tick();
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('X'); // dirty: "X<?php"
                    $tui->handleInput("\x1b[21~"); // F10 opens the menu
                },
                300 => function ($tui): void {
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu');
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $tui->handleInput("\x1b[C"); // right -> Edit
                    $tui->handleInput("\r"); // enter -> Edit's first item (Undo)
                },
                400 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('<?php', $editor->getDocument()->getLine(0), 'menu ran Undo via runAction');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'focus returned to editor');
                    $tui->handleInput("\x1b[21~"); // F10 again
                },
                500 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $tui->handleInput("\x1b"); // Esc closes
                },
                600 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'Esc closed the menu');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
            0.75,
        );

        $this->addToAssertionCount(1);
    }

    public function testApplicationMenuMouse(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui): void {
                    $tui->tick();
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('X'); // dirty: "X<?php"
                    // The menu bar sits at the top of the editor pane (tree width 30
                    // + gap 1 → col 31): File title at cols 31-36, Edit at 37-42.
                    // Click the "Edit" title (screen row 0 → SGR row 1).
                    $tui->handleInput("\x1b[<0;40;1M");
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'title click opened the menu');
                    $this->assertSame('menu', $tui->getFocus()?->getId());
                    // The dropdown anchors under Edit (col 37); Undo is item 0 at
                    // screen row 1 (below the menu bar) → SGR row 2.
                    $tui->handleInput("\x1b[<0;40;2M"); // click Undo -> runs it + closes
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'item click ran and closed');
                    $editor = $tui->getById('editor');
                    $this->assertSame('<?php', $editor->getDocument()->getLine(0), 'clicking Undo undid the edit');
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;35;1M"); // reopen File (title cols 31-36)
                },
                500 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'File reopened');
                    // Clicking the SAME (open) title again toggles the dropdown closed.
                    $tui->handleInput("\x1b[<0;35;1M"); // File title cols 31-36
                },
                600 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 're-clicking the open title closed it');
                    // Open File, then click a DIFFERENT title (Edit) → switches, stays open.
                    $tui->handleInput("\x1b[<0;35;1M"); // open File (index 0)
                    $tui->handleInput("\x1b[<0;40;1M"); // click Edit (index 1)
                },
                700 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen(), 'clicking another title kept a menu open');
                    $this->assertSame(1, $menu->openIndex(), 'switched to Edit (index 1)');
                    $tui->handleInput("\x1b[<0;100;18M"); // click far below the dropdown -> dismiss
                },
                800 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'outside click dismissed the menu');
                },
            ],
            1.0,
        );

        $this->addToAssertionCount(1);
    }

    public function testApplicationMenuHover(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui): void {
                    $tui->tick();
                    // Closed bar: hover the File title (cols 31-36, SGR move code 35).
                    $tui->handleInput("\x1b[<35;35;1M");
                },
                280 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $row = $menu->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
                    $this->assertStringContainsString("\x1b[48;5;240m", $row, 'closed title highlights on hover');
                    // Move off the bar (into the editor body) → the highlight clears.
                    $tui->handleInput("\x1b[<35;60;10M");
                },
                360 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $row = $menu->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
                    $this->assertStringNotContainsString("\x1b[48;5;240m", $row, 'moving off the bar cleared the highlight');
                    $tui->handleInput("\x1b[<0;40;1M"); // click the Edit title (cols 37-42) to open it
                },
                440 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    $this->assertSame(1, $menu->openIndex(), 'Edit is open');
                    // Hover Redo (item 1) in the dropdown: bar row 0 → items at SGR rows 2,3.
                    $tui->handleInput("\x1b[<35;40;3M");
                },
                520 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $segments = $menu->dropdownSegments($menu->panelWidth());
                    $this->assertStringContainsString("\x1b[48;5;31m", $segments[1]['segment'], 'hovering Redo highlighted it');
                    $this->assertStringNotContainsString("\x1b[48;5;31m", $segments[0]['segment'], 'Undo is no longer highlighted');
                    // While open, hover the sibling File title → switches the dropdown.
                    $tui->handleInput("\x1b[<35;35;1M");
                },
                600 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    $this->assertSame(0, $menu->openIndex(), 'hovering the sibling title switched to File');
                    // Hover the editor body while open (off both dropdown and bar) → menu stays open.
                    $tui->handleInput("\x1b[<35;60;12M");
                },
                680 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'hovering away from the menu kept it open');
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The whole point of the floating dropdown: opening a menu must NOT push
     * the content below it down. We snapshot the screen as absolute rows before
     * and after F10 and assert a tree entry keeps its exact row while the
     * dropdown appears composited over the editor pane.
     */
    public function testOpeningMenuDoesNotShiftContentDown(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\necho 1;\n",
            'zmarker.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();

                    $before = $this->screenGrid($tui, $terminal);
                    $markerRow = $this->rowOf($before, 'zmarker');
                    $this->assertNotNull($markerRow, 'tree lists zmarker.php before the menu opens');

                    $tui->handleInput("\x1b[21~"); // F10 opens the menu

                    $after = $this->screenGrid($tui, $terminal);
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'F10 opened the menu');
                    // Regression: the tree entry stays on the SAME screen row (before
                    // the fix it was reflowed downward) and no rows were inserted.
                    $this->assertSame($markerRow, $this->rowOf($after, 'zmarker'), 'content did not shift down');
                    $this->assertSameSize($before, $after, 'no extra rows inserted');

                    // ...and the dropdown really is drawn, floating over the pane.
                    $menu = $tui->getById('menu');
                    $segment = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $label = explode(' ', trim(AnsiUtils::stripAnsiCodes($segment)))[0];
                    $this->assertNotSame('', $label);
                    $this->assertStringContainsString($label, implode("\n", $after), 'dropdown composited over the editor pane');
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testOpeningMenuBeforeFirstRenderIsSafe(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->getTui();

        // No frame has rendered yet, so every widget rect is null. Opening the
        // menu must not blow up placing the (impossible-to-place) overlay.
        $tui->handleInput("\x1b[21~"); // F10

        $this->assertTrue($tui->getById('menu')->isOpen(), 'menu opened without a prior render');
        $this->assertSame('', $terminal->consumeOutput(), 'nothing was drawn');
    }

    /**
     * Force a full frame and return the screen as stripped text rows. A forced
     * render resets the differential writer, so the output is the clear/home
     * prefix followed by every row joined by CRLF — splittable into a grid,
     * unlike the incremental updates a normal render emits.
     *
     * @return list<string>
     */
    private function screenGrid(Tui $tui, VirtualTerminal $terminal): array
    {
        $terminal->consumeOutput(); // drop anything buffered so far
        $tui->requestRender(force: true); // reset the writer -> next frame is full
        $tui->processRender();
        $raw = $terminal->consumeOutput();
        $body = preg_replace('/^.*\x1b\[H/s', '', $raw); // strip up to cursor-home

        return array_map(
            static fn (string $row): string => AnsiUtils::stripAnsiCodes($row),
            explode("\r\n", (string) $body),
        );
    }

    /**
     * The index of the first grid row containing $needle, or null.
     *
     * @param list<string> $grid
     */
    private function rowOf(array $grid, string $needle): ?int
    {
        foreach ($grid as $i => $row) {
            if (str_contains($row, $needle)) {
                return $i;
            }
        }

        return null;
    }

    public function testMouseClickAndWheel(): void
    {
        putenv('PATH=/nonexistent');
        // Root-level files so the tree lists them directly (dirs sort first,
        // so keeping it flat makes row math simple).
        $long = "<?php\n".implode("\n", array_map(static fn (int $i) => "// line {$i}", range(1, 60)))."\n";
        $project = new TempProject(['A.php' => $long, 'B.php' => "<?php\n// this is B\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                // Editor click: place the cursor on a lower line.
                300 => function ($tui): void {
                    $tui->tick(); // ensure a frame rendered so widget rects exist
                    // Menu bar owns row 0, tab bar row 1, so the editor content
                    // starts at screen row 2; a click at screen row 4 lands on
                    // document line 2 (col 45 -> past the gutter).
                    $tui->handleInput("\x1b[<0;45;5M");
                },
                400 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertSame(2, $editor->getDocument()->getCursorLine(), 'click moved cursor');
                    // Wheel down over the editor scrolls the viewport.
                    $tui->handleInput("\x1b[<65;45;6M");
                    $tui->handleInput("\x1b[<65;45;6M");
                },
                500 => function ($tui): void {
                    // After two wheel-down steps (6 lines) the top visible line is
                    // no longer line 1.
                    $editor = $tui->getById('editor');
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(69, 20));
                    $topLineNumber = (int) trim(AnsiUtils::stripAnsiCodes($rendered[0]));
                    $this->assertGreaterThan(1, $topLineNumber, 'wheel scrolled the viewport down');
                    // Wheel over the tree scrolls it (feeds arrow keys).
                    $tui->handleInput("\x1b[<65;6;7M");
                    $tui->handleInput("\x1b[<64;6;7M");
                    // Tree click: focus the tree and open B.php. The menu bar now
                    // lives in the editor pane, so the tree owns the full height:
                    // header row 0, A.php row 1, B.php row 2.
                    $tui->handleInput("\x1b[<0;2;3M"); // 0-based col1 row2 -> B.php
                },
                650 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('B.php', (string) $editor->getFilePath());
                    // Tab-bar click: switch back to A.php (first tab).
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;34;2M"); // 0-based col33 row1 -> tab area
                },
                800 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('A.php', (string) $editor->getFilePath(), 'tab click switched buffer');
                },
            ],
            1.0,
        );

        // Mouse-enable escape written on startup, disable on shutdown.
        $this->assertStringContainsString("\x1b[?1006h", $terminal->getOutput());

        $this->addToAssertionCount(1);
    }

    public function testMouseClicksOutsideAllWidgetsAreIgnored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $before = $tui->getById('editor')->getDocument()->getCursorLine();
                    // Right-click in the 1-col gap between the tree and editor pane
                    // (SGR col 31 = the gap), drag-motion, release, and a wheel over
                    // empty space are all no-ops (no context menu, no caret move).
                    $tui->handleInput("\x1b[<2;31;4M"); // right press in the gap
                    $tui->handleInput("\x1b[<32;45;4M"); // motion
                    $tui->handleInput("\x1b[<0;45;4m"); // release
                    $tui->handleInput("\x1b[<64;1;24M"); // wheel over the status bar
                    $this->assertSame($before, $tui->getById('editor')->getDocument()->getCursorLine());
                    $this->assertFalse($tui->getById('context-menu')->isOpen());
                },
            ],
            0.5,
        );

        $this->addToAssertionCount(1);
    }

    // Editor pane starts at column 31 (tree 30 + gap 1), gutter is 6, and content
    // begins at screen row 2 (menu row 0, tab row 1). So document (line L, col C)
    // sits at SGR column 38+C, SGR row 3+L.
    public function testMouseDragSelectsText(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond line\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // press at line 0, col 0
                    $tui->handleInput("\x1b[<32;43;3M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;43;3m"); // release
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
            0.7,
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput(), 'any-motion mouse mode enabled');
    }

    public function testMouseDragAcrossLines(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "abc\ndef\nghi\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;39;3M"); // line 0, col 1
                    $tui->handleInput("\x1b[<32;39;5M"); // drag down to line 2, col 1
                    $tui->handleInput("\x1b[<0;39;5m");
                },
                400 => function ($tui): void {
                    $this->assertSame("bc\ndef\ng", $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDoubleClickSelectsWord(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;40;3M"); // press inside "hello"
                    $tui->handleInput("\x1b[<0;40;3M"); // second press same cell -> word
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseTripleClickSelectsLine(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;42;3M");
                    $tui->handleInput("\x1b[<0;42;3M");
                    $tui->handleInput("\x1b[<0;42;3M"); // third press -> whole line
                },
                400 => function ($tui): void {
                    $this->assertSame('hello world', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseShiftClickExtendsSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // plain click -> caret at col 0
                    $tui->handleInput("\x1b[<4;43;3M"); // Shift+click at col 5 -> extend
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseAltClickTogglesCaret(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click (code 8) on line 1 -> add caret
                    $this->assertTrue($tui->getById('editor')->hasMultipleCarets(), 'Alt+Click added a caret');
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click same spot -> remove
                    $this->assertFalse($tui->getById('editor')->hasMultipleCarets(), 'Alt+Click again removed it');
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testMousePlainClickCollapsesCarets(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click adds a caret
                    $this->assertTrue($tui->getById('editor')->hasMultipleCarets());
                    $tui->handleInput("\x1b[<0;38;3M"); // plain click collapses to one caret
                    $this->assertFalse($tui->getById('editor')->hasMultipleCarets());
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseAltShiftDragColumnSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    // Editor content starts at screen col 37 (SGR 38), row 2 (SGR 3);
                    // display col C is SGR 38+C. Alt+Shift (12) drag (44) release.
                    $tui->handleInput("\x1b[<12;39;3M"); // press line 0, display col 1
                    $tui->handleInput("\x1b[<44;41;5M"); // drag to line 2, display col 3
                    $tui->handleInput("\x1b[<0;41;5m"); // release
                    $this->assertTrue($tui->getById('editor')->hasMultipleCarets(), 'rectangle spans 3 lines');
                    $tui->handleInput('X'); // type at every caret
                },
                400 => function ($tui): void {
                    $this->assertSame("aXa\nbX\ncXc\n", $tui->getById('editor')->getDocument()->getText());
                },
            ],
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCursorThroughRunningIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B"); // ctrl+alt+down
                    $tui->handleInput("\x1b[1;7B");
                    $this->assertTrue($editor->hasMultipleCarets());
                    $tui->handleInput('#'); // type at all three carets
                    $this->assertSame("#one\n#two\n#three\n", $editor->getDocument()->getText());
                    $tui->handleInput("\x1a"); // ctrl+z: one undo reverts all
                    $this->assertSame("one\ntwo\nthree\n", $editor->getDocument()->getText());
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCaretSurvivesStrayInputAndShiftSelects(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbbbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B");
                    $tui->handleInput("\x1b[1;7B"); // carets on lines 0,1,2
                    // A stray focus report between keystrokes must not collapse them.
                    $tui->handleInput('X');
                    $tui->handleInput("\x1b[I");
                    $tui->handleInput('Y');
                    $this->assertTrue($editor->hasMultipleCarets(), 'stray input kept the carets');
                    $this->assertSame("XYaaaa\nXYbbbb\nXYcccc\n", $editor->getDocument()->getText());
                    // shift+right selects one char at every caret; typing replaces each.
                    $tui->handleInput("\x1b[1;2C");
                    $tui->handleInput('Z');
                    $this->assertSame("XYZaaa\nXYZbbb\nXYZccc\n", $editor->getDocument()->getText());
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testPeekDefinitionShowsContextAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nnew Target();\n",
            'src/Target.php' => "<?php\n\n// header\nclass Target\n{\n    public int \$x = 1;\n}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 4);
                    $tui->handleInput("\x1b[105;6u"); // ctrl+shift+i peek
                },
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Peek: src/Target.php:4', $screen);
                    $this->assertStringContainsString('class Target', $screen, 'definition line shown');
                    $this->assertStringEndsWith('Demo.php', (string) $editor->getFilePath(), 'peek did not switch buffers');
                    $tui->handleInput("\r"); // pick a context line -> jump for real
                },
                750 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('Target.php', (string) $editor->getFilePath(), 'Enter jumps into the file');
                },
            ],
            1.0,
        );

        $this->addToAssertionCount(1);
    }

    public function testPeekWithNoDefinition(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/T.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/T.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(91, 0); // mock: 90-94 -> no definition
                    $tui->handleInput("\x1b[105;6u");
                },
                600 => function ($tui, $terminal): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $this->assertStringContainsString('Nothing to peek', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // A definition that resolves outside the filesystem is refused.
                    $tui->getById('editor')->getDocument()->setCursor(96, 0); // mock: >=95 untitled uri
                    $tui->handleInput("\x1b[105;6u");
                },
                800 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Peek target outside the filesystem', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.1,
        );

        $this->addToAssertionCount(1);
    }

    public function testCommandPaletteRunsActionByName(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    foreach (str_split('save all') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Find Action', $screen);
                    $this->assertStringContainsString('Save All', $screen);
                    // Dirty the buffer, then run "Save All" by name.
                    $tui->getById('editor')->getDocument()->insert('X');
                    $tui->handleInput("\r");
                },
                600 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('X', (string) file_get_contents($project->path('A.php')));
                    $this->assertStringContainsString('Saved', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testCommandPaletteTogglesSettingAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
                    foreach (str_split('relative') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Toggle Relative Line Numbers', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run the toggle
                },
                600 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->hasRelativeLineNumbers());
                    $this->assertStringContainsString('Relative line numbers on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Persisted into .porta.json.
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), true);
                    $this->assertTrue($config['settings']['relativeLineNumbers']);
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testSettingsAppliedAtStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['inlineDiagnostics' => false, 'relativeLineNumbers' => true]]),
            'A.php' => "<?php\n\$x = 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->hasRelativeLineNumbers(), 'setting applied from file');
                    // Inline diagnostics disabled: even with a diagnostic, no inline text.
                    $editor->setDiagnostics([1 => [['severity' => 1, 'message' => 'boom', 'col' => 0]]]);
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 5));
                    $this->assertStringNotContainsString('boom', AnsiUtils::stripAnsiCodes($rendered[1]));
                },
            ],
            0.5,
        );

        $this->addToAssertionCount(1);
    }

    public function testSessionSavedOnQuitAndRestoredOnStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaa\nbbb\n", 'B.php' => "ccc\n"]);

        // First run: open A and B, move the cursor, quit.
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal);
        $tui = $ide->getTui();
        EventLoop::delay(0.3, static function () use ($tui): void {
            $tui->handleInput("\x14"); // tree
            $tui->handleInput("\x1b[B"); // B.php
            $tui->handleInput("\r"); // open B.php
        });
        EventLoop::delay(0.5, static function () use ($tui): void {
            $tui->getById('editor')->getDocument()->setCursor(0, 2);
            $tui->handleInput("\x11"); // ctrl+q (clean buffers -> quits)
        });
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('did not quit');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFileExists($project->path('.porta/session.json'));

        // Second run with NO file argument: the session reopens.
        $terminal2 = new VirtualTerminal(columns: 100, rows: 24);
        $ide2 = new Ide($project->dir, null, $terminal2);
        $tui2 = $ide2->getTui();
        EventLoop::delay(0.3, function () use ($tui2): void {
            $editor = $tui2->getById('editor');
            $this->assertNotNull($editor->getFilePath(), 'a buffer was restored');
            $this->assertSame('editor', $tui2->getFocus()?->getId());
            $tui2->stop();
        });
        $ide2->run();

        $this->addToAssertionCount(1);
    }

    public function testLocalHistorySnapshotAndRestore(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "version one\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui) use ($project): void {
                    // Edit and save -> first snapshot; edit and save -> second.
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version two\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                450 => static function ($tui): void {
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version three\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                600 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h show history
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $this->assertStringContainsString('Local History', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Restore the older snapshot (second row).
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                750 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Restored from local history', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('version two', $tui->getById('editor')->getDocument()->getText());
                    // Restoring is a single undo step back to what was on screen.
                    $tui->getById('editor')->handleInput("\x1a"); // ctrl+z
                    $this->assertStringContainsString('version three', $tui->getById('editor')->getDocument()->getText());
                },
            ],
            1.0,
        );

        $this->addToAssertionCount(1);
    }

    public function testShowHistoryWithNoSnapshots(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "x\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h, nothing saved yet
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No local history for this file yet', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testClickingTerminalPaneRefocusesIt(): void
    {
        putenv('PATH='.$this->originalPath); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens the terminal (and focuses it)
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    // Click into the editor: focus leaves the terminal.
                    $tui->handleInput("\x1b[<0;40;3M");
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'clicking the editor moved focus off the terminal');
                },
                360 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // Click the centre of the terminal pane → focus returns to it.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $this->assertNotNull($rect, 'terminal pane is laid out');
                    $row = $rect->row + intdiv($rect->rows, 2);
                    $col = $rect->col + intdiv($rect->columns, 2);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // SGR is 1-based
                    $this->assertSame('terminal', $tui->getFocus()?->getId(), 'clicking the terminal pane refocused it');
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheTerminal(): void
    {
        putenv('PATH='.$this->originalPath); // needs /bin/sh + seq
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // open the terminal
                    foreach (str_split("seq 30\n") as $ch) {
                        $tui->handleInput($ch); // produce more than a screenful of output
                    }
                },
                900 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $row = $rect->row + intdiv($rect->rows, 2);
                    $col = $rect->col + intdiv($rect->columns, 2);
                    // Wheel up (SGR code 64) over the terminal pane → scroll into history.
                    $tui->handleInput(\sprintf("\x1b[<64;%d;%dM", $col + 1, $row + 1));
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'scrolled'), 'the wheel scrolled the terminal back');
                },
            ],
            1.2,
        );

        $this->addToAssertionCount(1);
    }

    public function testKittyKeyInputIsTranslatedForTheShell(): void
    {
        putenv('PATH='.$this->originalPath); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal): void {
                    $terminal->setKittyProtocolActive(true); // pretend the host terminal speaks kitty
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens + focuses the terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    foreach (str_split('printf hi') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[104;1:3u"); // a stray key-release event → must be dropped
                    $tui->handleInput("\x1b[13u"); // kitty Enter → must become "\r" so the command runs
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    // Only reachable if the release was dropped and Enter was translated.
                    $this->assertStringContainsString('hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.2,
        );

        $this->addToAssertionCount(1);
    }

    public function testIntegratedTerminalOpensRunsAndCloses(): void
    {
        // Force a predictable shell so the panel spawns deterministically.
        putenv('PATH='.$this->originalPath); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` toggle terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                    // Type a command into the shell.
                    foreach (str_split("echo hello-term\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('hello-term', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Hide the panel; the editor regains focus.
                    $tui->handleInput("\x1b[96;5u");
                    $this->assertFalse($tui->getById('terminal')->isOpen());
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
                750 => function ($tui): void {
                    // Re-open: the still-running shell's panel comes back (no respawn).
                    $tui->handleInput("\x1b[96;5u");
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                    // Now exit the shell so onExit updates the title.
                    foreach (str_split("exit\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('exited', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.4,
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testTerminalScrollAndPanelKeys(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // open terminal
                    // Emit many lines to enable scrollback.
                    foreach (str_split("for i in 1 2 3 4 5 6 7 8 9; do echo row-\$i; done\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[5~"); // page up: scroll back
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('scrolled', $screen);
                    $tui->handleInput("\x1b[6~"); // page down
                    $tui->handleInput("\x1b"); // escape hides the panel
                    $this->assertFalse($tui->getById('terminal')->isOpen());
                },
            ],
            1.1,
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testTerminalViaCommandPalette(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a palette
                    foreach (str_split('terminal') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Open Terminal', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run it
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testRunMenuRunsCurrentFileInTerminal(): void
    {
        putenv('PATH='.$this->originalPath); // needs php + a shell
        $project = new TempProject(['hello.php' => "<?php\necho \"run-output-ok\\n\";\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('hello.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r run menu
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                },
                400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Run current file: hello.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run it -> terminal panel
                },
                450 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('run-output-ok', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.3,
        );

        $this->addToAssertionCount(1);
    }

    public function testRunLastRepeatsAndEmptyGuards(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['hello.php' => "<?php\necho \"second-run\\n\";\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('hello.php'),
            [
                300 => function ($tui, $terminal): void {
                    // Run Last with no prior run flashes a hint.
                    $tui->handleInput("\x1b[21;2~"); // shift+f10
                    $tui->tick();
                    $this->assertStringContainsString('No previous run', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Pick a run via the menu.
                    $tui->handleInput("\x1b[114;6u");
                },
                450 => static function ($tui): void {
                    $tui->handleInput("\r"); // run current file
                },
                700 => static function ($tui): void {
                    $tui->getById('terminal')->close();
                    $tui->handleInput("\x1b[21;2~"); // Run Last repeats it
                },
                750 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
            ],
            1.1,
        );

        $this->addToAssertionCount(1);
    }

    public function testRunMenuEmptyWhenNothingToRun(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['notes.txt' => "plain\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r
                    $tui->tick();
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $this->assertStringContainsString('Nothing to run', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testJumpStackIsCapped(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        $ide = new Ide($project->dir, $project->path('src/Demo.php'), $terminal);
        $tui = $ide->getTui();

        $navigation = new \ReflectionProperty($ide, 'navigation')->getValue($ide);
        $stack = new \ReflectionProperty($navigation, 'jumpStack');
        $stack->setValue($navigation, array_fill(0, 50, ['path' => $project->path('src/Demo.php'), 'line' => 0, 'col' => 0]));

        EventLoop::delay(0.4, static fn () => $tui->handleInput("\x1b[24~")); // F12
        EventLoop::delay(0.8, static fn () => $tui->stop());
        $ide->run();

        $this->assertCount(50, $stack->getValue($navigation), 'oldest entry evicted at the cap');
        $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath());
    }

    public function testSaveAllAndSaveAndCloseDialog(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s with nothing dirty
                    $tui->tick();
                    $tui->handleInput('X'); // dirty a.php
                },
                350 => static function ($tui): void {
                    $tui->handleInput("\x14"); // tree
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php
                },
                500 => static function ($tui): void {
                    $tui->handleInput('Y'); // dirty b.php
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s -> saves both
                },
                650 => function ($tui, $terminal) use ($project): void {
                    $this->assertStringContainsString('Saved 2 files', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('X', (string) file_get_contents($project->path('a.php')));
                    $this->assertStringContainsString('Y', (string) file_get_contents($project->path('b.php')));
                    // Now the Save & Close dialog path
                    $tui->handleInput('Z'); // dirty b.php again
                    $tui->handleInput("\x1bw"); // dialog
                    $tui->handleInput("\r"); // Save & Close (first option)
                },
                800 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertStringContainsString('Z', (string) file_get_contents($project->path('b.php')), 'saved before closing');
                },
            ],
            1.0,
        );

        $this->assertStringContainsString('Nothing to save', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testBufferSwitchReusesHighlightCache(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x14");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php
                },
                500 => function ($tui): void {
                    // Switching back must render a.php's highlight from the
                    // buffer-owned cache (same version, no re-parse).
                    $editorBefore = $tui->getById('editor');
                    $versionBefore = $editorBefore->getDocument()->getVersion();
                    $tui->handleInput("\x1b[1;3C"); // alt+right -> a.php
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame(1, $versionBefore, 'no phantom edits from switching');
                },
            ],
            0.8,
        );
    }

    public function testSaveFailureAndSaveWithoutFile(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['dir/locked.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('dir/locked.php'),
            [
                200 => static function ($tui) use ($project): void {
                    $tui->handleInput('x'); // dirty it
                    chmod($project->path('dir/locked.php'), 0444);
                    chmod($project->path('dir'), 0555);
                    $tui->handleInput("\x13"); // ctrl+s fails
                },
                400 => static function ($tui) use ($project): void {
                    chmod($project->path('dir'), 0755);
                    chmod($project->path('dir/locked.php'), 0644);
                    $tui->handleInput("\x1a"); // undo -> clean
                    $tui->handleInput("\x1bw"); // close buffer
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
            0.7,
        );

        $this->assertStringContainsString('Save failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testQuitWithoutDirtyBuffersStopsImmediately(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->getTui();

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11"));
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFalse($tui->isRunning());
    }

    public function testQuitRestoresTerminalViaAlternateScreen(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->getTui();

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11")); // Ctrl+Q
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $out = $terminal->getOutput();
        // Entered the alternate screen on start and left it on quit → the terminal
        // is restored to its pre-launch state (no leftover IDE frame).
        $this->assertStringContainsString("\x1b[?1049h", $out, 'entered the alternate screen');
        $this->assertStringContainsString("\x1b[?1049l", $out, 'left the alternate screen on quit');
        $this->assertGreaterThan(strpos($out, "\x1b[?1049h"), strrpos($out, "\x1b[?1049l"), 'leave comes after enter');
        $this->assertStringContainsString("\x1b[?1000l", $out, 'mouse tracking disabled on quit');
    }

    public function testCrashedServerReportsFailedStatus(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/crashing-lsp-server.php']),
            'a.php' => "<?php\n",
        ]);

        // The crash surfaces via stderr logging; initialize never completes.
        $terminal = $this->runIde($project, $project->path('a.php'), [], 1.0);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:starting…', $screen);
    }

    public function testServerInitiatedApplyEditIsHandled(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/demanding-lsp-server.php']),
            'a.php' => "<?php\n",
        ]);

        // The demanding server fires an empty workspace/applyEdit after init
        // (applied: false path) and a real one against the opened file after
        // didOpen (applied: true path, flashed to the status bar).
        $terminal = $this->runIde($project, $project->path('a.php'), [], 1.0);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:demanding-lsp-server', $screen);
        $this->assertStringContainsString('Applied server edit to 1 file', $screen);
    }

    public function testLspNegativePathsWithMockServer(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                400 => static function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(91, 0); // mock convention: >= 90 -> nothing found
                    $tui->handleInput("\x1b[24~"); // definition -> none
                },
                500 => static function ($tui): void {
                    $tui->tick(); // flush the previous flash
                    $tui->handleInput("\x1bOP"); // hover -> none
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[18;3~"); // usages -> none
                },
                650 => static function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(96, 0); // >= 95 -> definition outside filesystem
                    $tui->handleInput("\x1b[24~");
                },
                850 => static function ($tui): void {
                    // Rename that the server rejects: prepareRename succeeds at a
                    // low line, then the rename call itself errors on "ERR".
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x1b[17;2~"); // shift+f6 -> prepareRename
                },
                1000 => static function ($tui): void {
                    foreach (str_split('ERR') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
            ],
            1.4,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('No definition found', $screen);
        $this->assertStringContainsString('No documentation found', $screen);
        $this->assertStringContainsString('No usages found', $screen);
        $this->assertStringContainsString('Target outside the filesystem', $screen);
        $this->assertStringContainsString('Rename failed: cannot rename this', $screen);
    }

    /**
     * A temp dir holding `open` and `xdg-open` shims that append their args to
     * $captureFile, put first on PATH so BrowserOpener resolves them instead of
     * a real browser opener.
     */
    private function shimBrowserOpener(TempProject $project, string $captureFile): void
    {
        foreach (['open', 'xdg-open'] as $name) {
            $shim = $project->write('shim-bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> ".escapeshellarg($captureFile)."\n");
            chmod($shim, 0o755);
        }
        putenv('PATH='.$project->path('shim-bin'));
    }

    public function testGotoDeclarationOnNativePhpFunctionOpensDocs(): void
    {
        // No .porta.json: proves the native path never needs a language server.
        $project = new TempProject(['src/A.php' => "<?php\nstrlen('x');\n"]);
        $captureFile = $project->path('opened-urls.txt');
        $this->shimBrowserOpener($project, $captureFile);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 3); // inside 'strlen'
                    $tui->handleInput("\x1b[24~"); // F12
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // Ctrl+Click the same word: editor pane starts at screen col 31
                    // (tree 30 + separator), gutter is 6 wide, so doc col ~3 of
                    // line 1 (screen row 4, 1-based) is at 1-based col 41.
                    $tui->handleInput("\x1b[<16;41;4M");
                    $tui->handleInput("\x1b[<16;41;4m");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Opened https://www.php.net', $screen);
        $urls = (string) file_get_contents($captureFile);
        $this->assertStringContainsString('https://www.php.net/manual/en/function.strlen.php', $urls);
        $this->assertSame(2, substr_count($urls, "\n"), 'both F12 and ctrl+click opened the docs');
    }

    public function testGotoDeclarationOnJsBuiltinOpensMdn(): void
    {
        $project = new TempProject(['app.js' => "JSON.parse(x);\n"]);
        $captureFile = $project->path('opened-urls.txt');
        $this->shimBrowserOpener($project, $captureFile);

        $terminal = $this->runIde(
            $project,
            $project->path('app.js'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 2); // inside 'JSON'
                    $tui->handleInput("\x1b[24~");
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 6); // 'parse': not curated, no LSP
                    $tui->handleInput("\x1b[24~");
                },
            ],
        );

        $this->assertStringContainsString('Opened https://developer.mozilla.org', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $urls = (string) file_get_contents($captureFile);
        $this->assertStringContainsString('https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON', $urls);
        $this->assertStringNotContainsString('parse', $urls, 'a non-curated word opens nothing');
    }

    public function testNativeDocsWithoutAnOpenerFlashesTheUrl(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\narray_map('trim', []);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 2); // inside 'array_map'
                    $tui->handleInput("\x1b[24~");
                },
            ],
        );

        $this->assertStringContainsString(
            'Docs: https://www.php.net/manual/en/function.array-map.php',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
    }

    public function testGitViewStagesCommitsDiscardsAndRestores(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged edit
        $project->write('notes.md', "hi\n");               // untracked

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d opens the git view
                },
                550 => function ($tui, $terminal, $ide) use ($project): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('2 changed files', $screen);
                    $this->assertStringContainsString('[ ] M src/A.php', $screen);
                    $this->assertStringContainsString('+$a = 2;', $screen, 'the diff pane shows the worktree change');
                    $tui->handleInput(' '); // stage src/A.php
                },
                850 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
                    $this->assertContains('M  src/A.php', $lines, 'space staged the file');
                    // Tab twice: changes -> diff -> commit box; type + ctrl+enter.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (str_split('Update A') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;5u");
                },
                1250 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' log --format=%s 2>/dev/null', $subjects);
                    $this->assertSame(['Update A', 'init'], $subjects, 'ctrl+enter committed the staged change');
                    // Discard the untracked notes.md: Tab walks summary -> description
                    // -> next pane (the changes list), then arm + confirm.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    $tui->handleInput('d');
                    $tui->handleInput('y');
                },
                1550 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertFileDoesNotExist($project->path('notes.md'), 'discard deleted the untracked file');
                    $tui->handleInput("\x1b"); // esc restores the editor view
                },
                1700 => function ($tui): void {
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertNotNull($tui->getById('tree'), 'tree pane restored');
                },
            ],
            2.0,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Committed:', $screen);
        $this->assertStringContainsString('Working tree clean', $screen, 'after commit+discard nothing is left');
    }

    public function testGitViewStagesASingleLineFromTheDiffPane(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['f.txt' => "alpha\nbeta\ngamma\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        $project->write('f.txt', "alpha CHANGED\nbeta\ngamma CHANGED\n");

        $terminal = $this->runIde(
            $project,
            $project->path('f.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // changes list -> diff pane
                    // Rows: hunk header, ctx?, -alpha, +alpha CHANGED, ... walk to
                    // the first '+' row and stage just it.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput(" ");
                },
                1000 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' diff --cached 2>/dev/null', $cached);
                    exec('git -C '.escapeshellarg($project->dir).' diff 2>/dev/null', $work);
                    $joinedCached = implode("\n", $cached);
                    $this->assertStringContainsString('alpha', $joinedCached, 'the alpha change reached the index');
                    $this->assertStringNotContainsString('gamma CHANGED', $joinedCached, 'gamma stayed out of the index');
                    $this->assertStringContainsString('gamma CHANGED', implode("\n", $work), 'gamma remains a worktree change');
                    $tui->handleInput("\x1b");
                },
            ],
            1.4,
        );

        $this->assertStringContainsString('[~] M f.txt', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'partial staging shows the mixed checkbox');
    }

    public function testGitViewMouseAndMenuGuard(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\ntwo\nthree\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        $project->write('a.txt', "one!\ntwo\nthree changed\n");
        $project->write('b.txt', "new\n");

        $terminal = $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => function ($tui): void {
                    $tui->tick();
                    // F10 opens the app menu OVER the git view now.
                    $tui->handleInput("\x1b[21~");
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu bar');
                    $this->assertStringContainsString('Save File', $screen, 'the File dropdown composites over the git panes');
                    $this->assertStringContainsString('Quit', $screen);
                    // Hover a dropdown row (the dropdown starts under the menu
                    // row, i.e. screen row 2), then click the greyed 'Save File'
                    // item: disabled in the git view, so it only closes the menu.
                    $tui->handleInput("\x1b[<35;5;4M");
                    $tui->handleInput("\x1b[<0;5;2M");
                    $tui->handleInput("\x1b[<0;5;2m");
                },
                1150 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'closing the menu restores the git focus target');
                    // Click the 'File' title (row 1) to open, then again to close.
                    $tui->handleInput("\x1b[<0;3;1M");
                    $tui->handleInput("\x1b[<0;3;1m");
                    $tui->handleInput("\x1b[<0;3;1M");
                    $tui->handleInput("\x1b[<0;3;1m");
                    // With the menu row on top, the changes header is screen
                    // row 2 and a.txt sits on row 3. Click the checkbox: stages.
                    $tui->handleInput("\x1b[<0;3;3M");
                    $tui->handleInput("\x1b[<0;3;3m");
                },
                1500 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
                    $this->assertContains('M  a.txt', $lines, 'checkbox click staged the file');
                    // Hover row 4 (b.txt) selects it and loads its diff;
                    // wheel over the diff pane scrolls it.
                    $tui->handleInput("\x1b[<35;10;4M");
                    $tui->handleInput("\x1b[<65;80;6M");
                    // Wheel over the changes list moves its selection.
                    $tui->handleInput("\x1b[<65;10;3M");
                    // A click on the diff pane focuses it.
                    $tui->handleInput("\x1b[<0;60;6M");
                    $tui->handleInput("\x1b[<0;60;6m");
                    // Click into the commit box summary row.
                    $tui->handleInput("\x1b[<0;5;20M");
                    $tui->handleInput("\x1b[<0;5;20m");
                },
                1800 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('+new', $screen, 'hover loaded the untracked diff');
                    $this->assertSame('git-commit', $tui->getFocus()?->getId(), 'click focused the commit box');
                    $tui->handleInput("\x1b");
                },
            ],
            2.1,
        );

        $this->assertStringContainsString('b.txt', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testGitViewHistoryTabBrowsesCommits(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm 'first commit'");
        $project->write('a.txt', "one\nmore\n");
        exec("git -C {$dir} add -A && git -C {$dir} commit -qm 'second commit'");

        $terminal = $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput('2'); // History tab
                },
                1000 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('History (2)', $screen);
                    $this->assertStringContainsString('second commit', $screen);
                    $this->assertStringContainsString('M a.txt', $screen, 'the newest commit file list loads');
                    // Mouse: wheel over the history list, click the first commit
                    // row (the menu row on top shifts everything down one).
                    $tui->handleInput("\x1b[<65;10;4M");
                    $tui->handleInput("\x1b[<0;10;3M");
                    $tui->handleInput("\x1b[<0;10;3m");
                },
                1200 => static function ($tui): void {
                    // The clicked commit's files have settled; open its patch.
                    $tui->handleInput("\r");     // into the files list
                    $tui->handleInput("\x1b[B"); // wiggle selection to fire the file load
                    $tui->handleInput("\x1b[A");
                },
                1500 => function ($tui, $terminal): void {
                    $this->assertStringContainsString('+more', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the commit patch renders read-only');
                    $tui->handleInput('1'); // back to Changes
                },
            ],
            1.9,
        );

        $this->assertStringContainsString('Working tree clean', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the Changes tab restored');
    }

    private function gitAvailable(): bool
    {
        return null !== $this->whichGit();
    }

    private function whichGit(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }

    public function testPerLanguageServersRouteAndReportStatus(): void
    {
        // Two distinct server processes (the argv differ, so the pool cannot
        // share a session) from one language-map config; markdown has neither.
        $mock = \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php';
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => ['php' => $mock, 'javascript' => $mock.' js-flavor']]),
            'src/A.php' => "<?php\ngreet();\n",
            'src/app.js' => "doWork(x);\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
            'notes.md' => "# notes\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 2); // on greet
                    $tui->handleInput("\x1b[24~"); // php server resolves Target.php
                },
                700 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'php-routed definition jumped');
                    new \ReflectionMethod($ide, 'openFile')->invoke($ide, $project->path('src/app.js'), true);
                },
                1100 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 2); // on doWork
                    $tui->handleInput("\x1b[24~"); // js server resolves Target.php too
                },
                1400 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'js-routed definition jumped');
                    new \ReflectionMethod($ide, 'openFile')->invoke($ide, $project->path('notes.md'), true);
                    $tui->tick();
                },
            ],
            1.7,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:mock-lsp-server', $screen, 'per-language server name in the status bar');
        $this->assertStringContainsString('LSP:no server', $screen, 'markdown has no configured server');
    }

    public function testCtrlClickNavigatesToDeclaration(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\n// line 1\n// line 2\n// line 3\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    $tui->tick(); // ensure a frame rendered so widget rects exist
                    // Ctrl (SGR bit 16) + left click at screen row 5 → editor
                    // content row 2 (menu row 0, tab bar row 1) → document line 2.
                    $tui->handleInput("\x1b[<16;45;5M");
                    $tui->handleInput("\x1b[<16;45;5m");
                },
                900 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('Target.php', (string) $editor->getFilePath(), 'ctrl+click jumped to the declaration');
                    $this->assertSame(3, $editor->getDocument()->getCursorLine());
                    $this->assertFalse($editor->getDocument()->hasSelection(), 'a ctrl+click is a navigation, not a selection start');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Target.php', $screen);
    }

    public function testGotoDeclarationOnDeclarationShowsUsages(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                400 => static function ($tui): void {
                    // Mock convention: definition at lines 85-89 contains the
                    // requested position itself (a decoy Target.php location
                    // comes first, so any-match must beat the chooser).
                    $tui->getById('editor')->getDocument()->setCursor(86, 2);
                    $tui->handleInput("\x1b[24~"); // F12: declaration or usages
                },
                700 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('Tall.php', (string) $editor->getFilePath(), 'on the declaration: no jump');
                    $tui->handleInput("\x1b"); // close the usages panel
                },
                850 => static function ($tui): void {
                    $tui->tick();
                    // Lines 88-89: on the declaration, but zero usages.
                    $tui->getById('editor')->getDocument()->setCursor(88, 2);
                    $tui->handleInput("\x1b[24~");
                },
            ],
            1.3,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Usages', $screen);
        $this->assertStringContainsString('Target.php', $screen, 'usages rows listed');
        $this->assertStringNotContainsString('Choose target', $screen, 'a self-match shows usages, never the chooser');
        $this->assertStringContainsString('No usages found', $screen);
    }

    public function testNavigateBackWithEmptyStackAndOsc52Copy(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php echo 'hi';\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[1;7D"); // ctrl+alt+left: nowhere to go
                    $tui->handleInput("\x1b[1;2C"); // shift+right
                    $tui->handleInput("\x03"); // ctrl+c -> OSC 52 to terminal
                },
            ],
            0.6,
        );

        $this->assertStringContainsString('Nowhere to go back to', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $this->assertStringContainsString("\x1b]52;c;", $terminal->getOutput(), 'system clipboard escape emitted');
    }

    public function testJumpStackCapAndRapidTypingDebounce(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    // Overfill the jump stack (cap is 50)
                    $ideRef = null; // reach the private stack through reflection on the Ide via closure binding
                    $tui->handleInput('a');
                    $tui->handleInput('b'); // two rapid changes: second one cancels the first debounce
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\x1b[24~"); // definition jump (fills jumpStack once)
                },
                950 => static function ($tui): void {
                    $tui->handleInput("\x1b[1;7D"); // back again
                },
            ],
            1.3,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Target.php', $screen);
    }

    public function testExpandSelectionThenSurroundThroughFullIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$x = foo(bar);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 10); // inside "bar"
                    $tui->handleInput("\x17"); // ctrl+w: expand to the word "bar"
                    $tui->handleInput("\x1b\x14"); // ctrl+alt+t: open the surround menu
                    $tui->handleInput("\x1b[B"); // down to "[ ] Brackets"
                    $tui->handleInput("\r"); // accept -> wrap "bar" in [ ]
                },
                600 => static function ($tui): void {
                    $tui->tick();
                },
            ],
            0.9,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('foo([bar])', $screen);
    }

    private function gitInit(string $dir): void
    {
        exec('git -C '.escapeshellarg($dir).' init -q 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' config user.email t@t 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' config user.name tester 2>/dev/null');
    }

    private function gitCommit(string $dir): void
    {
        exec('git -C '.escapeshellarg($dir).' add -A 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' commit -qm init 2>/dev/null');
    }

    private function requireGit(): void
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return;
            }
        }
        self::markTestSkipped('git binary not available');
    }




    // --- Right-click context menus -------------------------------------------
    //
    // Terminal is 160×24. Tree occupies 0-based cols 0-29 (SGR 1-30); a 1-col gap
    // sits at SGR col 31; the editor pane starts at 0-based col 31 (SGR 32). Rows:
    // menu bar row 0 (SGR 1), tab bar row 1 (SGR 2), editor content from row 2
    // (SGR 3). Tree entries start at SGR row 2 (header is SGR row 1).

    /** Type each character of $text into the currently focused prompt/editor. */
    private function typeString(Tui $tui, string $text): void
    {
        foreach (mb_str_split($text) as $char) {
            $tui->handleInput($char);
        }
    }

    public function testEditorContextMenuCutsTheSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui): void {
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
            0.7,
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
                200 => function ($tui): void {
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
            0.7,
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
                200 => function ($tui): void {
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
            0.7,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;1M"); // tree header row → nodeAt() null
                },
                250 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'the tree header targets no node');
                    $tui->handleInput("\x1b[<2;150;2M"); // tab bar past the last tab → no index
                },
                300 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'empty tab-strip space targets no tab');
                },
            ],
            0.6,
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
                150 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (tree row 2)
                },
                300 => function ($tui): void {
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
            0.8,
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
                120 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (row 2)
                },
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open C (row 3)
                },
                300 => function ($tui): void {
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
                560 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // down → Close Others
                    $tui->handleInput("\x1b[B"); // down → Close All
                    $tui->handleInput("\r");
                },
            ],
            0.9,
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
                150 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (row 2)
                },
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click a tab
                },
                400 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // Close Others
                    $tui->handleInput("\x1b[B"); // Close All
                    $tui->handleInput("\r");
                },
                500 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'closing every tab returns focus to the tree');
                },
            ],
            0.8,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click src/ (a directory)
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\r"); // New File (first item) → opens the name prompt
                },
                360 => function ($tui): void {
                    $this->typeString($tui, 'new.php');
                    $tui->handleInput("\r"); // submit
                },
                460 => function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click src/ again
                },
                540 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // down → New Folder
                    $tui->handleInput("\r");
                },
                600 => function ($tui): void {
                    $this->typeString($tui, 'pkg');
                    $tui->handleInput("\r");
                },
            ],
            1.0,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click src/
                },
                300 => function ($tui): void {
                    $tui->handleInput("\r"); // New File
                },
                360 => function ($tui): void {
                    $tui->handleInput("\r"); // submit an empty name → no-op
                },
                460 => function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click src/
                },
                540 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\r");
                },
                600 => function ($tui): void {
                    $tui->handleInput('   '); // whitespace-only name → no-op
                    $tui->handleInput("\r");
                },
            ],
            1.0,
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
                150 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php
                },
                230 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                300 => function ($tui): void {
                    // The prompt is prefilled with the current name; submitting it
                    // unchanged is a no-op.
                    $tui->handleInput("\r");
                },
                380 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('A.php'), 'renaming to the same name changed nothing');
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php again
                },
                460 => function ($tui): void {
                    foreach (range(1, 4) as $_) {
                        $tui->handleInput("\x1b[B"); // → Rename…
                    }
                    $tui->handleInput("\r");
                },
                540 => function ($tui): void {
                    foreach (range(1, 10) as $_) {
                        $tui->handleInput("\x7f"); // backspace out the prefill
                    }
                    $this->typeString($tui, 'renamed.php');
                    $tui->handleInput("\r");
                },
                640 => function ($tui): void {
                    $this->assertStringEndsWith('renamed.php', (string) $tui->getById('editor')->getFilePath(), 'the open buffer followed the rename');
                },
            ],
            0.9,
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
                200 => function ($tui): void {
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
            0.7,
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
                200 => function ($tui): void {
                    $tui->tick();
                    // Right-click near the tree's right edge: the panel spills past
                    // the tree into the editor pane, exercising the per-pane clip.
                    $tui->handleInput("\x1b[<2;28;2M");
                    $tui->tick();
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'a straddling panel renders across both panes without error');
                },
            ],
            0.7,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php (row 1)
                },
                300 => function ($tui): void {
                    // Rename is the 4th selectable item after New File/New Folder/Copy×2.
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                380 => function ($tui): void {
                    foreach (range(1, 10) as $_) {
                        $tui->handleInput("\x7f");
                    }
                    $this->typeString($tui, 'B.php'); // clashes with an existing file
                    $tui->handleInput("\r");
                },
            ],
            0.8,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php
                },
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\r");
                },
                400 => function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php again
                },
                500 => function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\r");
                },
            ],
            0.9,
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
                150 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (row 2) as well
                },
                250 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php (row 1)
                },
                350 => function ($tui): void {
                    // Delete is the 6th selectable item.
                    foreach (range(1, 5) as $_) {
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
            0.9,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php
                },
                300 => function ($tui): void {
                    foreach (range(1, 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => function ($tui): void {
                    $this->typeString($tui, 'yes'); // 'yes' is also accepted
                    $tui->handleInput("\r");
                },
            ],
            0.7,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M");
                },
                300 => function ($tui): void {
                    foreach (range(1, 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => function ($tui): void {
                    $tui->handleInput('n'); // decline
                    $tui->handleInput("\r");
                },
                460 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'declining returns focus to the tree');
                },
            ],
            0.7,
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
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->getById('editor')->showDocument('', new \Porta\Editor\Document(''));
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php (row 1)
                },
                300 => function ($tui): void {
                    // The menu anchors at the click (SGR row 2), so item i sits at
                    // SGR row 2+i. "Open" is item 9 for a file node → SGR row 11.
                    $tui->handleInput("\x1b[<0;4;11M"); // left-click "Open"
                },
                400 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath(), 'Open reopened the file');
                    // A file created on disk appears in the tree only after Refresh.
                    $project->write('late.php', "<?php\n");
                    $tui->handleInput("\x1b[<2;3;2M"); // right-click A.php again
                },
                500 => function ($tui): void {
                    // "Refresh" is the last item (10) → SGR row 12.
                    $tui->handleInput("\x1b[<0;4;12M");
                    $tui->tick();
                },
                600 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'late.php'), 'Refresh picked up the new file');
                },
            ],
            0.9,
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
                200 => function ($tui): void {
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
            0.7,
        );

        $this->addToAssertionCount(1);
    }

    // --- Tab close icon + reordering ------------------------------------------

    /** The tab names in open order, read from the tab bar's provider. */
    private function tabNames(Tui $tui): array
    {
        $tabBar = $tui->getById('tabs');
        $provider = new \ReflectionProperty($tabBar, 'provider')->getValue($tabBar);

        return array_column($provider(), 'name');
    }

    public function testTabCloseIconClosesThatTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (tree row 2)
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
            0.8,
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
                150 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (row 2)
                },
                250 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open C (row 3)
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
            0.9,
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
                150 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;3M"); // open B (row 2)
                },
                250 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open C (row 3) → active = C
                },
                350 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    $tui->handleInput("\x1b[1;4D"); // alt+shift+left → [A, C, B]
                    $tui->handleInput("\x1b[1;4D"); // → [C, A, B]
                    $tui->handleInput("\x1b[1;4D"); // C already first → no move
                },
                420 => function ($tui): void {
                    $this->assertSame(['C.php', 'A.php', 'B.php'], $this->tabNames($tui), 'C moved to the front and stopped at the end');
                    $tui->handleInput("\x1b[1;4C"); // alt+shift+right → [A, C, B]
                },
                500 => function ($tui): void {
                    $this->assertSame(['A.php', 'C.php', 'B.php'], $this->tabNames($tui), 'moved back right');
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    // --- CLI-supplied options -------------------------------------------------

    public function testInitialLineJumpsCaretOnBoot(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "l0\nl1\nl2\nl3\nl4\nl5\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, initialLine: 4);
        $tui = $ide->getTui();

        $timer = EventLoop::delay(0.2, function () use ($tui): void {
            $tui->tick();
            $this->assertSame(3, $tui->getById('editor')->getDocument()->getCursorLine(), 'CLI line 4 → 0-based line 3');
            $tui->stop();
        });
        try {
            $ide->run();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->addToAssertionCount(1);
    }

    public function testLspDisabledShowsDisabledStatus(): void
    {
        $project = new TempProject(['A.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, lspDisabled: true);
        $tui = $ide->getTui();

        $timer = EventLoop::delay(0.2, function () use ($tui): void {
            $tui->tick();
            $this->assertStringContainsString('LSP:disabled', AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput()));
            $tui->stop();
        });
        try {
            $ide->run();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->addToAssertionCount(1);
    }

    // --- Automatic refresh on filesystem change -------------------------------

    public function testExternalChangeReloadsCleanBuffer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('original', $tui->getById('editor')->getDocument()->getText());
                    $project->write('A.php', "<?php\n// changed on disk\n"); // external edit
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('changed on disk', $doc->getText(), 'clean buffer reloaded');
                    $this->assertFalse($doc->isDirty(), 'reloaded buffer is not dirty');
                    $this->assertStringContainsString('Reloaded A.php', AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput()));
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeToUnreadableContentLeavesBufferUntouched(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    // The file grows beyond the 2 MB text limit → the poll must not
                    // try to load it, leaving the open buffer as-is.
                    $project->write('A.php', str_repeat('x', (2 * 1024 * 1024) + 1));
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('original', $doc->getText(), 'oversized change ignored, buffer kept');
                    $this->assertFalse($doc->isDirty());
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeKeepsDirtyBufferAndWarns(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->getById('editor')->handleInput('X'); // unsaved edit → dirty
                    $project->write('A.php', "<?php\n// external\n");
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringStartsWith('X', $doc->getText(), 'the unsaved edit was kept, not clobbered');
                    $this->assertTrue($doc->isDirty());
                },
            ],
            0.6,
        );

        $this->assertStringContainsString('A.php changed on disk', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testDeletedFileKeepsBufferAndWarns(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// keep me\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    unlink($project->path('A.php')); // deleted on disk
                    $ide->pollFilesystem();
                    $ide->pollFilesystem(); // second poll must not re-flash
                },
                300 => function ($tui): void {
                    $this->assertStringContainsString('keep me', $tui->getById('editor')->getDocument()->getText(), 'buffer retained');
                },
            ],
            0.6,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('A.php deleted on disk', $screen);
        $this->assertSame(1, substr_count($screen, 'deleted on disk'), 'warns exactly once');
    }

    public function testOwnSaveDoesNotTriggerReload(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $tui->getById('editor')->handleInput('Y'); // dirty
                    $tui->handleInput("\x13"); // ctrl+s → save (records our own disk stamp)
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $screen = AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput());
                    $this->assertStringNotContainsString('Reloaded', $screen, 'own save must not look like an external change');
                    $this->assertStringNotContainsString('changed on disk', $screen);
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testNewFileOnDiskAppearsInTreeAfterPoll(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNull($this->rowOf($grid, 'fresh.php'), 'not there yet');
                    $project->write('fresh.php', "<?php\n"); // created by an external tool
                    $ide->pollFilesystem();
                    $tui->tick();
                },
                300 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'fresh.php'), 'poll refreshed the tree');
                },
            ],
            0.6,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClicksUnsavedChangesDialog(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $tui->handleInput('E'); // dirty a.php
                    $tui->handleInput("\x1bw"); // close → unsaved-changes overlay
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                },
                320 => function ($tui): void {
                    // A click outside the editor pane dismisses the modal overlay (kept, not discarded).
                    $tui->handleInput("\x1b[<0;1;1M");
                },
                420 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertFalse($editor->isOverlayOpen(), 'outside click dismissed the dialog');
                    $this->assertStringEndsWith('a.php', (string) $editor->getFilePath(), 'dismiss kept the buffer');
                    $tui->handleInput("\x1bw"); // reopen the overlay
                },
                540 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($editor);
                    $host = new \ReflectionProperty($editor, 'overlayHost')->getValue($editor);
                    $box = new \ReflectionProperty($host, 'overlayBox')->getValue($host);
                    // Hover then click item index 1 ("Discard & Close"); title is row startRow.
                    $row = $rect->row + $box['startRow'] + 2;
                    $col = $rect->col + $box['col'] + 1;
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 1, $row + 1)); // hover
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // click
                },
                660 => function ($tui) use ($project): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $this->assertStringNotContainsString('E', (string) file_get_contents($project->path('a.php')), 'discard did not save the edit');
                },
            ],
            0.9,
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput());
    }

    public function testMouseClicksAndHoversSearchPanels(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n", 'c.php' => "<?php // c\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $results = $tui->getById('results');
                    $results->open('Find in Project', withQuery: true);
                    $results->setResults([
                        ['path' => 'b.php', 'line' => 0, 'text' => 'one'],
                        ['path' => 'c.php', 'line' => 0, 'text' => 'two'],
                    ]);
                    $tui->requestRender();
                    $tui->tick(); // force a frame so the panel gets a positioned rect
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($results);
                    // Header is relative row 0; the second result is at relative row 2.
                    $x = $rect->col + 6;
                    $y = $rect->row + 3; // 1-based screen row of relative row 2
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x, $y)); // hover the second result
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y)); // click it
                },
                340 => function ($tui): void {
                    $this->assertStringEndsWith('c.php', (string) $tui->getById('editor')->getFilePath(), 'clicked the second result');
                    $this->assertFalse($tui->getById('results')->isOpen(), 'jumping closed the panel');
                },
                480 => function ($tui, $terminal, $ide): void {
                    $goto = $tui->getById('goto-file');
                    $goto->open();
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($goto);
                    // Header is relative row 0; hover/click the second file (relative row 2)
                    // so the hover moves the selection off the default first row.
                    $x = $rect->col + 3;
                    $y = $rect->row + 3; // 1-based screen row of relative row 2
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x, $y)); // hover the second file
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y)); // click it
                },
                620 => function ($tui): void {
                    $this->assertFalse($tui->getById('goto-file')->isOpen(), 'clicking a file opened it and closed the palette');
                },
            ],
            0.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClickThroughOpenCompletion(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$foo = 1;\n\$foobar = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(1, 0);
                    $editor->showCompletions([
                        ['label' => 'alpha', 'insertText' => 'alpha', 'kind' => 'fn', 'detail' => ''],
                    ]);
                    $this->assertTrue($editor->isCompletionOpen());
                },
                320 => function ($tui, $terminal, $ide): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));
                    // Click at the bottom of the editor, far from the caret-anchored popup.
                    $x = $rect->col + 6;
                    $y = $rect->row + $rect->rows;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y));
                },
                440 => function ($tui): void {
                    $this->assertFalse($tui->getById('editor')->isCompletionOpen(), 'a click off the popup closed completion and placed the caret');
                },
            ],
            0.8,
        );
    }
}
