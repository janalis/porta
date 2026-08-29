<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

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

    public function testAGlobalWriteLandsInTheUsersFileAndLeavesTheProjectAlone(): void
    {
        putenv('PATH=/nonexistent');
        $this->home = new TempProject([]);
        $this->project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);
        putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Global);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $tui->getById('settings-nav');
                    \assert($nav instanceof SettingsNavWidget, description: 'the settings view owns the nav sidebar');
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function ($tui, $terminal): void {
                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the form is the content pane');
                    $tui->setFocus($form);

                    $this->assertStringContainsString(
                        '~/.porta/config.json',
                        $this->settingRow($form->render(new \Symfony\Component\Tui\Render\RenderContext(110, 22)), 'Save changes to'),
                        'the screen says where this is about to go',
                    );

                    $this->selectSettingRow($form, 'Sticky scroll');
                    $tui->handleInput("\r"); // toggle it off its default
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal, $ide): void {
                    $global = $this->home->path('.porta/config.json');
                    $this->assertFileExists($global, 'the global file was created on first write');

                    $written = json_decode((string) file_get_contents($global), associative: true);
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
        putenv('PATH=/nonexistent');
        $this->home = new TempProject([]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => json_encode(['settings' => ['stickyScroll' => true]], \JSON_THROW_ON_ERROR),
        ]);
        putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Global);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $tui->getById('settings-nav');
                    \assert($nav instanceof SettingsNavWidget, description: 'the settings view owns the nav sidebar');
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function ($tui, $terminal): void {
                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the form is the content pane');
                    $tui->setFocus($form);
                    $this->selectSettingRow($form, 'Sticky scroll');
                    $tui->handleInput("\r");
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal, $ide): void {
                    $global = $this->home->path('.porta/config.json');
                    $written = json_decode((string) file_get_contents($global), associative: true);
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
                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the form is the content pane');
                    $rows = array_values(array_filter(
                        $form->render(new \Symfony\Component\Tui\Render\RenderContext(110, 22)),
                        static fn (string $line): bool => str_contains(AnsiUtils::stripAnsiCodes($line), '['),
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
        putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => json_encode(
            ['settings' => ['stickyScroll' => false]],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => json_encode(['settings' => ['stickyScroll' => true]], \JSON_THROW_ON_ERROR),
        ]);
        putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                // No scope change: RunsIde pins every scenario to the project.
                300 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $tui->getById('settings-nav');
                    \assert($nav instanceof SettingsNavWidget, description: 'the settings view owns the nav sidebar');
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function ($tui, $terminal): void {
                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the form is the content pane');
                    $tui->setFocus($form);
                    $this->selectSettingRow($form, 'Sticky scroll');
                    $tui->handleInput("\r");
                    $this->frame($tui);
                },
                700 => function ($tui, $terminal, $ide): void {
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
}
