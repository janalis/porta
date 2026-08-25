<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\necho 2;\necho 3;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));

                    // Control: a click INSIDE the editor (row for line 2) routes
                    // through handleEditorClick and moves the caret.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $rect->row + 3));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 8, $rect->row + 3));
                    $this->assertSame(2, $tui->getById('editor')->getDocument()->getCursorLine(), 'an in-editor click places the caret');

                    // A left press one row below the editor's last row (the status
                    // band) still reaches handleEditorClick, whose guard rejects the
                    // coordinate outside the editor rect — the caret does not move.
                    $row = $rect->row + $rect->rows + 1; // 1-based screen row just below the editor
                    $this->assertLessThanOrEqual(24, $row, 'the click lands on-screen below the editor');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 8, $row));
                },
                300 => function ($tui): void {
                    $this->assertSame(2, $tui->getById('editor')->getDocument()->getCursorLine(), 'the click below the editor did not move the caret');
                },
            ],
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
                200 => static function ($tui, $terminal, $ide): void {
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    // Header is relative row 0, a.php (selected) is row 1; hover b.php at row 2.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 3, $rect->row + 3));
                },
                280 => function ($tui, $terminal, $ide): void {
                    $tree = $tui->getById('tree');
                    $line = $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 24))[2];
                    $this->assertStringContainsString("\x1b[48;5;236m", $line, 'hovered tree row is highlighted');
                    // Move off the tree into the editor → the hover clears.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $editorRect->col + 5, $editorRect->row + 3));
                },
                360 => function ($tui): void {
                    $tree = $tui->getById('tree');
                    $joined = implode('', $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 24)));
                    $this->assertStringNotContainsString("\x1b[48;5;236m", $joined, 'hover cleared after leaving the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClickAndWheel(): void
    {
        putenv('PATH=/nonexistent');
        // Root-level files so the tree lists them directly (dirs sort first,
        // so keeping it flat makes row math simple).
        // Line 3 is long so the horizontal-wheel step has room to scroll.
        $long = "<?php\n".implode("\n", array_map(static fn (int $i) => "// line {$i}".(3 === $i ? str_repeat(' pad', times: 30) : ''), range(1, end: 60)))."\n";
        $project = new TempProject(['A.php' => $long, 'B.php' => "<?php\n// this is B\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                // Editor click: place the cursor on a lower line.
                300 => static function ($tui): void {
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
                    // Horizontal wheel over the editor slides the view without
                    // moving the caret; shift+wheel-up brings it back.
                    $tui->handleInput("\x1b[<67;45;6M");
                    $scroll = EditorState::scroll($editor);
                    $this->assertSame(6, $scroll->hScroll(), 'wheel-right scrolled the editor sideways');
                    $this->assertSame(2, $editor->getDocument()->getCursorLine(), 'the caret stayed put');
                    $tui->handleInput("\x1b[<68;45;6M");
                    $this->assertSame(0, $scroll->hScroll(), 'shift+wheel-up scrolled back');
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
                    $this->assertSame(2, $editor->getDocument()->getCursorLine(), 'wheel scroll left the caret behind');
                    // A caret move snaps the detached viewport back to the caret.
                    $tui->handleInput("\x1b[B"); // down arrow -> caret line 3
                    $scroll = EditorState::scroll($editor);
                    $this->await(
                        function () use ($tui, $scroll): bool {
                            $this->frame($tui);

                            return $scroll->vScroll() <= 3;
                        },
                        what: 'the viewport to snap back to the caret',
                    );
                    // Wheel over the tree scrolls it (feeds arrow keys).
                    $tui->handleInput("\x1b[<65;6;7M");
                    $tui->handleInput("\x1b[<64;6;7M");
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
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // press at line 0, col 0
                    $tui->handleInput("\x1b[<32;43;3M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;43;3m"); // release
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "abc\ndef\nghi\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;39;3M"); // line 0, col 1
                    $tui->handleInput("\x1b[<32;39;5M"); // drag down to line 2, col 1
                    $tui->handleInput("\x1b[<0;39;5m");
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;40;3M"); // press inside "hello"
                    $tui->handleInput("\x1b[<0;40;3M"); // second press same cell -> word
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;42;3M");
                    $tui->handleInput("\x1b[<0;42;3M");
                    $tui->handleInput("\x1b[<0;42;3M"); // third press -> whole line
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // plain click -> caret at col 0
                    $tui->handleInput("\x1b[<4;43;3M"); // Shift+click at col 5 -> extend
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click (code 8) on line 1 -> add caret
                    $this->assertTrue(EditorState::multiCaret($tui->getById('editor'))->hasMultiple(), 'Alt+Click added a caret');
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click same spot -> remove
                    $this->assertFalse(EditorState::multiCaret($tui->getById('editor'))->hasMultiple(), 'Alt+Click again removed it');
                },
            ],
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
                    $this->assertTrue(EditorState::multiCaret($tui->getById('editor'))->hasMultiple());
                    $tui->handleInput("\x1b[<0;38;3M"); // plain click collapses to one caret
                    $this->assertFalse(EditorState::multiCaret($tui->getById('editor'))->hasMultiple());
                },
            ],
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
        putenv('PATH=/nonexistent');
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
                    // workspace); the frame's title bar is row startRow, a
                    // padding row follows, items start at startRow + 2.
                    $row = $box->startRow + 3;
                    $col = $box->col + 3; // past the rail and padding column
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 1, $row + 1)); // hover
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // click
                },
                660 => function ($tui) use ($project): void {
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')));
                    $this->assertStringNotContainsString('E', (string) file_get_contents($project->path('a.php')), 'discard did not save the edit');
                },
            ],
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput());
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
                        new \Porta\Editor\CompletionItem(label: 'alpha', insertText: 'alpha', kind: 'fn', detail: ''),
                    ]);
                    $this->assertTrue($editor->isCompletionOpen());
                },
                320 => static function ($tui, $terminal, $ide): void {
                    $tui->requestRender();
                    $tui->tick(); // paint the popup so its box geometry is recorded
                    $editor = $tui->getById('editor');
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($editor);
                    // Hover then click the suggestion itself (through the
                    // router's editor-relative completion branch): the box is
                    // the INNER item box, one row below the frame's top border.
                    $completion = Probe::get($editor, 'completion');
                    $box = Probe::get($completion, 'box');
                    \assert($box instanceof \Porta\Editor\CompletionBox, description: 'the completion overlay laid out its box');
                    $x = $rect->col + $box->col + 2;
                    $y = $rect->row + $box->startRow;
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x + 1, $y + 1)); // hover tints it
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x + 1, $y + 1)); // click accepts it
                },
                440 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertFalse($editor->isCompletionOpen(), 'the item click accepted the suggestion');
                    $this->assertStringContainsString('alpha', $editor->getDocument()->getLine(1));

                    // Re-open and click far from the popup: falls through to
                    // caret placement, which closes it.
                    $editor->showCompletions([
                        new \Porta\Editor\CompletionItem(label: 'alpha', insertText: 'alpha', kind: 'fn', detail: ''),
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
}
