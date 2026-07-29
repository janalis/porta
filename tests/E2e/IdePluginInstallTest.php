<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Settings → Plugins: installing (local path copy, git clone) with live
 * activation, and two-press removal. Drives the real settings view with
 * synthetic keys: Tab between nav and form, arrows, inline text editing.
 */
final class IdePluginInstallTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use NavigatesSettings;
    use PreservesPath;
    use RunsIde;

    private ?string $portaHome = null;

    protected function setUp(): void
    {
        $portaHome = getenv('PORTA_HOME');
        $this->portaHome = $portaHome ? $portaHome : null;
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
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
        Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
        $tui->handleInput("\t"); // form → nav
        $this->selectSettingsSection($tui->getById('settings-nav'), 'plugins');
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
                    $this->assertStringContainsString('Plugin installed', \Porta\Tests\Support\Toasts::last($ide));
                    $this->assertFileExists($project->path('.porta/plugins/installee/plugin.php'));
                    $this->assertStringContainsString('installee (project)  [Remove]', $screen, 'the new plugin lists with a Remove button');

                    // Live activation: the command is in the registry and the palette.
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Hi from installee', array_column($commands->paletteCommands(), 'label'));
                    $commands->run('installee:hi');
                    $tui->tick();
                    $this->assertStringContainsString('installee says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // The DEFAULT KEY dispatches live too — no restart: the
                    // lifecycle rebuilt the live keymap on activation. (The
                    // dispatcher's command loop runs before any widget, so no
                    // focus dance is needed.)
                    $terminal->consumeOutput();
                    $tui->handleInput("\x1b\x17"); // ctrl+alt+w, the plugin's default key
                    $tui->tick();
                    $this->assertStringContainsString('installee says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the default key dispatched without a restart');

                    // A plugin that installs but fails to load flashes the loader's error.
                    $tui->handleInput("\r"); // the install row is still selected
                    $this->type($tui, $project->path('plugin-src/broken'));
                    $tui->handleInput("\r");
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "broken" failed to load: plugin.php must return a PortaPlugin instance', \Porta\Tests\Support\Toasts::last($ide));
                },
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // A project-scope plugin toggles Enabled via the opt-out `disabledPlugins`
                    // list (bundled plugins use the opt-in `enabledPlugins` instead).
                    $tui->handleInput("\t"); // form → nav
                    for ($i = 0; $i < 10; ++$i) {
                        $tui->handleInput("\x1b[A"); // clamp to the top (General)
                    }
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugins');
                    $tui->handleInput("\x1b[B"); // installee sub-item
                    $tui->handleInput("\t"); // nav → form (Enabled row)
                    $tui->handleInput("\r"); // toggle off
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "installee" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame(['installee'], $config['disabledPlugins'] ?? null, 'a project plugin disables via disabledPlugins');

                    $tui->handleInput("\r"); // toggle back on
                    $tui->tick();
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertArrayNotHasKey('disabledPlugins', $config, 're-enabling clears the list');
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
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Hi from demo', array_column($commands->paletteCommands(), 'label'), 'active before removal');

                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\x1b[B"); // demo's Remove row
                    $tui->handleInput("\r"); // arm
                    $tui->tick();
                    $this->assertStringContainsString('Press Enter again to remove "demo"', \Porta\Tests\Support\Toasts::last($ide));
                    $this->assertDirectoryExists($project->path('.porta/plugins/demo'));

                    $tui->handleInput("\r"); // confirm
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "demo" removed', \Porta\Tests\Support\Toasts::last($ide));
                    $this->assertDirectoryDoesNotExist($project->path('.porta/plugins/demo'));

                    // The loaded code cannot be unloaded: the command still runs.
                    // Live deactivation: palette entry, key dispatch and menu are gone.
                    $commands = Services::resolve($ide, CommandRegistry::class);
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
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // the installer needs a real git
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
                    $this->await(static fn (): bool => str_contains(\Porta\Tests\Support\Toasts::last($ide), 'Plugin installed'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin installed', \Porta\Tests\Support\Toasts::last($ide));
                    $this->assertFileExists($project->path('.porta/plugins/cloned/plugin.php'));
                    $this->assertStringContainsString('cloned (project)  [Remove]', $screen, 'the async refresh re-listed the section');

                    $commands = Services::resolve($ide, CommandRegistry::class);
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
