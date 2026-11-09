<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FakeComposer;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Settings → Plugins: installing (local path, git URL) with live activation,
 * and two-press removal — every source kind resolved through composer (a
 * fake `composer` on PATH; real network/Packagist is not this suite's
 * business). Drives the real settings view with synthetic keys: Tab between
 * nav and form, arrows, inline text editing.
 */
final class IdePluginInstallTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use NavigatesSettings;
    use PreservesPath;
    use RunsIde;

    private ?string $portaHome = null;
    private string $composerBin;

    protected function setUp(): void
    {
        $portaHome = \getenv('PORTA_HOME');
        $this->portaHome = $portaHome ? $portaHome : null;
        $this->composerBin = FakeComposer::write(\sys_get_temp_dir().'/porta-e2e-fakebin-'.\bin2hex(\random_bytes(4)));
        \putenv('PATH='.$this->composerBin);
    }

    protected function tearDown(): void
    {
        \putenv(null !== $this->portaHome ? 'PORTA_HOME='.$this->portaHome : 'PORTA_HOME');
        // Set in setUp(), always to a fresh folder of this test's own: it went
        // nowhere before, and every run left one behind.
        new Filesystem()->remove(\dirname($this->composerBin)); // the binary's own folder
    }

    /** A plugin source registering one flashing command, with a composer.json declaring it a Porta plugin package. */
    private function pluginSource(string $name): string
    {
        return <<<PHP
            <?php
            use Porta\\Plugin\\Api\\PluginApi;
            use Porta\\Plugin\\Api\\PortaPlugin;

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

    /** The composer.json a plugin source dir needs for a path/vcs install to find it. */
    private function pluginManifest(string $name): string
    {
        return (string) \json_encode(['name' => 'test/'.$name, 'type' => 'porta-plugin'], \JSON_THROW_ON_ERROR);
    }

    /** Open settings and select the Plugins section, leaving focus on the form. */
    private function openPluginsSection(Tui $tui, Ide $ide): void
    {
        Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
        $tui->handleInput("\t"); // form → nav
        $this->selectSettingsSection($this->settingsNav($tui), 'plugins');
        $tui->handleInput("\t"); // nav → form (scope row selected)
    }

    /** Type into the form's inline text editor. */
    private function type($tui, string $text): void
    {
        foreach (\str_split($text) as $char) {
            $tui->handleInput($char);
        }
    }

    public function testLocalPathInstallActivatesLive(): void
    {
        $project = new TempProject([
            'plugin-src/installee/porta.php' => $this->pluginSource('installee'),
            'plugin-src/installee/composer.json' => $this->pluginManifest('installee'),
            'plugin-src/broken/porta.php' => "<?php\nreturn 42;\n",
            'plugin-src/broken/composer.json' => $this->pluginManifest('broken'),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->openPluginsSection($tui, $ide);
                    // A bare tick() is not a frame, and these read ACCUMULATED
                    // output — so without a real paint they asserted against
                    // whatever the boot happened to leave. It held until the
                    // pcov run, which is 2-3x slower, and failed there.
                    $this->awaitPainted($tui, $terminal, 'Save changes to');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertMatchesRegularExpression('/Save changes to\s+[│┃] \.porta\.json\s+▾ [│┃]/u', $screen);
                    $this->assertStringContainsString('git URL, local path, or vendor/name package', $screen, 'the empty install field shows its hint');

                    $tui->handleInput("\x1b[B"); // → the install row
                    $tui->handleInput("\r"); // start inline editing
                    $this->type($tui, $project->path('plugin-src/installee'));
                    $tui->handleInput("\r"); // commit → async composer require + live activation
                    $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'Plugin installed'));
                    // The toast is the MODEL saying so; the re-listed section is
                    // a later paint, and a bare tick() is not a frame.
                    $this->awaitPainted($tui, $terminal, '· on · project');

                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin installed', Toasts::last($ide));
                    $this->assertFileExists($project->path('.porta/plugins/installee/porta.php'));
                    $this->assertMatchesRegularExpression('/installee\s+[│┃]\s+Open\s+[│┃] · on · project/u', $screen, 'the new plugin lists, on, with a button to its page');

                    // Live activation: the command is in the registry and the palette.
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Hi from installee', \array_column($commands->paletteCommands(), 'label'));
                    $commands->run('installee:hi');
                    $this->awaitPainted($tui, $terminal, 'installee says hi');
                    $this->assertStringContainsString('installee says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // The DEFAULT KEY dispatches live too — no restart: the
                    // lifecycle rebuilt the live keymap on activation. (The
                    // dispatcher's command loop runs before any widget, so no
                    // focus dance is needed.)
                    $terminal->consumeOutput();
                    $tui->handleInput("\x1b\x17"); // ctrl+alt+w, the plugin's default key
                    $this->awaitPainted($tui, $terminal, 'installee says hi');
                    $this->assertStringContainsString('installee says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the default key dispatched without a restart');

                    // A plugin's key reaches past the Settings window as its menu
                    // item would: not one of Settings' own, it closed the window.
                    $this->assertFalse(Services::resolve($ide, ViewRegistry::class)->isOpen('settings'), "the plugin's key closed the Settings window first");

                    // A plugin that installs but fails to load flashes the loader's error.
                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\r"); // the install row: Settings reopens where it was left
                    $this->type($tui, $project->path('plugin-src/broken'));
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'failed to load'));
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "broken" failed to load: porta.php must return a PortaPlugin instance', Toasts::last($ide));
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, $ide) use ($project): void {
                    // A project-scope plugin toggles Enabled via the opt-out `disabledPlugins`
                    // list (bundled plugins use the opt-in `enabledPlugins` instead).
                    $tui->handleInput("\t"); // form → nav
                    for ($i = 0; $i < 10; ++$i) {
                        $tui->handleInput("\x1b[A"); // clamp to the top (General)
                    }
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugins');
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugin:installee'); // the installee sub-item, past the Store
                    $tui->handleInput("\t"); // nav → form
                    for ($i = 0; $i < 10; ++$i) {
                        $tui->handleInput("\x1b[A"); // clamp to the Enabled row: the page ends in Remove
                    }
                    $tui->handleInput("\r"); // toggle off
                    $this->awaitPainted($tui, $terminal, 'Plugin "installee" disabled');
                    $this->assertStringContainsString('Plugin "installee" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                    $this->assertSame(['installee'], $config['disabledPlugins'] ?? null, 'a project plugin disables via disabledPlugins');

                    $tui->handleInput("\r"); // toggle back on
                    $tui->tick();
                    $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                    $this->assertArrayNotHasKey('disabledPlugins', $config, 're-enabling clears the list');
                },
            ],
        );
    }

    public function testRemovalTakesTwoPressesAndDeactivatesLive(): void
    {
        $project = new TempProject([
            '.porta/plugins/demo/porta.php' => $this->pluginSource('demo'),
            'plugin-src/demo/porta.php' => $this->pluginSource('demo'),
            'plugin-src/demo/composer.json' => $this->pluginManifest('demo'),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Hi from demo', \array_column($commands->paletteCommands(), 'label'), 'active before removal');

                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\x1b[B"); // demo's row
                    $tui->handleInput("\r"); // Open: demo's own page
                    for ($row = 0; $row < 10; ++$row) {
                        $tui->handleInput("\x1b[B"); // clamps on the last row, Remove
                    }
                    $tui->handleInput("\r"); // arm
                    $tui->tick();
                    $this->assertStringContainsString('Press Enter again to remove "demo"', Toasts::last($ide));
                    $this->assertDirectoryExists($project->path('.porta/plugins/demo'));

                    $tui->handleInput("\r"); // confirm
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "demo" removed', Toasts::last($ide));
                    $this->assertDirectoryDoesNotExist($project->path('.porta/plugins/demo'));

                    // The loaded code cannot be unloaded: the command still runs.
                    // Live deactivation: palette entry, key dispatch and menu are gone.
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertNotContains('Hi from demo', \array_column($commands->paletteCommands(), 'label'), 'palette entry died');
                    $this->assertNotContains('demo:hi', $commands->keyBoundIds(), 'key dispatch died');
                    $terminal->clearOutput();
                    $commands->run('demo:hi'); // unknown id → safe no-op
                    // frame(), not tick(): this asserts an ABSENCE, so it has to
                    // force the paint that would have proved it wrong.
                    $this->frame($tui);
                    $this->assertStringNotContainsString('demo says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // The same name reinstalls live from a fresh source dir.
                    $tui->handleInput("\x1b[A"); // clamp to the top (scope row)…
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\x1b[B"); // …then down to the install row
                    $tui->handleInput("\r");
                    $this->type($tui, $project->path('plugin-src/demo'));
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'Plugin installed'));
                    $tui->tick();
                    $this->assertContains('Hi from demo', \array_column($commands->paletteCommands(), 'label'), 'reinstalled live');
                    $commands->run('demo:hi');
                    $this->awaitPainted($tui, $terminal, 'demo says hi');
                    $this->assertStringContainsString('demo says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'and its boot-time key/handler works again');
                },
            ],
        );
    }

    public function testGitUrlInstallClonesAndActivates(): void
    {
        // The installer needs a real git to peek the repository, AND the fake
        // composer to actually require the package once peeked — both on PATH.
        \putenv('PATH='.$this->composerBin.\PATH_SEPARATOR.\PORTA_TEST_PRISTINE_PATH);
        $this->skipWithoutGit();

        // The installed name derives from the repo's OWN composer.json "name",
        // so host the repo in a subdirectory with a valid plugin name too.
        $host = new TempProject([
            'cloned/porta.php' => $this->pluginSource('cloned'),
            'cloned/composer.json' => $this->pluginManifest('cloned'),
        ]);
        $dir = \escapeshellarg($host->path('cloned'));
        \exec("git -C {$dir} init -q 2>/dev/null && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init 2>/dev/null");
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, Ide $ide) use ($host): void {
                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\r");
                    $this->type($tui, 'file://'.$host->path('cloned'));
                    $tui->handleInput("\r"); // commit → async clone
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'Plugin installed'));
                    $this->awaitPainted($tui, $terminal, '· on · project');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin installed', Toasts::last($ide));
                    $this->assertFileExists($project->path('.porta/plugins/cloned/porta.php'));
                    $this->assertMatchesRegularExpression('/cloned\s+[│┃]\s+Open\s+[│┃] · on · project/u', $screen, 'the async refresh re-listed the section');

                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $commands->run('cloned:hi');
                    $this->awaitPainted($tui, $terminal, 'cloned says hi');
                    $this->assertStringContainsString('cloned says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testGlobalScopeInstallLandsInPortaHome(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            'plugin-src/worldwide/porta.php' => $this->pluginSource('worldwide'),
            'plugin-src/worldwide/composer.json' => $this->pluginManifest('worldwide'),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, VirtualTerminal $terminal, Ide $ide) use ($project, $home): void {
                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\x1b[C"); // step the scope select: project → global (Enter would drop its list)
                    // A substring that does not spell the gap: the value column is aligned
                    // per page, and a stale literal here is a ten-second timeout
                    // rather than a failure.
                    $this->awaitPainted($tui, $terminal, '~/.porta/config.json');
                    $this->assertMatchesRegularExpression('/Save changes to\s+[│┃] ~\/\.porta\/config\.json\s+▾ [│┃]/u', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\r");
                    $this->type($tui, $project->path('plugin-src/worldwide'));
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'Plugin installed'));
                    $this->awaitPainted($tui, $terminal, '· on · global');

                    $this->assertFileExists($home->dir.'/.porta/plugins/worldwide/porta.php');
                    $this->assertDirectoryDoesNotExist($project->path('.porta/plugins/worldwide'));
                    $this->assertMatchesRegularExpression('/worldwide\s+[│┃]\s+Open\s+[│┃] · on · global/u', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    /**
     * A tool install still running when the IDE quits is stopped with it —
     * not left to finish into nothing, writing under ~/.porta. The manager
     * here is a script that would leave a mark a second after it starts.
     */
    public function testQuittingStopsAToolInstallStillRunning(): void
    {
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta/plugins/needy/porta.php' => $this->pluginSource('needy'),
            '.porta/plugins/needy/composer.json' => (string) \json_encode(['extra' => ['porta' => ['tools' => [['name' => 'x', 'sources' => [['brew', 'x']]]]]]], \JSON_THROW_ON_ERROR),
        ]);
        $mark = $project->path('installed.mark');
        $pidFile = $project->path('brew.pid');
        $brew = $project->path('bin/brew');
        \mkdir(\dirname($brew));
        \file_put_contents($brew, "#!/bin/sh\necho \$\$ > '{$pidFile}'\nsleep 1\ntouch '{$mark}'\n");
        \chmod($brew, permissions: 0o755);
        // Before the IDE starts: the installer finds its managers on the PATH once.
        \putenv('PATH='.\dirname($brew).':/usr/bin:/bin');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, Ide $ide) use ($pidFile): void {
                    Services::resolve($ide, CommandRegistry::class)->run('plugins_install_tools');
                    $this->assertNotSame([], Probe::get(Services::resolve($ide, RunningProcesses::class), 'running'), 'the manager runs, kept where quitting can reach it');
                    // The manager is its own process, so waiting here for its
                    // first line does not hold up the loop it runs beside.
                    $this->await(static fn (): bool => \is_file($pidFile) && '' !== \file_get_contents($pidFile), timeout: 3.0, what: 'the manager to say who it is');
                },
            ],
        );

        // Not a fixed wait: the manager's own pid is the positive control. Once
        // that process has ended it can never write the mark, so the negative
        // below is final rather than "not yet". Ended includes a zombie: the
        // IDE killed it, and the reaper that would collect it stopped with
        // the loop.
        $pid = (int) \file_get_contents($pidFile);
        $this->assertGreaterThan(0, $pid, 'the manager started and said who it is');
        $ended = static fn (): bool => !\posix_kill($pid, signal: 0) || \str_starts_with(\trim((string) \shell_exec('ps -o stat= -p '.$pid)), 'Z');
        $this->await($ended, timeout: 3.0, what: 'quitting to end the manager');
        $this->assertFileDoesNotExist($mark, 'quitting stopped it before it finished');
    }
}
