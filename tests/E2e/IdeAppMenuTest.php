<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\LineNumberMode;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The application menu bar and the command palette: dropdowns,
 * hover, greying rules, palette-run actions.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeAppMenuTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testAppMenuDropdownCoversBreadcrumb(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => static function ($tui): void {
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // into handle() → breadcrumb bar shows
                    }
                },
                280 => function ($tui): void {
                    $crumb = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('handle', $crumb, 'breadcrumb is visible');
                    // Open View: its dropdown reaches down over the breadcrumb
                    // row's columns. The column is read from the rendered bar
                    // rather than hard-coded — the menu set is not fixed (Git's
                    // group is absent outside a repository, as here).
                    $bar = AnsiUtils::stripAnsiCodes($tui->getById('menu')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $view = mb_strpos($bar, needle: 'View');
                    self::assertIsInt($view, 'the View menu is on the bar');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;1M", $view + 2));
                },
                360 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $row = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('Show Problems', $row, 'the dropdown composites over the breadcrumb row (breadcrumb no longer in front)');
                    // Switch to File (left edge): with the full-width menu row its
                    // dropdown now floats over the TREE columns instead.
                    $tui->handleInput("\x1b[<0;3;1M");
                },
                440 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    // Tree row 0 carries the frame's top border; the first item
                    // row sits one below it.
                    $treeTop = AnsiUtils::stripAnsiCodes($tui->getById('tree')->render(new \Symfony\Component\Tui\Render\RenderContext(30, 20))[1]);
                    $this->assertStringContainsString('Save File', $treeTop, "File's dropdown composites over the tree");
                },
            ],
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
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[<0;9;1M"); // open the Edit menu (title cols 7-12)
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
                360 => static function ($tui) use ($project): void {
                    // Open a file so the document actions re-enable, then reopen Edit.
                    $tui->getById('editor')->openFile($project->path('a.php'), "<?php\necho 1;\n");
                    $tui->handleInput("\x1b[<0;9;1M");
                },
                440 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $undo = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $this->assertStringNotContainsString("\x1b[38;5;240m", $undo, 'Undo enabled once a file is open');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testApplicationMenuOpenRunAndDismiss(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
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
                200 => static function ($tui): void {
                    $tui->tick();
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('X'); // dirty: "X<?php"
                    // The menu bar is the full-width top row: File title at
                    // cols 1-6, Edit at 7-12. Click "Edit" (SGR row 1).
                    $tui->handleInput("\x1b[<0;9;1M");
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'title click opened the menu');
                    $this->assertSame('menu', $tui->getFocus()?->getId());
                    // The dropdown anchors under Edit (col 7); its frame's top
                    // border sits at screen row 1, Undo (item 0) below it → SGR row 3.
                    $tui->handleInput("\x1b[<0;9;3M"); // click Undo -> runs it + closes
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'item click ran and closed');
                    $editor = $tui->getById('editor');
                    $this->assertSame('<?php', $editor->getDocument()->getLine(0), 'clicking Undo undid the edit');
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;1M"); // reopen File (title cols 1-6)
                },
                500 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'File reopened');
                    // Clicking the SAME (open) title again toggles the dropdown closed.
                    $tui->handleInput("\x1b[<0;3;1M"); // File title cols 1-6
                },
                600 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 're-clicking the open title closed it');
                    // Open File, then click a DIFFERENT title (Edit) → switches, stays open.
                    $tui->handleInput("\x1b[<0;3;1M"); // open File (index 0)
                    $tui->handleInput("\x1b[<0;9;1M"); // click Edit (index 1)
                },
                700 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen(), 'clicking another title kept a menu open');
                    $this->assertSame(1, $menu->openIndex(), 'switched to Edit (index 1)');
                    // A click on the frame's border ring (the top border row) is
                    // swallowed: a 1-cell mis-click neither runs nor dismisses.
                    $tui->handleInput("\x1b[<0;9;2M");
                    $this->assertTrue($menu->isOpen(), 'a border click did not dismiss the menu');
                    $tui->handleInput("\x1b[<0;100;18M"); // click far below the dropdown -> dismiss
                },
                800 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'outside click dismissed the menu');
                },
            ],
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
                200 => static function ($tui): void {
                    $tui->tick();
                    // Closed bar: hover the File title (cols 1-6, SGR move code 35).
                    $tui->handleInput("\x1b[<35;3;1M");
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
                    $tui->handleInput("\x1b[<0;9;1M"); // click the Edit title (cols 7-12) to open it
                },
                440 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    $this->assertSame(1, $menu->openIndex(), 'Edit is open');
                    // Hover Redo (item 1) in the dropdown: bar row 0, the frame's
                    // top border at SGR row 2 → items at SGR rows 3,4.
                    $tui->handleInput("\x1b[<35;9;4M");
                },
                520 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $segments = $menu->dropdownSegments($menu->panelWidth());
                    $this->assertStringContainsString("\x1b[48;5;31m", $segments[1]['segment'], 'hovering Redo highlighted it');
                    $this->assertStringNotContainsString("\x1b[48;5;31m", $segments[0]['segment'], 'Undo is no longer highlighted');
                    // While open, hover the sibling File title → switches the dropdown.
                    $tui->handleInput("\x1b[<35;3;1M");
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
                    $this->assertNotNull($this->rowOf($before, 'zmarker'), 'tree lists zmarker.php before the menu opens');
                    // The File dropdown floats over the TREE columns now, so the
                    // row-stability marker must live outside its footprint: use
                    // the editor's text (col ~38, untouched by the dropdown).
                    $markerRow = $this->rowOf($before, 'echo 1;');
                    $this->assertNotNull($markerRow, 'editor content visible before the menu opens');

                    $tui->handleInput("\x1b[21~"); // F10 opens the menu

                    $after = $this->screenGrid($tui, $terminal);
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'F10 opened the menu');
                    // Regression: the editor line stays on the SAME screen row (before
                    // the fix it was reflowed downward) and no rows were inserted.
                    $this->assertSame($markerRow, $this->rowOf($after, 'echo 1;'), 'content did not shift down');
                    $this->assertSameSize($before, $after, 'no extra rows inserted');

                    // ...and the dropdown really is drawn, floating over the pane.
                    $menu = $tui->getById('menu');
                    $segment = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $label = explode(' ', trim(AnsiUtils::stripAnsiCodes($segment)))[0];
                    $this->assertNotSame('', $label);
                    $this->assertStringContainsString($label, implode("\n", $after), 'dropdown composited over the editor pane');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testOpeningMenuBeforeFirstRenderIsSafe(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = Services::resolve($ide, Tui::class);

        // No frame has rendered yet, so every widget rect is null. Opening the
        // menu must not blow up placing the (impossible-to-place) overlay.
        $tui->handleInput("\x1b[21~"); // F10

        $this->assertTrue($tui->getById('menu')->isOpen(), 'menu opened without a prior render');
        $this->assertSame('', $terminal->consumeOutput(), 'nothing was drawn');
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
                    $this->assertTrue($tui->getById('editor')->isSearchOverlayOpen(), 'the command palette opens the centered picker');
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
                    foreach (str_split('cycle line') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                450 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Cycle Line Numbers');
                    $this->assertStringContainsString('Cycle Line Numbers', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run the cycle: absolute → relative
                },
                600 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertSame(LineNumberMode::Relative, EditorState::display($tui->getById('editor'))->lineNumbers());
                    $this->assertStringContainsString('Line numbers relative', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Persisted into .porta.json.
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame('relative', $config['settings']['lineNumbers']);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
