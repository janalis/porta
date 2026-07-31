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
                    $this->frame($tui);
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
                    $this->frame($tui);
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
                    $this->frame($tui);
                    $this->assertStringContainsString('Plugin "bundled" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertNotContains('Plugins', array_column($this->menus($tui), 'title'), 'the only plugin gone → no Plugins menu');
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertNotContains('Bundled Run', array_column($commands->paletteCommands(), 'label'));
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame([], $config['enabledPlugins'] ?? null, 'a bundled plugin disables by leaving the list — kept explicit, or the absent-key default would flip back on');

                    // And re-enable it live from the same page.
                    $tui->handleInput("\r");
                    $this->frame($tui);
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
                    $this->frame($tui);
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
        // plugin.json declaring `Acme\\Tools`, and a class under its own src/
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

    /** A project plugin directory: an entry file, and a manifest when $requires is given. */
    private function projectPlugin(TempProject $project, string $name, array $requires = []): void
    {
        $dir = $project->path('.porta/plugins/'.$name);
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', data: $this->namedPlugin($name, ucfirst($name).' Run'));
        if ([] !== $requires) {
            file_put_contents($dir.'/plugin.json', data: (string) json_encode(['requires' => $requires]));
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
}
