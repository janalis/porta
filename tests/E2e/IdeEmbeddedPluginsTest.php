<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Plugins bundled with Porta (the embedded source, PORTA_EMBEDDED_PLUGINS):
 * they load in every project, sit in a Plugins menu between Code and Run with
 * per-item enablement, are listed as built-in (non-removable) but disableable,
 * and expose their registered settings on a per-plugin settings page.
 */
final class IdeEmbeddedPluginsTest extends TestCase
{
    use RunsIde;

    private string $originalPath;
    private string $originalEmbedded;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        $this->originalEmbedded = (string) getenv('PORTA_EMBEDDED_PLUGINS');
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_EMBEDDED_PLUGINS='.$this->originalEmbedded);
    }

    /** An embedded fixture plugin whose menu item is usable only once its binary setting is set. */
    private function embeddedFixture(): TempProject
    {
        return new TempProject(['bundled/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\PluginApi;
                use Porta\Plugin\PortaPlugin;

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

    /** @return list<\Porta\Ui\Menu> */
    private function menus($tui): array
    {
        return new \ReflectionProperty($tui->getById('menu'), 'menus')->getValue($tui->getById('menu'));
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

                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $this->assertContains('Bundled Run', array_column($commands->paletteCommands(), 'label'), 'the embedded plugin activated');

                    // The manage screen lists it as built-in; actuating is inert.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $tui->handleInput("\x1b[B"); // Interface
                    $tui->handleInput("\x1b[B"); // Keybindings
                    $tui->handleInput("\x1b[B"); // Plugins
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
                    $tui->tick();
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
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('bundled:run');
                    $tui->tick();
                    $this->assertStringContainsString('bundled ran /opt/tool', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // Back to the plugin's page to disable it live: menu + palette
                    // die and the choice persists.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $tui->handleInput("\x1b[B"); // Interface
                    $tui->handleInput("\x1b[B"); // Keybindings
                    $tui->handleInput("\x1b[B"); // Plugins
                    $tui->handleInput("\x1b[B"); // bundled sub-item
                    $tui->handleInput("\t"); // nav → form
                    $tui->handleInput("\x1b[A"); // clamp to the Enabled row
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r"); // toggle off
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "bundled" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertNotContains('Plugins', array_column($this->menus($tui), 'title'), 'the only plugin gone → no Plugins menu');
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $this->assertNotContains('Bundled Run', array_column($commands->paletteCommands(), 'label'));
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertArrayNotHasKey('enabledPlugins', $config, 'a bundled plugin disables by leaving the opt-in list');

                    // And re-enable it live from the same page.
                    $tui->handleInput("\r");
                    $tui->tick();
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
            use Porta\\Plugin\\PluginApi;
            use Porta\\Plugin\\PortaPlugin;

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
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $labels = array_column($commands->paletteCommands(), 'label');
                    $this->assertContains('Project Run', $labels, 'the project copy shadows the embedded one');
                    $this->assertNotContains('Bundled Run', $labels);

                    // Remove the project override: the embedded copy reactivates live.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $tui->handleInput("\t");
                    $tui->handleInput("\x1b[B"); // Interface
                    $tui->handleInput("\x1b[B"); // Keybindings
                    $tui->handleInput("\x1b[B"); // Plugins
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
                    $tui->tick();
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
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $this->assertNotContains('Bundled Run', array_column($commands->paletteCommands(), 'label'), 'skipped at boot');
                    $this->assertNotContains('Plugins', array_column($this->menus($tui), 'title'));

                    // Its settings page is still reachable, showing the unchecked toggle.
                    $commands->run('ide_settings');
                    $tui->handleInput("\t");
                    $tui->handleInput("\x1b[B"); // Interface
                    $tui->handleInput("\x1b[B"); // Keybindings
                    $tui->handleInput("\x1b[B"); // Plugins
                    $tui->handleInput("\x1b[B"); // bundled sub-item
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('[ ] Enabled', $screen, 'listed and re-enableable');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
