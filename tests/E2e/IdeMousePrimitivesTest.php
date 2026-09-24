<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionBox;
use Porta\Editor\CompletionItem;
use Porta\Ide;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Raw mouse behavior in the editor: clicks, drags, multi-click
 * selection, column selection, click-through popups.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeMousePrimitivesTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testLeftClickBelowTheEditorIsIgnored(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\necho 2;\necho 3;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, Ide $ide): void {
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));

                    // Control: a click INSIDE the editor (row for line 2) routes
                    // through handleEditorClick and moves the caret.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $rect->row + 3));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 8, $rect->row + 3));
                    $this->assertSame(2, $tui->getById('editor')->getDocument()->cursorLine, 'an in-editor click places the caret');

                    // A left press one row below the editor's last row (the status
                    // band) still reaches handleEditorClick, whose guard rejects the
                    // coordinate outside the editor rect — the caret does not move.
                    $row = $rect->row + $rect->rows + 1; // 1-based screen row just below the editor
                    $this->assertLessThanOrEqual(24, $row, 'the click lands on-screen below the editor');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 8, $row));
                },
                300 => function ($tui): void {
                    $this->assertSame(2, $tui->getById('editor')->getDocument()->cursorLine, 'the click below the editor did not move the caret');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSidebarHoverHighlightsRow(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, Ide $ide): void {
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    // Header is relative row 0, a.php (selected) is row 1; hover b.php at row 2.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 3, $rect->row + 3));
                },
                280 => function ($tui, $terminal, Ide $ide): void {
                    $tree = $tui->getById('tree');
                    $line = Paint::lines($tree, new RenderContext(30, 24))[2];
                    $this->assertStringContainsString("\x1b[48;5;236m", $line, 'hovered tree row is highlighted');
                    // Move off the tree into the editor → the hover clears.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $editorRect->col + 5, $editorRect->row + 3));
                },
                360 => function ($tui): void {
                    $tree = $tui->getById('tree');
                    $joined = \implode('', Paint::lines($tree, new RenderContext(30, 24)));
                    $this->assertStringNotContainsString("\x1b[48;5;236m", $joined, 'hover cleared after leaving the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClickAndWheel(): void
    {
        \putenv('PATH=/nonexistent');
        // Root-level files so the tree lists them directly (dirs sort first,
        // so keeping it flat makes row math simple).
        // Line 3 is long so the horizontal-wheel step has room to scroll.
        $long = "<?php\n".\implode("\n", \array_map(static fn (int $i): string => "// line {$i}".(3 === $i ? \str_repeat(' pad', times: 30) : ''), \range(1, end: 60)))."\n";
        $project = new TempProject(['A.php' => $long, 'B.php' => "<?php\n// this is B\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                // Editor click: place the cursor on a lower line.
                300 => function (Tui $tui): void {
                    $this->frame($tui); // a real frame, so widget rects exist
                    // Menu bar owns row 0, tab bar row 1, so the editor content
                    // starts at screen row 2; a click at screen row 4 lands on
                    // document line 2 (col 45 -> past the gutter).
                    $tui->handleInput("\x1b[<0;45;5M");
                },
                400 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertSame(2, $editor->getDocument()->cursorLine, 'click moved cursor');
                    // Horizontal wheel over the editor slides the view without
                    // moving the caret; shift+wheel-up brings it back.
                    $tui->handleInput("\x1b[<67;45;6M");
                    $scroll = EditorState::scroll($editor);
                    $this->assertSame(6, $scroll->hScroll, 'wheel-right scrolled the editor sideways');
                    $this->assertSame(2, $editor->getDocument()->cursorLine, 'the caret stayed put');
                    $tui->handleInput("\x1b[<68;45;6M");
                    $this->assertSame(0, $scroll->hScroll, 'shift+wheel-up scrolled back');
                    // Wheel down over the editor scrolls the viewport.
                    $tui->handleInput("\x1b[<65;45;6M");
                    $tui->handleInput("\x1b[<65;45;6M");
                },
                500 => function ($tui): void {
                    // After two wheel-down steps (6 lines) the top visible line is
                    // no longer line 1.
                    $editor = $tui->getById('editor');
                    $rendered = Paint::lines($editor, new RenderContext(69, 20));
                    $topLineNumber = (int) \trim(AnsiUtils::stripAnsiCodes($rendered[0]));
                    $this->assertGreaterThan(1, $topLineNumber, 'wheel scrolled the viewport down');
                    $this->assertSame(2, $editor->getDocument()->cursorLine, 'wheel scroll left the caret behind');
                    // A caret move snaps the detached viewport back to the caret.
                    $tui->handleInput("\x1b[B"); // down arrow -> caret line 3
                    $scroll = EditorState::scroll($editor);
                    $this->await(
                        function () use ($tui, $scroll): bool {
                            $this->frame($tui);

                            return $scroll->vScroll <= 3;
                        },
                        what: 'the viewport to snap back to the caret',
                    );
                    // Wheel over the tree scrolls it (feeds arrow keys): the
                    // selection moves down a notch and comes back up with the next.
                    $tree = $tui->getById('tree');
                    $before = $tree->selectedNode()?->path;
                    $tui->handleInput("\x1b[<65;6;7M");
                    $this->assertNotSame($before, $tree->selectedNode()?->path, 'a wheel-down over the tree moved its selection');
                    $tui->handleInput("\x1b[<64;6;7M");
                    $this->assertSame($before, $tree->selectedNode()?->path, 'and a wheel-up brought it back');
                    // Tree click: focus the tree and open B.php. The menu bar now
                    // lives in the editor pane, so the tree owns the full height:
                    // header row 0, A.php row 1, B.php row 2.
                    $tui->handleInput("\x1b[<0;2;4M"); // 0-based col1 row2 -> B.php
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
        );

        // Mouse-enable escape written on startup, disable on shutdown.
        $this->assertStringContainsString("\x1b[?1006h", $terminal->getOutput());

        $this->addToAssertionCount(1);
    }

    /**
     * A wheel inside a floating surface moves THAT surface and nothing under it.
     *
     * The router returns after each owner handles the notch; take a return out
     * and the notch falls through to the editor pane behind the surface, which
     * scrolls as well. Every existing wheel test either had no buffer behind the
     * surface or never looked at the buffer, so nothing noticed.
     */
    public function testAWheelInsideAFloatingSurfaceNeverScrollsTheEditorBehindIt(): void
    {
        \putenv('PATH=/nonexistent');
        $long = "<?php\n".\implode("\n", \array_map(static fn (int $i): string => "// line {$i}", \range(1, end: 80)))."\n";
        $project = new TempProject(['A.php' => $long]);
        // The notch goes on the box's lowest inner row: the top of a dropdown
        // can sit over the tab strip, where a fall-through scrolls nothing
        // visible, and the point has to be one where it would reach the editor.
        $wheelInside = static function ($tui, ?DropdownRegion $box, WidgetRect $editorRect): void {
            Assert::assertInstanceOf(DropdownRegion::class, $box, 'the surface is open and has a box');
            $row = $box->top + $box->count - 2; // 0-based, just above the bottom border
            $col = \max($box->col + 2, $editorRect->col); // the app menu's box starts over the tree
            Assert::assertTrue($box->contains($row, $col) && $col < ($box->col + $box->width - 1), 'sanity: the notch lands inside the box, off its border');
            Assert::assertTrue($row >= $editorRect->row && $col >= $editorRect->col, 'sanity: and over the editor pane, where a fall-through would scroll it');
            $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $col + 1, $row + 1));
        };

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide) use ($wheelInside): void {
                    $this->frame($tui);
                    $editor = $tui->getById('editor');
                    $scroll = EditorState::scroll($editor);
                    $overlay = Services::resolve($ide, MenuOverlayController::class);

                    // The context menu, opened over the editor.
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 12, $rect->row + 3));
                    $this->frame($tui);
                    $menu = $tui->getById('context-menu');
                    $this->assertTrue($menu->isOpen(), 'the right-click opened the context menu');
                    $before = Probe::get($menu, 'selected');
                    $wheelInside($tui, $overlay->contextMenuDropdown(), $rect);
                    $this->assertNotSame($before, Probe::get($menu, 'selected'), 'the notch moved the menu selection');
                    $this->assertSame(0, $scroll->vScroll, 'and did not scroll the editor behind it');
                    $tui->handleInput("\x1b"); // close it
                    $this->frame($tui);

                    // The application menu's dropdown.
                    $tui->handleInput("\x1b[21~"); // F10 arms the bar
                    $tui->handleInput("\x1b[B"); // ↓ drops File
                    $this->frame($tui);
                    $bar = $tui->getById('menu');
                    $this->assertTrue($bar->isOpen(), 'F10 opened the menu dropdown');
                    $before = Probe::get($bar, 'selected');
                    $wheelInside($tui, $overlay->menuDropdown(), $rect);
                    $this->assertNotSame($before, Probe::get($bar, 'selected'), 'the notch moved the dropdown selection');
                    $this->assertSame(0, $scroll->vScroll, 'and did not scroll the editor behind it');
                    $tui->handleInput("\x1b");
                    $this->frame($tui);

                    // The find-in-files modal, which also floats over the editor.
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    $this->frame($tui);
                    $box = $editor->findModal->lastBox();
                    $this->assertNotNull($box, 'the find modal is open and has a box');
                    $row = $box->startRow + $box->rows - 2;
                    $col = \max($box->col + 2, $rect->col);
                    $this->assertTrue($box->contains($row, $col) && $row >= $rect->row, 'sanity: inside the modal and over the editor pane');
                    $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $col + 1, $row + 1));
                    $this->assertSame(0, $scroll->vScroll, 'a notch inside the find modal did not scroll the editor behind it');
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClicksOutsideAllWidgetsAreIgnored(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $before = $tui->getById('editor')->getDocument()->cursorLine;
                    // Right-click in the 1-col gap between the tree and editor pane
                    // (SGR col 31 = the gap), drag-motion, release, and a wheel over
                    // empty space are all no-ops (no context menu, no caret move).
                    $tui->handleInput("\x1b[<2;31;4M"); // right press in the gap
                    $tui->handleInput("\x1b[<32;45;4M"); // motion
                    $tui->handleInput("\x1b[<0;45;4m"); // release
                    $tui->handleInput("\x1b[<64;1;24M"); // wheel over the status bar
                    $this->assertSame($before, $tui->getById('editor')->getDocument()->cursorLine);
                    $this->assertFalse($tui->getById('context-menu')->isOpen());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    // Editor pane starts at column 31 (tree 30 + gap 1), gutter is 7 (git strip,
    // line number, marker, fold, space), and content begins at screen row 2
    // (menu row 0, tab row 1). So document (line L, col C) sits at SGR column
    // 39+C, SGR row 3+L.
    public function testMouseDragSelectsText(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond line\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;39;3M"); // press at line 0, col 0
                    $tui->handleInput("\x1b[<32;44;3M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;44;3m"); // release
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput(), 'any-motion mouse mode enabled');
    }

    public function testMouseDragAcrossLines(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "abc\ndef\nghi\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;40;3M"); // line 0, col 1
                    $tui->handleInput("\x1b[<32;40;5M"); // drag down to line 2, col 1
                    $tui->handleInput("\x1b[<0;40;5m");
                },
                400 => function ($tui): void {
                    $this->assertSame("bc\ndef\ng", $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDoubleClickSelectsWord(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;41;3M"); // press inside "hello"
                    $tui->handleInput("\x1b[<0;41;3M"); // second press same cell -> word
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseTripleClickSelectsLine(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;43;3M");
                    $tui->handleInput("\x1b[<0;43;3M");
                    $tui->handleInput("\x1b[<0;43;3M"); // third press -> whole line
                },
                400 => function ($tui): void {
                    $this->assertSame('hello world', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseShiftClickExtendsSelection(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;39;3M"); // plain click -> caret at col 0
                    $tui->handleInput("\x1b[<4;44;3M"); // Shift+click at col 5 -> extend
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseAltClickTogglesCaret(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;40;4M"); // Alt+Click (code 8) on line 1 -> add caret
                    $this->assertTrue(EditorState::multiCaret($tui->getById('editor'))->hasMultiple(), 'Alt+Click added a caret');
                    $tui->handleInput("\x1b[<8;40;4M"); // Alt+Click same spot -> remove
                    $this->assertFalse(EditorState::multiCaret($tui->getById('editor'))->hasMultiple(), 'Alt+Click again removed it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMousePlainClickCollapsesCarets(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;40;4M"); // Alt+Click adds a caret
                    $this->assertTrue(EditorState::multiCaret($tui->getById('editor'))->hasMultiple());
                    $tui->handleInput("\x1b[<0;39;3M"); // plain click collapses to one caret
                    $this->assertFalse(EditorState::multiCaret($tui->getById('editor'))->hasMultiple());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseAltShiftDragColumnSelection(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    // Editor content starts at screen col 37 (SGR 38), row 2 (SGR 3);
                    // display col C is SGR 38+C. Alt+Shift (12) drag (44) release.
                    $tui->handleInput("\x1b[<12;40;3M"); // press line 0, display col 1
                    $tui->handleInput("\x1b[<44;42;5M"); // drag to line 2, display col 3
                    $tui->handleInput("\x1b[<0;42;5m"); // release
                    $this->assertTrue(EditorState::multiCaret($tui->getById('editor'))->hasMultiple(), 'rectangle spans 3 lines');
                    $tui->handleInput('X'); // type at every caret
                },
                400 => function ($tui): void {
                    $this->assertSame("aXa\nbX\ncXc\n", $tui->getById('editor')->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClicksUnsavedChangesDialog(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $tui->handleInput('E'); // dirty a.php
                    $tui->handleInput("\x1bw"); // close → unsaved-changes overlay
                    $this->assertTrue(EditorState::pickerOpen($tui->getById('editor')));
                },
                320 => static function ($tui): void {
                    // A click outside the floating box dismisses the modal
                    // overlay (kept, not discarded) — on the tree pane here;
                    // the menu bar row would open a menu instead (it keeps
                    // first claim over every floating modal).
                    $tui->handleInput("\x1b[<0;2;10M");
                },
                420 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertFalse(EditorState::pickerOpen($editor), 'outside click dismissed the dialog');
                    $this->assertStringEndsWith('a.php', (string) $editor->getFilePath(), 'dismiss kept the buffer');
                    $tui->handleInput("\x1bw"); // reopen the overlay
                },
                540 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue(EditorState::pickerOpen($editor));
                    $host = Probe::get($editor, 'overlayHost');
                    $box = Probe::get($host, 'overlayBox');
                    // Hover then click item index 1 ("Discard & Close"): the
                    // box is SCREEN-space now (the picker floats over the
                    // workspace); items start below its header rows — the
                    // title bar, a padding row and the filter field.
                    $row = $box->startRow + $box->headerRows + 1;
                    $col = $box->col + 3; // past the rail and padding column
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 1, $row + 1)); // hover
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // click
                },
                660 => function ($tui) use ($project): void {
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')));
                    $this->assertStringNotContainsString('E', (string) \file_get_contents($project->path('a.php')), 'discard did not save the edit');
                },
            ],
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput());
    }

    /**
     * The status bar's clickable cells answer the pointer: hovering the LSP
     * cell underlines it, and leaving takes the underline back — the router
     * repaints on the change, not on every motion event.
     */
    public function testHoveringAStatusCellUnderlinesIt(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = \count($grid) - 1;
                    $col = \mb_strpos($grid[$row], needle: 'LSP:');
                    $this->assertNotFalse($col, 'the LSP cell is on the status row');

                    $terminal->consumeOutput();
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 2, $row + 1));
                    $this->frame($tui);
                    $this->assertStringContainsString("\x1b[4mLSP:", $terminal->consumeOutput(), 'the hovered cell underlines');

                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", 3, 3)); // back over the editor
                    $this->frame($tui);
                    $this->assertStringNotContainsString("\x1b[4mLSP:", $tui->getById('status')->render(new RenderContext(160, 1))[0], 'and un-underlines on leaving');
                },
            ],
        );
    }

    public function testMouseClickThroughOpenCompletion(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$foo = 1;\n\$foobar = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(1, 0);
                    $editor->showCompletions([
                        new CompletionItem(label: 'alpha', insertText: 'alpha', kind: 'fn', detail: ''),
                    ]);
                    $this->assertTrue($editor->isCompletionOpen());
                },
                320 => static function ($tui, $terminal, Ide $ide): void {
                    $tui->requestRender(force: true);
                    $tui->processRender(); // paint the popup so its box geometry is recorded
                    $editor = $tui->getById('editor');
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($editor);
                    // Hover then click the suggestion itself (through the
                    // router's editor-relative completion branch): the box is
                    // the INNER item box, one row below the frame's top border.
                    $completion = Probe::get($editor, 'completion');
                    $box = Probe::get($completion, 'box');
                    Assert::assertInstanceOf(CompletionBox::class, $box, 'the completion overlay laid out its box');
                    $x = $rect->col + $box->col + 2;
                    $y = $rect->row + $box->startRow;
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x + 1, $y + 1)); // hover tints it
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x + 1, $y + 1)); // click accepts it
                },
                440 => function ($tui, $terminal, Ide $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertFalse($editor->isCompletionOpen(), 'the item click accepted the suggestion');
                    $this->assertStringContainsString('alpha', $editor->getDocument()->getLine(1));

                    // Re-open and click far from the popup: falls through to
                    // caret placement, which closes it.
                    $editor->showCompletions([
                        new CompletionItem(label: 'alpha', insertText: 'alpha', kind: 'fn', detail: ''),
                    ]);
                    $this->assertTrue($editor->isCompletionOpen());
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($editor);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 6, $rect->row + $rect->rows));
                },
                560 => function ($tui): void {
                    $this->assertFalse($tui->getById('editor')->isCompletionOpen(), 'a click off the popup closed completion and placed the caret');
                },
            ],
        );
    }

    /**
     * A modified click in the tree builds a SELECTION, and the router is the
     * only place that knows a modifier was held: the widget is told an intent.
     */
    public function testCtrlAndShiftClickSelectTreeFilesWithoutOpeningThem(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n", 'c.php' => "<?php // c\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, Ide $ide): void {
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('tree'));
                    // Button 0 + 16 is Ctrl held, per the SGR modifier bits;
                    // +4 lands on c.php, two rows below the opened a.php.
                    $tui->handleInput(\sprintf("\x1b[<16;%d;%dM", $rect->col + 3, $rect->row + 4));
                },
                300 => function ($tui): void {
                    $tree = $tui->getById('tree');
                    $this->assertSame(
                        ['a.php', 'c.php'],
                        \array_map(basename(...), $tree->selectedPaths()),
                        'the ctrl-click added the third row to the file the tree opened with',
                    );
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath(), 'and did not open it — the editor is what you compare against');
                },
                400 => static function ($tui, $terminal, Ide $ide): void {
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('tree'));
                    // Button 0 + 4 is Shift: the range from the last click.
                    $tui->handleInput(\sprintf("\x1b[<4;%d;%dM", $rect->col + 3, $rect->row + 3));
                },
                500 => function ($tui): void {
                    $this->assertSame(
                        ['b.php', 'c.php'],
                        \array_map(basename(...), $tui->getById('tree')->selectedPaths()),
                        'the shift-click took the range from the ctrl-clicked row, dropping the one outside it',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
