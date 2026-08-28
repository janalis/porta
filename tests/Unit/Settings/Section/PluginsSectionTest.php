<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings\Section;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\ToolInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\Section\PluginsSection;
use Porta\Settings\SettingsPluginsController;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PluginsSectionTest extends TestCase
{
    public function testChangeOnTheTopLevelPluginsSectionRoutesOnlyToChangeNotChangePlugin(): void
    {
        $project = new TempProject();
        $lifecycle = new FakePluginLifecycle();
        $pluginSettings = new PluginSettings($project->dir, []);
        $plugins = new SettingsPluginsController(
            new PluginInstaller($project->dir, $project->dir, new Translator('en'), null, null, null),
            $pluginSettings,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            $lifecycle,
            new PluginManager(new EventDispatcher(), $pluginSettings),
            new ToolInstaller($project->dir, []),
        );
        $section = new PluginsSection($plugins);

        // 'pluginEnabled' is a changePlugin() key, not a change() key — if the
        // top-level 'plugins' section's change() ever fell through into
        // changePlugin(), this would toggle a bogus '' plugin.
        $section->change('plugins', 'pluginEnabled', true);

        $this->assertSame([], $lifecycle->toggled, 'the top-level section never reaches changePlugin()');
    }

    /**
     * The Plugins index has nothing resettable: a mode switch, an install
     * field and a list of buttons. Only a per-plugin page reaches the
     * controller.
     */
    public function testResetOnTheTopLevelPluginsSectionIsInert(): void
    {
        $project = new TempProject();
        $lifecycle = new FakePluginLifecycle();
        $pluginSettings = new PluginSettings($project->dir, ['demo' => ['binary' => '/custom']]);
        $pluginSettings->registerFor('demo', [
            ['key' => 'binary', 'label' => 'Binary', 'kind' => 'text', 'default' => '/stock', 'options' => null, 'hint' => null, 'min' => null, 'max' => null, 'step' => null],
        ]);
        $section = new PluginsSection(new SettingsPluginsController(
            new PluginInstaller($project->dir, $project->dir, new Translator('en'), null, null, null),
            $pluginSettings,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            $lifecycle,
            new PluginManager(new EventDispatcher(), $pluginSettings),
            new ToolInstaller($project->dir, []),
        ));

        $section->reset('plugins', 'pluginSetting:binary');
        $this->assertSame('/custom', $pluginSettings->value('demo', 'binary'), 'the index page resets nothing');

        $section->reset('plugin:demo', 'pluginSetting:binary');
        $this->assertSame('/stock', $pluginSettings->value('demo', 'binary'), 'a plugin page does');
    }
}
