<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Plugins bundled with Porta (the embedded source, PORTA_EMBEDDED_PLUGINS):
 * they load in every project, sit in a Plugins menu between Code and Run with
 * per-item enablement, are listed as built-in (non-removable) but disableable,
 * and expose their registered settings on a per-plugin settings page.
 */
final class IdeEmbeddedPluginsTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;

    /** The suite-wide PORTA_HOME (the `tempest` highlighting fixture), to restore. */
    private ?string $savedPortaHome = null;

    /**
     * Opt out of the suite-wide `tempest` highlighting fixture installed via
     * PORTA_HOME. This class exercises plugin *machinery* — discovery, listings,
     * the Settings nav — and a permanently present plugin appears in every
     * listing and shifts the index-based navigation.
     *
     * Restored in tearDown: paratest reuses the process, so a leaked PORTA_HOME
     * would silently uncolour later tests in the same worker.
     */
    private function withoutTheHighlightingFixture(): void
    {
        $home = getenv('PORTA_HOME');
        $this->savedPortaHome = false === $home ? null : $home;
        putenv('PORTA_HOME=/nonexistent');
    }

    private function restoreTheHighlightingFixture(): void
    {
        if (null === $this->savedPortaHome) {
            return;
        }
        putenv('PORTA_HOME='.$this->savedPortaHome);
        $this->savedPortaHome = null;
    }

    use RunsIde;

    private string $originalEmbedded;

    protected function setUp(): void
    {
        $this->withoutTheHighlightingFixture();
        $this->originalEmbedded = (string) getenv('PORTA_EMBEDDED_PLUGINS');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        $this->restoreTheHighlightingFixture();
        putenv('PORTA_EMBEDDED_PLUGINS='.$this->originalEmbedded);
    }

    /** An embedded fixture plugin whose menu item is usable only once its binary setting is set. */
    private function embeddedFixture(): TempProject
    {
        return new TempProject(['bundled/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;

                return new class implements PortaPlugin {
                    public function name(): string { return 'bundled'; }
                    public function activate(PluginApi $api): void
                    {
                        $api->registerSetting('binary', 'Tool binary', 'text', '', hint: 'path to the tool');
                        $api->registerCommand('run', 'Bundled Run', static function () use ($api): void {
                            $api->flash('bundled ran '.$api->setting('binary'));
                        });
                        $api->addMenuItem('run', static fn (): bool => '' !== $api->setting('binary'));
                    }
                };
                PHP]);
    }

    /** @return list<\Porta\Workbench\Menu\Menu> */
    private function menus($tui): array
    {
        return Probe::get($tui->getById('menu'), 'menus');
    }

    public function testEmbeddedPluginLoadsWithAMenuBetweenCodeAndRunAndIsNotRemovable(): void
    {
        $embedded = $this->embeddedFixture();
        putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        // Bundled plugins are off by default; opt the fixture in.
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => json_encode(['enabledPlugins' => ['bundled']])]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($embedded): void {
                    $tui->tick();
                    $titles = array_column($this->menus($tui), 'title');
                    $this->assertSame(['File', 'Edit', 'Navigate', 'Code', 'Plugins', 'Run', 'Git', 'View', 'Help'], $titles, 'the Plugins menu sits between Code and Run');
                    $pluginsMenu = $this->menus($tui)[4];
                    $this->assertFalse($pluginsMenu->items[0]->enabled(), 'no binary configured → the entry is disabled');

                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Bundled Run', array_column($commands->paletteCommands(), 'label'), 'the embedded plugin activated');

                    // The manage screen lists it as built-in; actuating is inert.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugins');
                    $tui->handleInput("\t"); // nav → form
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('bundled (built-in)  [Built-in]', $screen, 'no Remove button for embedded plugins');
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B"); // the built-in row
                    $tui->handleInput("\r"); // inert
                    $tui->tick();
                    $this->assertDirectoryExists($embedded->path('bundled'), 'nothing was removed');
                },
                150 => function ($tui, $terminal, $ide) use ($project): void {
                    // The plugin's settings page: set the binary → the menu entry enables.
                    $tui->handleInput("\t"); // form → nav (on Plugins)
                    $tui->handleInput("\x1b[B"); // → the bundled sub-item
                    $this->awaitPainted($tui, $terminal, '   bundled');
                    $this->assertStringContainsString('   bundled', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the nav lists the plugin as an indented sub-item');
                    $tui->handleInput("\t"); // nav → form (Enabled row)
                    $tui->handleInput("\x1b[B"); // → Tool binary
                    $tui->handleInput("\r");
                    foreach (str_split('/opt/tool') as $char) {
                        $tui->handleInput($char);
                    }
                    $tui->handleInput("\r");
                    $tui->tick();

                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame('/opt/tool', $config['plugins']['bundled']['binary'] ?? null, 'the setting persisted');

                    // Outside the settings view (which gates every non-safe item),
                    // the entry is now enabled and the plugin reads the live value.
                    $tui->handleInput("\x1b"); // close the settings view
                    $tui->tick();
                    $this->assertTrue($this->menus($tui)[4]->items[0]->enabled(), 'the menu entry enabled once configured');
                    Services::resolve($ide, CommandRegistry::class)->run('bundled:run');
                    $this->awaitPainted($tui, $terminal, 'bundled ran /opt/tool');
                    $this->assertStringContainsString('bundled ran /opt/tool', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // Back to the plugin's page to disable it live: menu + palette
                    // die and the choice persists.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugins');
                    $tui->handleInput("\x1b[B"); // bundled sub-item
                    $tui->handleInput("\t"); // nav → form
                    $tui->handleInput("\x1b[A"); // clamp to the Enabled row
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r"); // toggle off
                    $this->awaitPainted($tui, $terminal, 'Plugin "bundled" disabled');
                    $this->assertStringContainsString('Plugin "bundled" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertNotContains('Plugins', array_column($this->menus($tui), 'title'), 'the only plugin gone → no Plugins menu');
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertNotContains('Bundled Run', array_column($commands->paletteCommands(), 'label'));
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame([], $config['enabledPlugins'] ?? null, 'a bundled plugin disables by leaving the list — kept explicit, or the absent-key default would flip back on');

                    // And re-enable it live from the same page.
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Plugin "bundled" enabled');
                    $this->assertStringContainsString('Plugin "bundled" enabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertContains('Plugins', array_column($this->menus($tui), 'title'));
                    $this->assertContains('Bundled Run', array_column($commands->paletteCommands(), 'label'));
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame(['bundled'], $config['enabledPlugins'] ?? null, 're-enabling opts it back in');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** A minimal named plugin flashing a distinct label. */
    private function namedPlugin(string $name, string $label): string
    {
        return <<<PHP
            <?php
            use Porta\\Plugin\\Api\\PluginApi;
            use Porta\\Plugin\\Api\\PortaPlugin;

            return new class implements PortaPlugin {
                public function name(): string { return '{$name}'; }
                public function activate(PluginApi \$api): void
                {
                    \$api->registerCommand('run', '{$label}', static function (): void {});
                }
            };
            PHP;
    }

    public function testRemovingAShadowingCopyFallsBackToTheEmbeddedOneAndErrorsFlash(): void
    {
        $embedded = new TempProject([
            'bundled/plugin.php' => $this->namedPlugin('bundled', 'Bundled Run'),
            'shadowfail/plugin.php' => "<?php\nreturn 42;\n", // a broken fallback copy
            'sleeper/plugin.php' => "<?php\nreturn 42;\n", // broken, disabled at boot
        ]);
        putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = new TempProject([
            '.porta/plugins/bundled/plugin.php' => $this->namedPlugin('bundled', 'Project Run'),
            '.porta/plugins/shadowfail/plugin.php' => $this->namedPlugin('shadowfail', 'Shadow OK'),
            '.porta.json' => json_encode(['disabledPlugins' => ['sleeper']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $labels = array_column($commands->paletteCommands(), 'label');
                    $this->assertContains('Project Run', $labels, 'the project copy shadows the embedded one');
                    $this->assertNotContains('Bundled Run', $labels);

                    // Remove the project override: the embedded copy reactivates live.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t");
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugins');
                    $tui->handleInput("\t"); // → form
                    $tui->handleInput("\x1b[B"); // install row
                    $tui->handleInput("\x1b[B"); // bundled (project) Remove
                    $tui->handleInput("\r");
                    $tui->handleInput("\r");
                    $tui->tick();
                    $labels = array_column($commands->paletteCommands(), 'label');
                    $this->assertNotContains('Project Run', $labels, 'the override died');
                    $this->assertContains('Bundled Run', $labels, 'the shadowed embedded copy took over live');

                    // Removing a plugin whose fallback copy is broken flashes the loader error.
                    $tui->handleInput("\r"); // the shadowfail Remove row slid into the selection
                    $tui->handleInput("\r");
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Plugin "shadowfail" failed to load', $screen);
                    $this->assertNotContains('Shadow OK', array_column($commands->paletteCommands(), 'label'));
                },
                150 => function ($tui, $terminal): void {
                    // Enabling a disabled plugin whose file is broken flashes too.
                    $tui->handleInput("\t"); // form → nav (on Plugins)
                    $tui->handleInput("\x1b[B"); // bundled
                    $tui->handleInput("\x1b[B"); // shadowfail
                    $tui->handleInput("\x1b[B"); // sleeper
                    $tui->handleInput("\t"); // → form
                    $tui->handleInput("\x1b[A"); // clamp to the Enabled row
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r"); // toggle on → load fails
                    $this->awaitPainted($tui, $terminal, 'Plugin "sleeper" failed to load');
                    $this->assertStringContainsString('Plugin "sleeper" failed to load', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testADisabledPluginIsSkippedAtBootButStaysListedForReEnabling(): void
    {
        $embedded = $this->embeddedFixture();
        putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = new TempProject([
            '.porta.json' => json_encode(['disabledPlugins' => ['bundled']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertNotContains('Bundled Run', array_column($commands->paletteCommands(), 'label'), 'skipped at boot');
                    $this->assertNotContains('Plugins', array_column($this->menus($tui), 'title'));

                    // Its settings page is still reachable, showing the unchecked toggle.
                    $commands->run('ide_settings');
                    $tui->handleInput("\t");
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugins');
                    $tui->handleInput("\x1b[B"); // bundled sub-item
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('[ ] Enabled', $screen, 'listed and re-enableable');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAThirdPartyPluginShipsItsOwnNamespacedClass(): void
    {
        // The committed fixture is shaped like a real third-party plugin: a
        // composer.json whose psr-4 declares `Acme\\Tools`, and a class under its own src/
        // that composer's autoloader knows nothing about.
        // setUp() already opted this class out of the PORTA_HOME fixture, and
        // tearDown restores it — calling either again here would capture the
        // ALREADY-modified value and leak /nonexistent into the whole worker.
        $fixture = \dirname(__DIR__).'/Support/fixtures/thirdparty/acme-tools';
        $project = new TempProject(['a.php' => "<?php\n"]);
        mkdir($project->path('.porta/plugins'), permissions: 0o777, recursive: true);
        symlink($fixture, $project->path('.porta/plugins/acme-tools'));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Acme: Greet', array_column($commands->paletteCommands(), 'label'), 'the plugin loaded');

                    // Running it reaches into the plugin's OWN class, which only
                    // resolves through the manifest's autoload mapping.
                    $commands->run('acme-tools:greet');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'hello from acme-tools'), what: "the plugin class's answer");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAThirdPartyPluginUsesAPackageItVendoredWithComposer(): void
    {
        // The whole strand end to end: the fixture ships composer.json + a
        // vendor/ with composer's generated maps, and its own class calls into
        // the vendored package. Nothing requires composer's autoloader — doing
        // so would PREPEND the plugin's loader and let it take over Porta's own
        // dependencies process-wide.
        $fixture = \dirname(__DIR__).'/Support/fixtures/thirdparty/acme-vendored';
        $project = new TempProject(['a.php' => "<?php\n"]);
        mkdir($project->path('.porta/plugins'), permissions: 0o777, recursive: true);
        symlink($fixture, $project->path('.porta/plugins/acme-vendored'));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertContains('Acme: Greet', array_column($commands->paletteCommands(), 'label'), 'the plugin loaded');
                    $commands->run('acme-vendored:greet');
                    $this->await(
                        static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'acme psr4-ok classmap-ok'),
                        what: 'both halves of the vendored map',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A PATH holding one executable named `composer` that fabricates the vendor
     * tree a real install would and exits with $exitCode.
     *
     * Real composer is not the suite's business — network, minutes, and a
     * resolver we do not own. `PluginInstaller::create()` resolves the binary
     * per boot rather than at compile time, so a PATH set before runIde() is
     * what the IDE finds. The php binary is on it too, or the plugin stubs'
     * `#!/usr/bin/env php` shebangs would not run.
     */
    private function fakeComposerPath(TempProject $project, int $exitCode = 0): string
    {
        $bin = $project->path('fakebin');
        mkdir($bin, permissions: 0o777, recursive: true);
        file_put_contents($bin.'/composer', data: '#!'.\PHP_BINARY.' -n'."\n<?php\n".<<<'PHP'
            @mkdir(getcwd().'/vendor/composer', 0o777, true);
            file_put_contents(getcwd().'/vendor/composer/autoload_psr4.php', "<?php return [];\n");
            PHP."\nexit({$exitCode});\n");
        chmod($bin.'/composer', permissions: 0o755);
        symlink(\PHP_BINARY, $bin.'/php');

        return $bin;
    }

    /** A project plugin that declares a composer package it has not installed. */
    private function needyPlugin(TempProject $project, string $name, array $requires = []): void
    {
        $this->projectPlugin($project, $name);
        $manifest = ['require' => ['vend/lib' => '^1.0']];
        if ([] !== $requires) {
            $manifest['extra'] = ['porta' => ['requires' => $requires]];
        }
        file_put_contents($project->path('.porta/plugins/'.$name.'/composer.json'), data: (string) json_encode($manifest));
    }

    /** A project plugin directory: an entry file, and a manifest when $requires is given. */
    private function projectPlugin(TempProject $project, string $name, array $requires = []): void
    {
        $dir = $project->path('.porta/plugins/'.$name);
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', data: $this->namedPlugin($name, ucfirst($name).' Run'));
        if ([] !== $requires) {
            file_put_contents($dir.'/composer.json', data: (string) json_encode(['extra' => ['porta' => ['requires' => $requires]]]));
        }
    }

    public function testADependencyLoadsFirstAndAMissingOneKeepsThePluginOut(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->projectPlugin($project, 'zbase'); // discovered LAST alphabetically
        $this->projectPlugin($project, 'app', ['zbase']); // but needed FIRST
        $this->projectPlugin($project, 'orphan', ['absent']);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $labels = array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label');
                    $this->assertContains('Zbase Run', $labels);
                    $this->assertContains('App Run', $labels);
                    $this->assertLessThan(
                        array_search('App Run', $labels, strict: true),
                        array_search('Zbase Run', $labels, strict: true),
                        'the dependency registered first, so it loaded first',
                    );

                    // The one whose requirement does not exist is skipped, and
                    // the boot warning says which requirement. The toast MODEL,
                    // not the painted screen: the warning is flashed during the
                    // boot wiring, and what has reached the terminal by now is a
                    // question about frame timing, not about the warning.
                    $this->assertNotContains('Orphan Run', $labels);
                    $this->assertStringContainsString('needs "absent"', implode("\n", Toasts::all($ide)));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEnablingBringsWhatItRequiresAndDisablingTakesItsDependents(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) json_encode(['disabledPlugins' => ['app', 'zbase']]),
            'a.php' => "<?php\n",
        ]);
        $this->projectPlugin($project, 'zbase');
        $this->projectPlugin($project, 'app', ['zbase']);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertNotContains('App Run', array_column($commands->paletteCommands(), 'label'), 'both start off');

                    // Enabling the dependent brings its requirement with it.
                    $lifecycle->enable('app');
                    $labels = array_column($commands->paletteCommands(), 'label');
                    $this->assertContains('App Run', $labels);
                    $this->assertContains('Zbase Run', $labels, 'the requirement came along');
                    // The toast MODEL, not the painted row: no frame has run
                    // between the enable and this assertion.
                    $this->assertContains('Also enabled: zbase', Toasts::all($ide));

                    // A requirement that is ALREADY on brings nothing along:
                    // turning the dependent off and on again is a no-op for it.
                    $lifecycle->disable('app');
                    $lifecycle->enable('app');
                    $this->assertContains('App Run', array_column($commands->paletteCommands(), 'label'));
                    $this->assertSame(['Plugin "app" enabled'], array_slice(Toasts::all($ide), 0, 1), 'nothing to also-enable');

                    // Disabling the requirement takes the dependent with it: it
                    // cannot work without what it declared it needs.
                    $lifecycle->disable('zbase');
                    $labels = array_column($commands->paletteCommands(), 'label');
                    $this->assertNotContains('Zbase Run', $labels);
                    $this->assertNotContains('App Run', $labels, 'the dependent went too');
                    $this->assertContains('Also disabled: app', Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEnablingSomethingNotInstalledSaysSoRatherThanClaimingSuccess(): void
    {
        // The first-run screen and the plugins page both offer to enable a
        // recommended plugin. If it is not installed, the choice is still worth
        // persisting — install it later and it comes up on — but reporting
        // "enabled" for a load that never happened is a report of something
        // that did not occur.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, PluginLifecycle::class)->enable('not-installed-anywhere');

                    $this->assertContains('Plugin "not-installed-anywhere" is not installed', Toasts::all($ide));
                    $this->assertNotContains('Plugin "not-installed-anywhere" enabled', Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEnablingAPluginInstallsItsDependenciesFirst(): void
    {
        // The toggle means "make this plugin work", and a plugin that owes an
        // install does not work — it is declined at boot with a message telling
        // the user to run composer by hand. Now Porta runs it.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'needy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['needy']]));
        putenv('PATH='.$this->fakeComposerPath($project));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $labels = static fn (): array => array_column($commands->paletteCommands(), 'label');
                    $this->assertNotContains('Needy Run', $labels(), 'disabled to start with');

                    Services::resolve($ide, PluginLifecycle::class)->enable('needy');

                    // Synchronously, before any loop turn: the call did not block.
                    $this->assertContains('Installing dependencies for "needy"…', Toasts::all($ide));
                    $this->assertNotContains('Needy Run', $labels(), 'and did not activate ahead of its own install');

                    $this->await(static fn (): bool => \in_array('Needy Run', $labels(), strict: true), what: 'the plugin to activate after its install');

                    $this->assertFileExists($project->path('.porta/plugins/needy/vendor/composer/autoload_psr4.php'));
                    $this->assertContains('Plugin "needy" enabled', Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTheRequirementChainInstallsOneAtATimeAndStillDepsFirst(): void
    {
        // One process slot, so the queue is the whole design: `app` must not
        // load while `zbase` — which it requires — is still installing.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'zbase');
        $this->needyPlugin($project, 'app', ['zbase']);
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['app', 'zbase']]));
        putenv('PATH='.$this->fakeComposerPath($project));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $labels = static fn (): array => array_column($commands->paletteCommands(), 'label');

                    Services::resolve($ide, PluginLifecycle::class)->enable('app');
                    $this->await(static fn (): bool => \in_array('App Run', $labels(), strict: true), what: 'both installs to land');

                    $order = $labels();
                    $this->assertLessThan(
                        array_search('App Run', $order, strict: true),
                        (int) array_search('Zbase Run', $order, strict: true),
                        'the requirement installed and loaded first',
                    );
                    $this->assertContains('Also enabled: zbase', Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEnablingWithoutComposerKeepsTheChoiceAndSaysWhatIsMissing(): void
    {
        // The enable is what the user expressed; a missing binary is a
        // transient fact about the machine. A checkbox that flips itself back
        // would be worse than a message — and the next boot picks it up.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'needy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['needy']]));
        putenv('PATH=/nonexistent');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, PluginLifecycle::class)->enable('needy');

                    $toasts = Toasts::all($ide);
                    $this->assertContains('Plugin "needy" enabled, but composer is not available to install its dependencies', $toasts);
                    $this->assertNotContains('Plugin "needy" is not installed', $toasts, 'it IS installed; only its packages are missing');
                    $this->assertNotContains('Needy Run', array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label'));
                    $this->assertSame([], \Porta\Config\IdeConfig::load($project->dir)->disabledPlugins, 'the choice stands');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAFailedInstallLeavesThePluginEnabledAndSaysWhichCodeFailed(): void
    {
        // "is not installed" would be a lie here — it IS installed; its
        // packages are not. The choice stands, and the next boot retries.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'needy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['needy']]));
        putenv('PATH='.$this->fakeComposerPath($project, exitCode: 3));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, PluginLifecycle::class)->enable('needy');
                    $this->await(static fn (): bool => \in_array('Plugin "needy" enabled, but composer install failed (exit code 3)', Toasts::all($ide), strict: true), what: 'the failure report');

                    $this->assertNotContains('Plugin "needy" is not installed', Toasts::all($ide));
                    $this->assertNotContains(
                        'Needy Run',
                        array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label'),
                        'not loaded: it would fatal on the first missing symbol',
                    );
                    $this->assertSame([], \Porta\Config\IdeConfig::load($project->dir)->disabledPlugins, 'the choice stands');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDisablingAPluginMidInstallDropsItsActivation(): void
    {
        // The install lands after the user changed their mind. Loading it then
        // would resurrect a plugin the user just switched off — and the disable
        // has already flashed, so there is nothing left to say.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'needy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['needy']]));
        putenv('PATH='.$this->fakeComposerPath($project));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $lifecycle->enable('needy');
                    $lifecycle->disable('needy'); // before the subprocess can exit

                    $this->await(static fn (): bool => is_dir($project->path('.porta/plugins/needy/vendor')), what: 'the install to finish anyway');
                    $this->pump(0.1); // let the exit callback land, proving it does nothing

                    $this->assertNotContains('Needy Run', array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label'), 'the late install did not resurrect it');
                    $this->assertContains('Plugin "needy" disabled', Toasts::all($ide));
                    $this->assertNotContains('Plugin "needy" enabled', Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEnablingWhileAnotherInstallRunsIsRefusedRatherThanQueued(): void
    {
        // One process slot, one visible refusal. A hidden queue would leave the
        // user with a plugin that silently comes up minutes later.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'busy');
        $this->needyPlugin($project, 'needy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['busy', 'needy']]));
        putenv('PATH='.$this->fakeComposerPath($project));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // Another plugin's enable takes the slot; this one asks for
                    // it while composer is still running.
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $lifecycle->enable('busy');
                    $lifecycle->enable('needy');

                    $this->assertContains('Cannot install "needy" dependencies now — another install is already running', Toasts::all($ide));
                    $this->assertNotContains('Needy Run', array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label'));

                    // Let the install that held the slot finish, so the test
                    // does not stop the loop on an armed process watcher.
                    $this->await(static fn (): bool => is_dir($project->path('.porta/plugins/busy/vendor')), what: "the other plugin's install");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDisablingRemovesTheDependenciesPortaInstalled(): void
    {
        // The symmetry the toggle promises: what enabling put on disk, disabling
        // takes back. It costs a reinstall to turn it on again — that is the
        // trade, and it only applies to trees Porta made.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'needy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['needy']]));
        putenv('PATH='.$this->fakeComposerPath($project));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $vendor = $project->path('.porta/plugins/needy/vendor');

                    $lifecycle->enable('needy');
                    // The marker, not the directory: composer creates vendor/
                    // while it runs and the marker lands with its exit code, so
                    // awaiting the directory would race the claim.
                    $this->await(static fn (): bool => is_file($vendor.'/.porta-installed'), what: 'the install to be marked ours');

                    $lifecycle->disable('needy');
                    $this->assertDirectoryDoesNotExist($vendor, 'and taken back');
                    $this->assertFileExists($project->path('.porta/plugins/needy/composer.json'), 'the plugin itself stands');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAVendorTreeThePluginShippedSurvivesADisable(): void
    {
        // Porta only deletes what Porta wrote. A plugin that commits its
        // vendor/ would otherwise lose tracked files to a checkbox.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->projectPlugin($project, 'shipped');
        $vendor = $project->path('.porta/plugins/shipped/vendor');
        mkdir($vendor.'/vend', permissions: 0o777, recursive: true);
        file_put_contents($vendor.'/vend/Thing.php', data: "<?php\n");
        putenv('PATH=/nonexistent');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($vendor): void {
                    Services::resolve($ide, PluginLifecycle::class)->disable('shipped');

                    $this->assertFileExists($vendor.'/vend/Thing.php', 'no marker, no deletion');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRemovalIsDeferredToNobodyWhenAnInstallIsRunning(): void
    {
        // Deleting a tree while composer works would leave a half-vendor that
        // the next boot reads as complete. Refused, and said out loud, because
        // the disk is now in a state the next enable behaves differently in.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $this->needyPlugin($project, 'needy');
        $this->needyPlugin($project, 'busy');
        $project->write('.porta.json', (string) json_encode(['disabledPlugins' => ['needy', 'busy']]));
        putenv('PATH='.$this->fakeComposerPath($project));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $vendor = $project->path('.porta/plugins/needy/vendor');

                    $lifecycle->enable('needy');
                    $this->await(static fn (): bool => is_file($vendor.'/.porta-installed'), what: "needy's install");

                    $lifecycle->enable('busy'); // takes the slot
                    $lifecycle->disable('needy'); // …so this cannot delete

                    $this->assertContains('Cannot remove "needy" dependencies now — an install is running', Toasts::all($ide));
                    $this->assertDirectoryExists($vendor, 'left intact rather than half-deleted');

                    $this->await(static fn (): bool => is_dir($project->path('.porta/plugins/busy/vendor')), what: 'the other install');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDisablingSomethingNotInstalledAnywhereIsHarmless(): void
    {
        // A name from a config file that no directory answers to: nothing to
        // deactivate, nothing to delete, and the choice still records.
        $project = new TempProject(['a.php' => "<?php\n"]);
        putenv('PATH=/nonexistent');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, PluginLifecycle::class)->disable('ghost');

                    $this->assertContains('Plugin "ghost" disabled', Toasts::all($ide));
                    $this->assertSame(['ghost'], \Porta\Config\IdeConfig::load($project->dir)->disabledPlugins);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
