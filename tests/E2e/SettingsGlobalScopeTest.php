<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Widget\Settings\KeybindingsListWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

/**
 * The round trip the global scope exists for: change a setting, it lands in
 * the USER's file, and the project's stays untouched.
 *
 * Every piece of this had a test and the whole of it did not. The writer knew
 * about `ConfigScope::Global`, the merge could read a global file someone had
 * written by hand, and the scope row could be stepped — but nothing drove a
 * real setting through the real screen into `~/.porta/config.json`, which is
 * the entire point of the feature.
 *
 * `RunsIde` pins every scenario to the project scope, because a global write
 * otherwise lands in a home shared with the whole suite. This test is the one
 * that means to write there, so it says so — which is also the documented way
 * to opt back in.
 */
final class SettingsGlobalScopeTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use PreservesPortaHome;
    use ReadsSettingsForm;
    use RunsIde;
    use ScreenReads;

    /** Held so the destructors do not remove the dirs mid-test. */
    private TempProject $home;

    private TempProject $project;

    /**
     * The two steps both global-scope sticky-scroll cases share: open Settings
     * in Global scope on the Interface section, focus the form, land on the
     * Sticky scroll row, and press $key on it.
     *
     * Spread into the caller's step list rather than merged, so each test still
     * reads as one array of numbered steps and its own 700 stays where the eye
     * expects it.
     *
     * @return array<int, \Closure>
     */
    private function reachTheStickyScrollRow(string $key): array
    {
        return [
            300 => function (Tui $tui, $terminal, Ide $ide): void {
                Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Global);
                Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                $nav = $this->settingsNav($tui);
                $this->selectSettingsSection($nav, 'interface');
                $this->frame($tui);
            },
            500 => function (Tui $tui, $terminal) use ($key): void {
                $form = $this->settingsForm($tui);
                $tui->setFocus($form);
                $this->selectSettingRow($form, 'Sticky scroll');
                $tui->handleInput($key);
                $this->frame($tui);
            },
        ];
    }

    public function testAGlobalWriteLandsInTheUsersFileAndLeavesTheProjectAlone(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject([]);
        $this->project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Global);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, $terminal): void {
                    $form = $this->settingsForm($tui);
                    $tui->setFocus($form);

                    $this->assertStringContainsString(
                        '~/.porta/config.json',
                        $this->settingRow($form->render(new RenderContext(110, 22)), 'Save changes to'),
                        'the screen says where this is about to go',
                    );

                    $this->selectSettingRow($form, 'Sticky scroll');
                    $tui->handleInput("\r"); // toggle it off its default
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal, Ide $ide): void {
                    $global = $this->home->path('.porta/config.json');
                    $this->assertFileExists($global, 'the global file was created on first write');

                    $written = \json_decode((string) \file_get_contents($global), associative: true);
                    $this->assertIsArray($written);
                    $this->assertFalse(
                        $written['settings']['stickyScroll'] ?? null,
                        'the change landed in the user file',
                    );

                    $this->assertFileDoesNotExist(
                        $this->project->path('.porta.json'),
                        'and the project file was never touched — that is the whole point',
                    );

                    // Read back through the real loader: the merge sees it.
                    $this->assertFalse(
                        IdeConfig::load($this->project->dir)->settings['stickyScroll'],
                        'and a fresh load picks it up through the global layer',
                    );

                    // Nothing shadows this one, so nothing to warn about — a
                    // warning on every global save would be noise that trains
                    // people past the case that matters.
                    $this->assertStringNotContainsString(
                        'overrides it in this project',
                        Toasts::last($ide),
                        'an unshadowed global save is not warned about',
                    );
                },
            ],
        );
    }

    /**
     * The project layer still wins over what was just written globally.
     *
     * Writing to the user file while a project overrides that key would show a
     * change that does not take effect, which is worse than refusing it — so
     * the row says `overrides global` and the effective value stays the
     * project's.
     */
    public function testAProjectOverrideSurvivesAGlobalWriteAndSaysSo(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject([]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(['settings' => ['stickyScroll' => true]], \JSON_THROW_ON_ERROR),
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                ...$this->reachTheStickyScrollRow("\r"),
                700 => function (Tui $tui, $terminal, Ide $ide): void {
                    $global = $this->home->path('.porta/config.json');
                    $written = \json_decode((string) \file_get_contents($global), associative: true);
                    $this->assertIsArray($written);
                    $this->assertArrayHasKey('stickyScroll', $written['settings'] ?? [], 'the global write happened');

                    $this->assertTrue(
                        IdeConfig::load($this->project->dir)->settings['stickyScroll'],
                        'but the project layer still decides the effective value',
                    );

                    // The row, not the toast. A flash saying "Sticky scroll off"
                    // is composited INTO the form's own render — the form hosts
                    // floating surfaces — so a label-addressed read can land on
                    // the notification instead of the setting. The checkbox is
                    // what tells them apart.
                    $form = $this->settingsForm($tui);
                    $rows = \array_values(\array_filter(
                        $form->render(new RenderContext(110, 22)),
                        static fn (string $line): bool => \str_contains(AnsiUtils::stripAnsiCodes($line), '['),
                    ));
                    $this->assertStringContainsString(
                        'overrides global',
                        $this->settingRow($rows, 'Sticky scroll'),
                        'and the row says why the value did not move',
                    );
                    // The tag carries the fact; the toast carries the
                    // consequence, at the moment the key was pressed.
                    $this->assertStringContainsString(
                        'overrides it in this project',
                        Toasts::last($ide),
                        'and the save said so out loud',
                    );
                },
            ],
        );
    }

    /**
     * Editing the PROJECT file for a key the global file also sets is the
     * ordinary two-layer case, and warning about it would be noise on a write
     * that does exactly what was asked.
     *
     * `shadowed` is true here too — both files set the key — so the scope is
     * what separates the two situations, and without this case a warning on
     * every shadowed write looks correct.
     */
    public function testEditingTheProjectFileOverAGlobalValueIsNotWarnedAbout(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            ['settings' => ['stickyScroll' => false]],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(['settings' => ['stickyScroll' => true]], \JSON_THROW_ON_ERROR),
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                // No scope change: RunsIde pins every scenario to the project.
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, $terminal): void {
                    $form = $this->settingsForm($tui);
                    $tui->setFocus($form);
                    $this->selectSettingRow($form, 'Sticky scroll');
                    $tui->handleInput("\r");
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal, Ide $ide): void {
                    $this->assertFalse(
                        IdeConfig::load($this->project->dir)->settings['stickyScroll'],
                        'the project value moved, which is what was asked',
                    );
                    $this->assertStringNotContainsString(
                        'overrides it in this project',
                        Toasts::last($ide),
                        'and the ordinary case is not warned about',
                    );
                },
            ],
        );
    }

    /**
     * Reset is a write too, and the same project override swallows it.
     *
     * Pressing `r` on a shadowed row in the global scope removes the user's
     * value and changes nothing anyone can see, because the project goes on
     * deciding it. The two write paths skipped this warning for exactly one
     * commit, which is what this pins.
     */
    public function testClearingAShadowedGlobalValueSaysThatToo(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            ['settings' => ['stickyScroll' => false]],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(['settings' => ['stickyScroll' => true]], \JSON_THROW_ON_ERROR),
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                // 'r' resets the row to its default rather than toggling it.
                ...$this->reachTheStickyScrollRow('r'),
                700 => function ($tui, $terminal, Ide $ide): void {
                    $written = \json_decode((string) \file_get_contents($this->home->path('.porta/config.json')), associative: true);
                    $this->assertIsArray($written);
                    $this->assertArrayNotHasKey(
                        'stickyScroll',
                        $written['settings'] ?? [],
                        'the key was removed from the user file rather than set to the default',
                    );
                    $this->assertStringContainsString(
                        'still overrides it here',
                        Toasts::last($ide),
                        'and the reset said the project still decides this',
                    );
                },
            ],
        );
    }

    /**
     * A plugin the user turned off globally cannot be turned back on for one
     * project, and the toggle has to say so rather than appear to do nothing.
     *
     * `disabledPlugins` unions across the layers on purpose — both files
     * saying "not this one" have to agree — so pressing Enable here writes
     * correctly and changes nothing. That is the design; the silence was not.
     */
    public function testEnablingAPluginTheGlobalFileDisablesSaysWhyItStaysOff(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            ['disabledPlugins' => ['noisy']],
            \JSON_THROW_ON_ERROR,
        )]);
        // An INSTALLED plugin: `disabledPlugins` is the opt-OUT list, which is
        // what project and global plugins use. Bundled ones are opt-in through
        // `enabledPlugins` and never reach this path.
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta/plugins/noisy/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;

                return new class implements PortaPlugin {
                    public function name(): string { return 'noisy'; }
                    public function activate(PluginApi $api): void {}
                };
                PHP,
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                // Project scope, which is where a user would be doing this.
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, PluginLifecycle::class)->enable('noisy');
                    $this->frame($tui);
                },
                500 => function ($tui, $terminal, Ide $ide): void {
                    $this->assertContains(
                        'noisy',
                        IdeConfig::load($this->project->dir)->disabledPlugins,
                        'the union keeps it off, which is the documented rule',
                    );
                    $shown = \implode(' | ', Toasts::all($ide));
                    $this->assertStringContainsString(
                        'stays off',
                        $shown,
                        'the toggle said so instead of appearing to work',
                    );
                    $this->assertStringContainsString(
                        '~/.porta/config.json',
                        $shown,
                        'and named the file that is keeping it off',
                    );
                    // The whole point: "enabled" must never be on screen for a
                    // toggle that did not enable anything.
                    $this->assertStringNotContainsString(
                        'enabled',
                        $shown,
                        'and never claimed success',
                    );
                    $this->assertFileDoesNotExist(
                        $this->project->path('.porta.json'),
                        'and no empty config file was left behind for it',
                    );
                },
            ],
        );
    }

    /**
     * The language page's LSP and debug rows carry provenance too.
     *
     * They were the three rows on that page without it — the indent rows had
     * tags and these did not, so the page answered "where did this come from"
     * for some of its own rows and stayed silent for the rest. A user cannot
     * tell "nothing set this" from "this row does not do tags".
     */
    public function testTheLspAndDebugRowsSayWhereTheirValuesCameFrom(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            [
                'lsp' => ['php' => 'global-ls --stdio'],
                'languages' => ['php' => ['debug' => ['adapter' => 'global-dap']]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'lang:php');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, $terminal): void {
                    $form = $this->settingsForm($tui);
                    $rows = $form->render(new RenderContext(120, 22));

                    $this->assertStringContainsString('from global', $this->settingRow($rows, 'LSP server'));
                    $this->assertStringContainsString('from global', $this->settingRow($rows, 'Debug adapter'));
                    $this->assertStringNotContainsString(
                        'from global',
                        $this->settingRow($rows, 'Launch config'),
                        'and a row nothing set stays untagged',
                    );
                },
            ],
        );
    }

    /**
     * A rebind the other config file overrules stops claiming it happened.
     *
     * `keymap` merges per ACTION — a file naming an action replaces its whole
     * chord list, never adds to it — so rebinding globally while the project
     * binds the same action writes correctly and changes nothing. "Rebound X
     * to Y" would be the only thing on screen, and false.
     */
    public function testARebindTheProjectOverrulesSaysSoInsteadOfClaimingSuccess(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject([]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(['keymap' => ['save_all' => ['ctrl+w']]], \JSON_THROW_ON_ERROR),
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Global);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'keybindings');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, $terminal): void {
                    // Through the real list: walk to the action, Enter to arm
                    // the capture, then the key it should take.
                    $list = $tui->getById('settings-keys');
                    $this->assertInstanceOf(KeybindingsListWidget::class, $list, 'the keybindings page owns the list');
                    $tui->setFocus($list);
                    for ($i = 0; $i < 200; ++$i) {
                        if ('save_all' === (Probe::get($list, 'rows')[Probe::object($list, 'list')->selected()] ?? null)?->action) {
                            break;
                        }
                        $list->handleInput("\x1b[B");
                    }
                    $list->handleInput("\r"); // arm the capture
                    $list->handleInput("\x1b[15~"); // F5
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal, Ide $ide): void {
                    $shown = \implode(' | ', Toasts::all($ide));
                    $this->assertStringContainsString('keeps its binding', $shown, 'the rebind said it did not take');
                    $this->assertStringContainsString('.porta.json', $shown, 'and named the file that binds it');
                    $this->assertStringNotContainsString('Rebound', $shown, 'and never claimed success');

                    $this->assertSame(
                        ['ctrl+w'],
                        IdeConfig::load($this->project->dir)->keymap['save_all'] ?? null,
                        'the project layer still decides the binding',
                    );
                },
            ],
        );
    }

    /**
     * The scope chosen on the settings screen also decides where a PLUGIN's
     * settings are written.
     *
     * `PluginSettings` is hand-constructed by `PluginHost` and takes the scope
     * as a TRAILING OPTIONAL argument, so if autowiring ever stopped filling it
     * the object would quietly build a second `SettingsScope` of its own. There
     * would be no error: the screen would say one file and plugin settings
     * would go to another, which is the kind of divergence only a round trip
     * catches.
     *
     * Asserted behaviourally rather than by instance identity — identity is how
     * it happens to work today, the file it lands in is what was promised.
     */
    public function testAPluginSettingFollowsTheScopeTheScreenShows(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject([]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            // A settings block ON PURPOSE: SettingsScope defaults a project
            // that has one to Project. Without it a scope built by mistake
            // would default to Global too, both paths would write to the same
            // file, and this test would pass whatever the wiring did.
            '.porta.json' => \json_encode(['settings' => ['theme' => 'dark']], \JSON_THROW_ON_ERROR),
            '.porta/plugins/noisy/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;

                return new class implements PortaPlugin {
                    public function name(): string { return 'noisy'; }
                    public function activate(PluginApi $api): void
                    {
                        $api->registerSetting('volume', 'Klaxon volume', 'int', 3, min: 1, max: 9);
                    }
                };
                PHP,
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Global);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'plugin:noisy');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, $terminal): void {
                    $form = $this->settingsForm($tui);
                    $tui->setFocus($form);
                    $this->selectSettingRow($form, 'Klaxon volume');
                    $tui->handleInput("\x1b[C"); // step it up
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal): void {
                    $global = $this->home->path('.porta/config.json');
                    $this->assertFileExists($global, 'the plugin setting went to the user file');

                    $written = \json_decode((string) \file_get_contents($global), associative: true);
                    $this->assertIsArray($written);
                    $this->assertSame(4, $written['plugins']['noisy']['volume'] ?? null, 'stepped and stored there');

                    $project = \json_decode((string) \file_get_contents($this->project->path('.porta.json')), associative: true);
                    $this->assertIsArray($project);
                    $this->assertArrayNotHasKey(
                        'plugins',
                        $project,
                        'and not into the project the screen said it would leave alone',
                    );
                },
            ],
        );
    }
}
