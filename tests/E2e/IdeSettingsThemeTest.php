<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\InlayHintMode;
use Porta\Editor\LineNumberMode;
use Porta\Ide;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FindModalReads;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\ViewWindowCells;
use Porta\Tests\Support\Widgets;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The settings view and live-applied settings: theme recolor,
 * cursor shape, sidebar resize, startup application.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeSettingsThemeTest extends TestCase
{
    use ClearsPortaLsp;
    use FindModalReads;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;
    use ViewWindowCells;

    private ?string $portaHomeToRestore = null;

    protected function tearDown(): void
    {
        if (null !== $this->portaHomeToRestore) {
            \putenv('PORTA_HOME='.$this->portaHomeToRestore);
            $this->portaHomeToRestore = null;
        }
    }

    public function testSettingsAppliedAtStartup(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['diagnosticMessages' => 'none', 'inlayHints' => 'tooltip', 'lineNumbers' => 'relative']], \JSON_THROW_ON_ERROR),
            'A.php' => "<?php\n\$x = 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui): void {
                    $editor = Widgets::editor($tui);
                    $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers, 'setting applied from file');
                    $this->assertSame(InlayHintMode::Tooltip, $editor->display->inlayHints, 'the hint mode applied from file');
                    // Diagnostic messages off: even with a diagnostic, no inline text.
                    $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0]]]);
                    $rendered = Paint::lines($editor, new RenderContext(80, 5));
                    $this->assertStringNotContainsString('boom', AnsiUtils::stripAnsiCodes($rendered[1]));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSettingsViewSidebarNavFormAndLanguages(): void
    {
        // Plugin-list navigation is index-based, so keep the suite-wide
        // `tempest` highlighting fixture (installed via PORTA_HOME) out of it.
        // tearDown puts it back — paratest reuses the process.
        $portaHome = \getenv('PORTA_HOME');
        $this->portaHomeToRestore = false === $portaHome ? null : $portaHome;
        \putenv('PORTA_HOME=/nonexistent');
        \putenv('PATH=/nonexistent'); // no git/LSP subprocesses needed
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui, $terminal, Ide $ide): void {
                    // Open via the command registry (the ide_settings closure),
                    // then the mouse steps below exercise the routing.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // A bare tick() is not a frame, and every assertion below
                    // reads the ACCUMULATED output — so without a real paint
                    // they were reading whatever the boot happened to leave.
                    $this->awaitPainted($tui, $terminal, 'Auto-pairs');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('settings-form', $tui->getFocus()?->getId(), 'settings opened focused on the General form');
                    $this->assertStringContainsString('General', $screen, 'the nav sidebar lists sections');
                    $this->assertStringContainsString('Languages', $screen);
                    $this->assertStringContainsString('Auto-pairs', $screen, 'General content shown');
                    // Toggle Auto-pairs in the content pane. Region row 8:
                    // the page title (0) and the rule under it (1), the
                    // boxed "Save changes to" chooser that leads every page
                    // that writes a file (2-4), the Editing heading between
                    // its blank rows (5-7), then this checkbox.
                    //
                    // Once: a checkbox toggles on the click that selects it,
                    // as a label does — every other kind of row waits for a
                    // second.
                    $tui->handleInput(self::windowSgr($ide, 0, 8, 39));
                    $tui->handleInput(self::windowSgr($ide, 0, 8, 39, release: true));
                    // Hover the nav sidebar (a tint): Interface. With a rule
                    // under every row the nav overflows, but nothing is above
                    // yet, so no ▲ row: General is row 0, its rule row 1.
                    $tui->handleInput(self::windowSgr($ide, 35, 2, 4));
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // frame(), not tick(): the click below is hit-tested against
                    // the rects the LAST RENDER produced.
                    $this->frame($tui);
                    // Click the 'Keybindings' entry in the nav: region row 6,
                    // after General, Interface and Highlighting, each with the
                    // rule under it.
                    $tui->handleInput(self::windowSgr($ide, 0, 6, 4));
                    $tui->handleInput(self::windowSgr($ide, 0, 6, 4, release: true));
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Ctrl+F to filter');
                    $this->assertSame('settings-nav', $tui->getFocus()?->getId(), 'clicking the nav focuses it');
                    // Hover + wheel + click the keybindings list (content pane), a nav miss too.
                    $tui->handleInput(self::windowSgr($ide, 35, 3, 39)); // hover a list row (nav clears)
                    $tui->handleInput(self::windowSgr($ide, 65, 3, 39)); // wheel down over the list
                    $tui->handleInput(self::windowSgr($ide, 0, 3, 39)); // click a list row
                    $tui->handleInput(self::windowSgr($ide, 0, 3, 39, release: true));
                },
                500 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    // Select PHP in the nav: region row 14, after General,
                    // Interface, Highlighting, Keybindings, Command line, the
                    // Plugins folder and the Languages folder, each with its
                    // rule → the content shows its indent rows.
                    $tui->handleInput(self::windowSgr($ide, 0, 14, 4));
                    $tui->handleInput(self::windowSgr($ide, 0, 14, 4, release: true));
                },
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Indent style');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('LSP server', $screen, 'and its LSP server row');
                    // Park the selection on a row we are not about to actuate.
                    // Switching pages CLAMPS the selection rather than resetting
                    // it, so which row a language page opens on depends on where
                    // the previous page left it — and "click twice" then means
                    // one step or two. Region row 12 is Indent size's label: a
                    // select, and a single click on its LABEL only selects (a
                    // click on its box would drop its list).
                    $tui->handleInput(self::windowSgr($ide, 0, 12, 39));
                    $tui->handleInput(self::windowSgr($ide, 0, 12, 39, release: true));
                    // Now step indent style (auto → spaces). Region row 9: the
                    // page title and its rule (0-1), the boxed "Save
                    // changes to" chooser that leads every page writing a file
                    // (2-4), the Indentation heading between its blank rows
                    // (5-7), then this box (8-10), its label on its middle row.
                    // Two clicks on the label: select, then the forward step.
                    $tui->handleInput(self::windowSgr($ide, 0, 9, 39));
                    $tui->handleInput(self::windowSgr($ide, 0, 9, 39, release: true));
                    $tui->handleInput(self::windowSgr($ide, 0, 9, 39));
                    $tui->handleInput(self::windowSgr($ide, 0, 9, 39, release: true));
                    // Edit the LSP server row: after indent size (11-13) and the
                    // Language server heading between its blank rows (14-16),
                    // the label row of its box (17-19), region row 18. TWO
                    // clicks: the first selects, and only a
                    // click on the already-selected row opens the editor — a
                    // single click no longer mutates whatever it lands on.
                    $tui->handleInput(self::windowSgr($ide, 0, 18, 39));
                    $tui->handleInput(self::windowSgr($ide, 0, 18, 39, release: true));
                    $tui->handleInput(self::windowSgr($ide, 0, 18, 39));
                    $tui->handleInput(self::windowSgr($ide, 0, 18, 39, release: true));
                    foreach (\str_split('my-ls --stdio') as $char) {
                        $tui->handleInput($char);
                    }
                    $tui->handleInput("\r"); // commit → persists + reconfigures the LSP pool live
                    $tui->handleInput("\x1b[21~"); // F10 arms the app menu OVER the settings view
                    $tui->handleInput("\x1b[B"); // ↓ drops File
                },
                700 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Settings…');
                    $tui->handleInput("\x1b[<35;5;3M"); // hover a dropdown row: the menu rides along
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu bar');
                    $this->assertStringContainsString('Save File', $screen);
                },
            ],
            // The rows above count the boxed controls: a roomy screen, tall
            // enough that the window (80% of it) holds what the full-screen
            // view did on 30.
            rows: 40,
        );

        $this->assertFalse(IdeConfig::load($project->dir)->settings['autoPairs'], 'the General toggle persisted');
        $this->assertSame('spaces', IdeConfig::load($project->dir)->languages['php']['indentStyle'], 'the PHP indent change persisted');
        $this->assertSame('my-ls --stdio', IdeConfig::load($project->dir)->lspServers['php'], 'the PHP LSP server persisted');
    }

    public function testThemeSwitchLiveRecolorsChromeAndSyntax(): void
    {
        \putenv('PATH=/nonexistent'); // no git/LSP subprocesses needed
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // Dark theme baseline: the `return` keyword is the dark KEYWORD color.
                    $this->awaitPaintedRaw($tui, $terminal, "\x1b[38;5;176m");
                    $terminal->clearOutput(); // capture only the frames rendered after the switch
                },
                500 => static function ($tui, $terminal, Ide $ide): void {
                    // Change the theme setting live, as the settings form would.
                    Services::resolve($ide, SettingsController::class)
                        ->changeSetting('theme', 'light');
                    $tui->tick();
                },
                700 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // The buffer re-highlighted: light KEYWORD color in, dark gone.
                    // The POSITIVE is the barrier — without a frame the cleared
                    // buffer is empty and "dark gone" passes for the wrong reason.
                    $this->awaitPaintedRaw($tui, $terminal, "\x1b[38;5;91m");
                    // A frame painted before the async re-highlight landed still
                    // carries the old keyword colour; judge the settled frame.
                    $terminal->clearOutput();
                    $this->frame($tui);
                    $out = $terminal->getOutput();
                    $this->assertStringNotContainsString("\x1b[38;5;176m", $out, 'no dark syntax remains');
                    // Chrome recolored: light header bar in, dark gone.
                    $this->assertStringContainsString("\x1b[48;5;253m", $out, 'light header bar present');
                    $this->assertStringNotContainsString("\x1b[48;5;237m", $out, 'no dark header bar remains');

                    // Second live switch: the accessibility theme.
                    $terminal->clearOutput();
                    Services::resolve($ide, SettingsController::class)
                        ->changeSetting('theme', 'high-contrast');
                    $tui->tick();
                },
                900 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPaintedRaw($tui, $terminal, "\x1b[38;5;213m");
                    $out = $terminal->getOutput(); // only post-high-contrast frames
                    $this->assertStringNotContainsString("\x1b[38;5;91m", $out, 'no light syntax remains');
                    $this->assertStringContainsString("\x1b[48;5;236m", $out, 'high-contrast header bar present');
                    $this->assertStringNotContainsString("\x1b[48;5;253m", $out, 'no light header bar remains');
                },
            ],
        );

        $this->assertSame('high-contrast', IdeConfig::load($project->dir)->settings['theme'], 'the theme persisted');
    }

    /**
     * The nav's selection reaches into the row over it: in the window, the
     * window's own blank padding takes General's upper half, as the
     * workspace rule takes it when the view is not in a window.
     */
    public function testTheNavsFirstRowBleedsIntoTheWindowsPadding(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => static function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('settings-nav'));
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertStringContainsString('General', \mb_substr($grid[$rect->row], $rect->col, $rect->columns), 'General on the nav\'s first row');
                    $this->assertStringContainsString('▄', \mb_substr($grid[$rect->row - 1], $rect->col, $rect->columns), 'its upper half in the padding over it');
                    $this->assertStringNotContainsString('▄', \mb_substr($grid[$rect->row - 1], $rect->col + $rect->columns), 'and nowhere past the nav');
                },
            ],
            rows: 40,
        );
    }

    public function testSettingsSidebarResizesAndPersists(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        // A seeded percent is a share of the WINDOW's inner width: on a
        // 160-column terminal the window is 128 wide, 124 inside, so 25% is 31.
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents($project->path('.porta/layout.json'), \json_encode(['settingsSidebarWidthPercent' => 25]));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => static function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $this->assertSame(31, Widgets::byId($tui, 'settings-nav', SettingsNavWidget::class)->width(), 'the seeded percent applied to the window');
                    // Drag the separator (region column 31, after the 31-wide nav) in to column 21.
                    $tui->handleInput(self::windowSgr($ide, 0, 3, 31)); // arm on the separator
                    $tui->handleInput(self::windowSgr($ide, 32, 3, 21)); // drag left
                    $tui->handleInput(self::windowSgr($ide, 0, 3, 21, release: true));
                },
                900 => function (Tui $tui) use ($project): void {
                    $this->assertSame(21, Widgets::byId($tui, 'settings-nav', SettingsNavWidget::class)->width(), 'the drag resized the sidebar');
                    $layout = \json_decode((string) \file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(17, $layout['viewSidebarWidthPercent']['settings'], 'saved as a share of the window (round(21/124))');
                },
            ],
        );
    }

    public function testCursorShapeSettingSwitchesTheMarkerLiveAndTerminalPanelPlacesTheCursor(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // ScreenWriter converts the zero-width marker into a hardware
                    // cursor position plus a DECSCUSR shape: 5 = bar (default).
                    $this->awaitPaintedRaw($tui, $terminal, "\x1b[5 q");

                    Services::resolve($ide, SettingsController::class)
                        ->changeSetting('cursorShape', 'block');
                    $terminal->clearOutput();
                    $tui->tick();
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPaintedRaw($tui, $terminal, "\x1b[1 q");
                    $out = $terminal->getOutput();
                    $this->assertStringNotContainsString("\x1b[5 q", $out, 'no stale bar shape survives the cache flush');
                },
                600 => function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_terminal');
                    $terminal->clearOutput();
                    $tui->tick();
                    $rows = Paint::lines($tui->getById('terminal'), new RenderContext(160, 12));
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'the focused terminal panel places the hardware cursor');
                },
            ],
        );

        $this->assertSame('block', IdeConfig::load($project->dir)->settings['cursorShape'], 'the shape persisted');
    }
}
