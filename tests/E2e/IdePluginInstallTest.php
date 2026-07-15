<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * Settings → Plugins: installing (local path copy, git clone) with live
 * activation, and two-press removal. Drives the real settings view with
 * synthetic keys: Tab between nav and form, arrows, inline text editing.
 */
final class IdePluginInstallTest extends TestCase
{
    use RunsIde;

    private string $originalPath;
    private ?string $portaHome = null;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        $this->portaHome = getenv('PORTA_HOME') ?: null;
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv(null !== $this->portaHome ? 'PORTA_HOME='.$this->portaHome : 'PORTA_HOME');
    }

    /** A plugin source registering one flashing command. */
    private function pluginSource(string $name): string
    {
        return <<<PHP
            <?php
            use Porta\\Plugin\\PluginApi;
            use Porta\\Plugin\\PortaPlugin;

            return new class implements PortaPlugin {
                public function name(): string { return '{$name}'; }
                public function activate(PluginApi \$api): void
                {
                    \$api->registerCommand('hi', 'Hi from {$name}', static function () use (\$api): void {
                        \$api->flash('{$name} says hi');
                    }, ['ctrl+alt+w']);
                    \$api->addMenuItem('hi');
                }
            };
            PHP;
    }

    /** Open settings and select the Plugins section, leaving focus on the form. */
    private function openPluginsSection($tui, $ide): void
    {
        new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
        $tui->handleInput("\t"); // form → nav
        $tui->handleInput("\x1b[B"); // Keybindings
        $tui->handleInput("\x1b[B"); // Plugins
        $tui->handleInput("\t"); // nav → form (scope row selected)
    }

    /** Type into the form's inline text editor. */
    private function type($tui, string $text): void
    {
        foreach (str_split($text) as $char) {
            $tui->handleInput($char);
        }
    }

    public function testLocalPathInstallActivatesLive(): void
    {
        $project = new TempProject([
            'plugin-src/installee/plugin.php' => $this->pluginSource('installee'),
            'plugin-src/broken/plugin.php' => "<?php\nreturn 42;\n",
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->openPluginsSection($tui, $ide);
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Install into  < This project >', $screen);
                    $this->assertStringContainsString('git URL or local path', $screen, 'the empty install field shows its hint');

                    $tui->handleInput("\x1b[B"); // → the install row
                    $tui->handleInput("\r"); // start inline editing
                    $this->type($tui, $project->path('plugin-src/installee'));
                    $tui->handleInput("\r"); // commit → copy + live activation
                    $tui->tick();

                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin installed — default keybindings apply on restart', $screen);
                    $this->assertFileExists($project->path('.porta/plugins/installee/plugin.php'));
                    $this->assertStringContainsString('installee (project)  [Remove]', $screen, 'the new plugin lists with a Remove button');

                    // Live activation: the command is in the registry and the palette.
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $this->assertContains('Hi from installee', array_column($commands->paletteCommands(), 'label'));
                    $commands->run('installee:hi');
                    $tui->tick();
                    $this->assertStringContainsString('installee says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // A plugin that installs but fails to load flashes the loader's error.
                    $tui->handleInput("\r"); // the install row is still selected
                    $this->type($tui, $project->path('plugin-src/broken'));
                    $tui->handleInput("\r");
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "broken" failed to load: plugin.php must return a PortaPlugin instance', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRemovalTakesTwoPressesAndDeactivatesLive(): void
    {
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->pluginSource('demo'),
            'plugin-src/demo/plugin.php' => $this->pluginSource('demo'),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($project): void {
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $this->assertContains('Hi from demo', array_column($commands->paletteCommands(), 'label'), 'active before removal');

                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\x1b[B"); // demo's Remove row
                    $tui->handleInput("\r"); // arm
                    $tui->tick();
                    $this->assertStringContainsString('Press Enter again to remove "demo"', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertDirectoryExists($project->path('.porta/plugins/demo'));

                    $tui->handleInput("\r"); // confirm
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "demo" removed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertDirectoryDoesNotExist($project->path('.porta/plugins/demo'));

                    // The loaded code cannot be unloaded: the command still runs.
                    // Live deactivation: palette entry, key dispatch and menu are gone.
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $this->assertNotContains('Hi from demo', array_column($commands->paletteCommands(), 'label'), 'palette entry died');
                    $this->assertNotContains('demo:hi', $commands->keyBoundIds(), 'key dispatch died');
                    $terminal->clearOutput();
                    $commands->run('demo:hi'); // unknown id → safe no-op
                    $tui->tick();
                    $this->assertStringNotContainsString('demo says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // The same name reinstalls live from a fresh source dir.
                    $tui->handleInput("\x1b[A"); // clamp to the top (scope row)…
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\x1b[B"); // …then down to the install row
                    $tui->handleInput("\r");
                    $this->type($tui, $project->path('plugin-src/demo'));
                    $tui->handleInput("\r");
                    $tui->tick();
                    $this->assertContains('Hi from demo', array_column($commands->paletteCommands(), 'label'), 'reinstalled live');
                    $commands->run('demo:hi');
                    $tui->tick();
                    $this->assertStringContainsString('demo says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'and its boot-time key/handler works again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitUrlInstallClonesAndActivates(): void
    {
        putenv('PATH='.$this->originalPath); // the installer needs a real git
        if (null === new ExecutableFinder()->find('git')) {
            self::markTestSkipped('git binary not available');
        }

        // The clone name derives from the URL basename, so host the repo in a
        // subdirectory with a valid plugin name.
        $host = new TempProject(['cloned/plugin.php' => $this->pluginSource('cloned')]);
        $dir = escapeshellarg($host->path('cloned'));
        exec("git -C {$dir} init -q 2>/dev/null && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init 2>/dev/null");
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($host): void {
                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\r");
                    $this->type($tui, 'file://'.$host->path('cloned'));
                    $tui->handleInput("\r"); // commit → async clone
                },
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugin installed — default keybindings apply on restart'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin installed — default keybindings apply on restart', $screen);
                    $this->assertFileExists($project->path('.porta/plugins/cloned/plugin.php'));
                    $this->assertStringContainsString('cloned (project)  [Remove]', $screen, 'the async refresh re-listed the section');

                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $commands->run('cloned:hi');
                    $tui->tick();
                    $this->assertStringContainsString('cloned says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGlobalScopeInstallLandsInPortaHome(): void
    {
        $home = new TempProject();
        putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            'plugin-src/worldwide/plugin.php' => $this->pluginSource('worldwide'),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($project, $home): void {
                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\r"); // cycle the scope row: project → global
                    $tui->tick();
                    $this->assertStringContainsString('Install into  < Global (all projects) >', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\r");
                    $this->type($tui, $project->path('plugin-src/worldwide'));
                    $tui->handleInput("\r");
                    $tui->tick();

                    $this->assertFileExists($home->dir.'/.porta/plugins/worldwide/plugin.php');
                    $this->assertDirectoryDoesNotExist($project->path('.porta/plugins/worldwide'));
                    $this->assertStringContainsString('worldwide (global)  [Remove]', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
