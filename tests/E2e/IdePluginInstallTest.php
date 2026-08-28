<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FakeComposer;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Tui\Ansi\AnsiUtils;

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
        $portaHome = getenv('PORTA_HOME');
        $this->portaHome = $portaHome ? $portaHome : null;
        $this->composerBin = FakeComposer::write(sys_get_temp_dir().'/porta-e2e-fakebin-'.bin2hex(random_bytes(4)));
        putenv('PATH='.$this->composerBin);
    }

    protected function tearDown(): void
    {
        putenv(null !== $this->portaHome ? 'PORTA_HOME='.$this->portaHome : 'PORTA_HOME');
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
        return (string) json_encode(['name' => 'test/'.$name, 'type' => 'porta-plugin']);
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
            'plugin-src/installee/composer.json' => $this->pluginManifest('installee'),
            'plugin-src/broken/plugin.php' => "<?php\nreturn 42;\n",
            'plugin-src/broken/composer.json' => $this->pluginManifest('broken'),
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
                    $this->assertMatchesRegularExpression('/Save changes to\s+< \.porta\.json >/', $screen);
                    $this->assertStringContainsString('git URL, local path, or vendor/name package', $screen, 'the empty install field shows its hint');

                    $tui->handleInput("\x1b[B"); // → the install row
                    $tui->handleInput("\r"); // start inline editing
                    $this->type($tui, $project->path('plugin-src/installee'));
                    $tui->handleInput("\r"); // commit → async composer require + live activation
                    $this->await(static fn (): bool => str_contains(\Porta\Tests\Support\Toasts::last($ide), 'Plugin installed'));
                    $tui->tick();

                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin installed', \Porta\Tests\Support\Toasts::last($ide));
                    $this->assertFileExists($project->path('.porta/plugins/installee/plugin.php'));
                    $this->assertMatchesRegularExpression('/installee \(project\)\s+\[Remove\]/', $screen, 'the new plugin lists with a Remove button');

                    // Live activation: the command is in the registry and the palette.
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Hi from installee', array_column($commands->paletteCommands(), 'label'));
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

                    // A plugin that installs but fails to load flashes the loader's error.
                    $tui->handleInput("\r"); // the install row is still selected
                    $this->type($tui, $project->path('plugin-src/broken'));
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => str_contains(\Porta\Tests\Support\Toasts::last($ide), 'failed to load'));
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
                    $this->awaitPainted($tui, $terminal, 'Plugin "installee" disabled');
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
            'plugin-src/demo/composer.json' => $this->pluginManifest('demo'),
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
                    $this->await(static fn (): bool => str_contains(\Porta\Tests\Support\Toasts::last($ide), 'Plugin installed'));
                    $tui->tick();
                    $this->assertContains('Hi from demo', array_column($commands->paletteCommands(), 'label'), 'reinstalled live');
                    $commands->run('demo:hi');
                    $this->awaitPainted($tui, $terminal, 'demo says hi');
                    $this->assertStringContainsString('demo says hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'and its boot-time key/handler works again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitUrlInstallClonesAndActivates(): void
    {
        // The installer needs a real git to peek the repository, AND the fake
        // composer to actually require the package once peeked — both on PATH.
        putenv('PATH='.$this->composerBin.\PATH_SEPARATOR.PORTA_TEST_PRISTINE_PATH);
        if (null === new ExecutableFinder()->find('git')) {
            self::markTestSkipped('git binary not available');
        }

        // The installed name derives from the repo's OWN composer.json "name",
        // so host the repo in a subdirectory with a valid plugin name too.
        $host = new TempProject([
            'cloned/plugin.php' => $this->pluginSource('cloned'),
            'cloned/composer.json' => $this->pluginManifest('cloned'),
        ]);
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
                    $this->assertMatchesRegularExpression('/cloned \(project\)\s+\[Remove\]/', $screen, 'the async refresh re-listed the section');

                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $commands->run('cloned:hi');
                    $this->awaitPainted($tui, $terminal, 'cloned says hi');
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
            'plugin-src/worldwide/composer.json' => $this->pluginManifest('worldwide'),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($project, $home): void {
                    $this->openPluginsSection($tui, $ide);
                    $tui->handleInput("\r"); // cycle the scope row: project → global
                    // A substring that does not spell the gap: the value column is aligned
                    // per page, and a stale literal here is a ten-second timeout
                    // rather than a failure.
                    $this->awaitPainted($tui, $terminal, '< ~/.porta/config.json >');
                    $this->assertMatchesRegularExpression('/Save changes to\s+< ~\/\.porta\/config\.json >/', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\r");
                    $this->type($tui, $project->path('plugin-src/worldwide'));
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => str_contains(\Porta\Tests\Support\Toasts::last($ide), 'Plugin installed'));
                    $tui->tick();

                    $this->assertFileExists($home->dir.'/.porta/plugins/worldwide/plugin.php');
                    $this->assertDirectoryDoesNotExist($project->path('.porta/plugins/worldwide'));
                    $this->assertMatchesRegularExpression('/worldwide \(global\)\s+\[Remove\]/', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
