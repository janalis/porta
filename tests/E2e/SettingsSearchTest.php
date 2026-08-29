<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Settings\SettingsSearchEntry;
use Porta\Settings\SettingsSearchIndex;
use Porta\Settings\SettingsSearchLauncher;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\Settings\SettingsFormWidget;

/**
 * "Find Setting", end to end: Ctrl+F inside the settings view opens the
 * centered picker, and picking a hit lands on that setting's row.
 *
 * Driven through real keystrokes rather than the launcher, because the whole
 * risk of this feature is in the hops between them — the widget's keymap, the
 * command event, the overlay that has to paint over a full-screen view, and the
 * open-then-dispatch order that decides whether the row survives the page
 * switch.
 */
final class SettingsSearchTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use ReadsSettingsForm;
    use RunsIde;
    use ScreenReads;

    public function testCtrlFFindsASettingOnAnotherPageAndLandsOnItsRow(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                500 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Inline diagnostics'), 'the view opens on General');
                    $this->assertNull($this->rowOf($grid, 'Sticky scroll'), 'and Interface rows are elsewhere');

                    $tui->handleInput("\x06"); // ctrl+f
                },
                700 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Find Setting'),
                        'the picker paints over the full-screen settings view',
                    );
                    foreach (str_split('sticky') as $char) {
                        $tui->handleInput($char);
                    }
                },
                900 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Sticky scroll'),
                        'the hit is listed while its page is not the one showing',
                    );
                    $tui->handleInput("\r");
                },
                1100 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNull($this->rowOf($grid, 'Find Setting'), 'the picker closed');
                    $this->assertNotNull($this->rowOf($grid, 'Sticky scroll'), 'the Interface page is showing');

                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the settings form is the content pane while the view is open');
                    $this->assertStringContainsString(
                        'Sticky scroll',
                        $this->selectedSettingRow($form),
                        'and the selection is on the picked row, not merely on its page',
                    );
                },
            ],
        );
    }

    /**
     * The same picker with the settings view CLOSED, which is the case that
     * makes the launcher's open-then-dispatch order matter:
     * `SettingsViewController::open()` resets to the General page, so a view
     * opened after the event would throw away the section the event carried
     * and land on General every time.
     *
     * Reached through the launcher rather than a keystroke because Ctrl+F is a
     * settings-widget binding and there is no widget to press it on here.
     */
    public function testPickingWithTheViewClosedOpensItOnTheSettingsOwnPage(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, SettingsSearchLauncher::class)->open();
                },
                500 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Find Setting'),
                        'the picker opens over the editor',
                    );
                    foreach (str_split('sticky') as $char) {
                        $tui->handleInput($char);
                    }
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\r");
                },
                900 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Sticky scroll'), 'the settings view opened on Interface');
                    $this->assertNull($this->rowOf($grid, 'Inline diagnostics'), 'NOT on General, which is where open() resets to');

                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the settings form is the content pane while the view is open');
                    $this->assertStringContainsString('Sticky scroll', $this->selectedSettingRow($form), 'landed on the row');
                },
            ],
        );
    }

    /**
     * The same source inside Search Everywhere, which is where you look when
     * you do not already know that the thing you want is a setting.
     *
     * Ordered last of the six categories, so a settings hit can never displace
     * a file — the query here is one no file in the project answers.
     */
    public function testSearchEverywhereFindsSettingsToo(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_search_everywhere');
                },
                500 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Search Everywhere'),
                        'the aggregate picker opened',
                    );
                    foreach (str_split('sticky scroll') as $char) {
                        $tui->handleInput($char);
                    }
                },
                800 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Settings'), 'under a Settings group header');
                    $this->assertNotNull($this->rowOf($grid, 'Sticky scroll'), 'the setting is a hit');
                    $tui->handleInput("\r");
                },
                1000 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Sticky scroll'), 'picking it opened the settings view on Interface');

                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the settings form is the content pane while the view is open');
                    $this->assertStringContainsString('Sticky scroll', $this->selectedSettingRow($form), 'landed on the row');
                },
            ],
        );
    }

    /**
     * The scope chooser is not a search result.
     *
     * It leads every page that writes a file, so indexing it put one identical
     * hit per page — nineteen of them — in front of whatever the user was
     * actually looking for. It is also the one row a search cannot usefully
     * take you to: it is already on whichever page you are on.
     *
     * Its own words are the ones that expose this, which is why the query is
     * "save" and not something neutral.
     */
    public function testTheScopeChooserDoesNotFloodResultsWithItself(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $index = Services::resolve($ide, SettingsSearchIndex::class);
                    $labels = array_map(
                        static fn (SettingsSearchEntry $e): string => $e->label,
                        $index->search('save', 60),
                    );

                    $this->assertNotContains('Save changes to', $labels, 'the chooser is not indexed');
                    $this->assertContains('Format on save (LSP)', $labels, 'and a real setting named for the query is findable');
                },
            ],
        );
    }

    /**
     * A search result on a PLUGIN's page lands there.
     *
     * Plugin pages are the only ones whose section id is minted at runtime
     * (`plugin:<name>`), so the reveal has to carry an id nothing declared
     * ahead of time and resolve it against a section that claims a prefix
     * rather than a fixed string. Every other page in the index is a constant.
     */
    public function testASearchHitOnAPluginsOwnPageRevealsItThere(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
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

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $hits = Services::resolve($ide, SettingsSearchIndex::class)->search('klaxon', 10);

                    $this->assertNotEmpty($hits, 'a plugin setting is in the index');
                    $this->assertSame('Klaxon volume', $hits[0]->label);
                    $this->assertSame('plugin:noisy', $hits[0]->section, 'addressed by the runtime-minted page id');

                    Services::resolve($ide, SettingsSearchLauncher::class)->open();
                    $tui->handleInput('klaxon');
                },
                500 => static function ($tui): void {
                    $tui->handleInput("\r"); // pick it
                },
                700 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Klaxon volume'), "the plugin's own page is showing");

                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget, description: 'the settings form is the content pane');
                    $this->assertStringContainsString(
                        'Klaxon volume',
                        $this->selectedSettingRow($form),
                        'with the picked row selected',
                    );
                },
            ],
        );
    }
}
