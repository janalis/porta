<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\LineNumberMode;
use Porta\Ide;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FindModalReads;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
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
            '.porta.json' => \json_encode(['settings' => ['inlineDiagnostics' => false, 'lineNumbers' => 'relative']]),
            'A.php' => "<?php\n\$x = 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers, 'setting applied from file');
                    // Inline diagnostics disabled: even with a diagnostic, no inline text.
                    $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0]]]);
                    $rendered = $editor->render(new RenderContext(80, 5));
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
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // A bare tick() is not a frame, and every assertion below
                    // reads the ACCUMULATED output — so without a real paint
                    // they were reading whatever the boot happened to leave.
                    $this->awaitPainted($tui, $terminal, 'Inline diagnostics');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('settings-form', $tui->getFocus()?->getId(), 'settings opened focused on the General form');
                    $this->assertStringContainsString('General', $screen, 'the nav sidebar lists sections');
                    $this->assertStringContainsString('Languages', $screen);
                    $this->assertStringContainsString('Inline diagnostics', $screen, 'General content shown');
                    // Toggle Inline diagnostics in the content pane. Screen row
                    // 4: a title row, then the "Save changes to" chooser that
                    // leads every page that writes a file, then this one.
                    //
                    // Twice, because a click on a row that is not already
                    // selected only selects it — the first click used to
                    // actuate only because Inline diagnostics happened to be
                    // the row the page opens on, and it no longer is.
                    $tui->handleInput("\x1b[<0;40;4M");
                    $tui->handleInput("\x1b[<0;40;4m");
                    $tui->handleInput("\x1b[<0;40;4M");
                    $tui->handleInput("\x1b[<0;40;4m");
                    // Hover the nav sidebar (a tint).
                    $tui->handleInput("\x1b[<35;5;3M");
                },
                300 => function (Tui $tui): void {
                    // frame(), not tick(): the click below is hit-tested against
                    // the rects the LAST RENDER produced.
                    $this->frame($tui);
                    // Click the 'Keybindings' entry in the nav (screen row 5, after
                    // General + Interface + Highlighting).
                    $tui->handleInput("\x1b[<0;5;5M");
                    $tui->handleInput("\x1b[<0;5;5m");
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'actions');
                    $this->assertSame('settings-nav', $tui->getFocus()?->getId(), 'clicking the nav focuses it');
                    // Hover + wheel + click the keybindings list (content pane), a nav miss too.
                    $tui->handleInput("\x1b[<35;40;5M"); // hover a list row (nav clears)
                    $tui->handleInput("\x1b[<65;40;5M"); // wheel down over the list
                    $tui->handleInput("\x1b[<0;40;5M"); // click a list row
                    $tui->handleInput("\x1b[<0;40;5m");
                },
                500 => function (Tui $tui): void {
                    $this->frame($tui);
                    // Select PHP in the nav (screen row 8, after Interface +
                    // Highlighting + Plugins and the Languages header) → the
                    // content shows its indent rows. Two rows higher than it
                    // used to be: the Version control category and its Git
                    // sub-item went with the git plugin.
                    $tui->handleInput("\x1b[<0;5;8M");
                    $tui->handleInput("\x1b[<0;5;8m");
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Indent style');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('LSP server', $screen, 'and its LSP server row');
                    // Park the selection on a row we are not about to actuate.
                    // Switching pages CLAMPS the selection rather than resetting
                    // it, so which row a language page opens on depends on where
                    // the previous page left it — and "click twice" then means
                    // one step or two. Screen row 6 is Run/debug command, a Text
                    // row that a single click only selects.
                    $tui->handleInput("\x1b[<0;40;6M");
                    $tui->handleInput("\x1b[<0;40;6m");
                    // Now actuate indent style (auto → spaces). Screen row 4:
                    // the page title, then the "Save changes to" chooser that
                    // leads every page writing a file, then this. Two clicks:
                    // select, then actuate.
                    $tui->handleInput("\x1b[<0;40;4M");
                    $tui->handleInput("\x1b[<0;40;4m");
                    $tui->handleInput("\x1b[<0;40;4M");
                    $tui->handleInput("\x1b[<0;40;4m");
                    // Edit the LSP server row, one lower than it was for the
                    // same reason. TWO clicks: the first selects, and only a
                    // click on the already-selected row opens the editor — a
                    // single click no longer mutates whatever it lands on.
                    $tui->handleInput("\x1b[<0;40;7M");
                    $tui->handleInput("\x1b[<0;40;7m");
                    $tui->handleInput("\x1b[<0;40;7M");
                    $tui->handleInput("\x1b[<0;40;7m");
                    foreach (\str_split('my-ls --stdio') as $char) {
                        $tui->handleInput($char);
                    }
                    $tui->handleInput("\r"); // commit → persists + reconfigures the LSP pool live
                    $tui->handleInput("\x1b[21~"); // F10 opens the app menu OVER the settings view
                },
                700 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Settings…');
                    $tui->handleInput("\x1b[<35;5;3M"); // hover a dropdown row: the menu rides along
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu bar');
                    $this->assertStringContainsString('Save File', $screen);
                },
            ],
        );

        $this->assertFalse(IdeConfig::load($project->dir)->settings['inlineDiagnostics'], 'the General toggle persisted');
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
                    $out = $terminal->getOutput(); // only post-switch frames (cleared at step 300)
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

    public function testSettingsSidebarResizesAndPersists(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        // A seeded percent must be applied at startup (25% of 160 cols = 40).
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
                600 => function (Tui $tui): void {
                    $this->frame($tui);
                    $this->assertSame(40, $tui->getById('settings-nav')->width(), 'the seeded percent applied at startup');
                    // Drag the settings separator (SGR col 41 with a 40-wide nav) in to col 31.
                    $tui->handleInput("\x1b[<0;41;6M"); // arm on the separator
                    $tui->handleInput("\x1b[<32;31;6M"); // drag left
                    $tui->handleInput("\x1b[<0;31;6m"); // release
                },
                900 => function ($tui) use ($project): void {
                    $this->assertSame(30, $tui->getById('settings-nav')->width(), 'the drag resized the sidebar');
                    $layout = \json_decode((string) \file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(19, $layout['viewSidebarWidthPercent']['settings'], 'saved as a percentage (round(30/160))');
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
                    $rows = $tui->getById('terminal')->render(new RenderContext(160, 12));
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'the focused terminal panel places the hardware cursor');
                },
            ],
        );

        $this->assertSame('block', IdeConfig::load($project->dir)->settings['cursorShape'], 'the shape persisted');
    }
}
