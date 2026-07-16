<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\SettingsPluginsController;
use Porta\Config\SettingsRowKind;
use Porta\I18n\Translator;
use Porta\Plugin\PluginInstaller;
use Porta\Plugin\PluginSettings;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsPluginsControllerTest extends TestCase
{
    private TempProject $project;
    private TempProject $global;
    private StatusBarWidget $status;
    private FakePluginLifecycle $lifecycle;
    private ?string $embeddedDir = null;
    private PluginSettings $pluginSettings;

    private function controller(): SettingsPluginsController
    {
        $this->project = new TempProject();
        $this->global = new TempProject();
        $this->status = new StatusBarWidget();
        $this->lifecycle = new FakePluginLifecycle();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));

        $this->pluginSettings = new PluginSettings($this->project->dir, []);

        return new SettingsPluginsController(
            new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $this->embeddedDir),
            $this->pluginSettings,
            new Notifier($this->status, $tui),
            new Translator('en'),
            $this->lifecycle,
        );
    }

    private function seedPlugin(TempProject $base, string $name): void
    {
        mkdir($base->dir.'/.porta/plugins/'.$name, permissions: 0o777, recursive: true);
        file_put_contents($base->dir.'/.porta/plugins/'.$name.'/plugin.php', data: "<?php\n");
    }

    /** A plugin source dir outside the scope bases. */
    private function sourceDir(string $name): string
    {
        $dir = $this->project->dir.'/src/'.$name;
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', data: "<?php\n");

        return $dir;
    }

    private function flash(): string
    {
        return $this->status->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
    }

    public function testRowsShapeScopeChoiceInstallFieldAndRemoveButtons(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'local');
        $this->seedPlugin($this->global, 'shared');

        $rows = $controller->rows();
        $this->assertSame(['pluginScope', 'pluginInstall', 'pluginRemove:project:local', 'pluginRemove:global:shared'], array_column($rows, 'key'));
        $this->assertSame([SettingsRowKind::Choice, SettingsRowKind::Text, SettingsRowKind::Action, SettingsRowKind::Action], array_column($rows, 'kind'));
        $this->assertSame('project', $rows[0]->value);
        $this->assertSame(['project', 'global'], $rows[0]->options);
        $this->assertSame('This project', $rows[0]->optionLabels['project'] ?? null);
        $this->assertSame('git URL or local path', $rows[1]->hint);
        $this->assertSame('local (project)', $rows[2]->label);
        $this->assertSame('shared (global)', $rows[3]->label);
        $this->assertSame('Remove', $rows[3]->value);
    }

    public function testEmbeddedPluginsListAsBuiltInWithoutARemoveButton(): void
    {
        $embedded = new TempProject(['bundled/plugin.php' => "<?php\n"]);
        $this->embeddedDir = $embedded->dir;
        $controller = $this->controller();

        $rows = $controller->rows();
        $this->assertSame('pluginInfo:bundled', $rows[2]->key);
        $this->assertSame('bundled (built-in)', $rows[2]->label);
        $this->assertSame('Built-in', $rows[2]->value);

        $controller->change('pluginInfo:bundled', 'Built-in'); // inert
        $this->assertSame([], $this->lifecycle->deactivated);
        $this->assertDirectoryExists($embedded->dir.'/bundled');
        $this->embeddedDir = null;
    }

    public function testRemovalDeactivatesLive(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'demo');

        $controller->change('pluginRemove:project:demo', 'Remove');
        $controller->change('pluginRemove:project:demo', 'Remove');
        $this->assertSame(['demo'], $this->lifecycle->deactivated, 'a successful removal deactivates the plugin live');
    }

    public function testPluginSettingsPageRowsAndChanges(): void
    {
        $controller = $this->controller();
        $this->lifecycle->names = ['demo'];
        $this->assertTrue($controller->hasPlugin('demo'));
        $this->assertFalse($controller->hasPlugin('other'));

        $rows = $controller->pluginRows('demo');
        $this->assertCount(1, $rows, 'no registered settings → just the Enabled toggle');
        $this->assertSame('pluginEnabled', $rows[0]->key);
        $this->assertTrue($rows[0]->value);

        $this->pluginSettings->registerFor('demo', [
            ['key' => 'binary', 'label' => 'Binary', 'kind' => 'text', 'default' => '', 'options' => null, 'hint' => null],
        ]);
        $rows = $controller->pluginRows('demo');
        $this->assertSame(['pluginEnabled', 'pluginSetting:binary'], array_column($rows, 'key'));

        $controller->changePlugin('demo', 'pluginSetting:binary', '/opt/x');
        $controller->changePlugin('demo', 'someOtherKey', 'ignored');
        $this->assertSame('/opt/x', $this->pluginSettings->value('demo', 'binary'));
    }

    public function testTheEnabledToggleRoutesToSetEnabledAndReflectsDisabledState(): void
    {
        $controller = $this->controller();
        $this->lifecycle->disabledNames = ['demo'];
        $this->assertFalse($controller->pluginRows('demo')[0]->value, 'a disabled plugin shows unchecked');

        $controller->changePlugin('demo', 'pluginEnabled', true);
        $controller->changePlugin('demo', 'pluginEnabled', false);
        $this->assertSame([['demo', true], ['demo', false]], $this->lifecycle->toggled);
    }

    public function testScopeChangeRoutesInstallsAndAnEmptyInstallIsANoOp(): void
    {
        $controller = $this->controller();
        $controller->change('pluginInstall', '   ');
        $this->assertSame(0, $this->lifecycle->refreshed, 'an empty source does nothing');

        $controller->change('pluginScope', 'global');
        $this->assertSame('global', $controller->rows()[0]->value);

        $controller->change('pluginInstall', $this->sourceDir('demo'));
        $this->assertSame([$this->global->dir.'/.porta/plugins/demo/plugin.php'], $this->lifecycle->activated, 'installed into the chosen scope and activated');
        $this->assertStringContainsString('Plugin installed — default keybindings apply on restart', $this->flash());
        $this->assertSame(1, $this->lifecycle->refreshed);
    }

    public function testInstallAndActivationErrorsFlashInstead(): void
    {
        $controller = $this->controller();
        $controller->change('pluginInstall', $this->project->dir.'/missing');
        $this->assertStringContainsString('git is not available', $this->flash(), 'a nonexistent path falls through to the clone branch');
        $this->assertSame([], $this->lifecycle->activated);
        $this->assertSame(1, $this->lifecycle->refreshed, 'the section still refreshes after a failure');

        $this->lifecycle->activateResult = 'Plugin "demo" failed to load: nope';
        $controller->change('pluginInstall', $this->sourceDir('demo'));
        $this->assertStringContainsString('Plugin "demo" failed to load: nope', $this->flash());
    }

    public function testRemovalNeedsTwoPressesAndDisarmsOnAnythingElse(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'demo');

        $controller->change('pluginRemove:project:demo', 'Remove');
        $this->assertStringContainsString('Press Enter again to remove "demo"', $this->flash());
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo');

        $controller->change('pluginScope', 'global'); // anything else disarms
        $controller->change('pluginRemove:project:demo', 'Remove');
        $this->assertStringContainsString('Press Enter again to remove "demo"', $this->flash());
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo');

        $controller->change('pluginRemove:project:demo', 'Remove'); // the confirming press
        $this->assertStringContainsString('Plugin "demo" removed', $this->flash());
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/demo');
    }

    public function testArmingADifferentRowResetsTheConfirm(): void
    {
        $controller = $this->controller();
        $this->seedPlugin($this->project, 'one');
        $this->seedPlugin($this->project, 'two');

        $controller->change('pluginRemove:project:one', 'Remove');
        $controller->change('pluginRemove:project:two', 'Remove'); // re-arms onto "two"
        $this->assertStringContainsString('Press Enter again to remove "two"', $this->flash());
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/one');
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/two');

        $controller->change('pluginRemove:project:two', 'Remove');
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/two');
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/one', 'the first armed row was never confirmed');
    }
}
