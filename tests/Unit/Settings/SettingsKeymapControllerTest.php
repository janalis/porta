<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\KeyDispatch;
use Porta\Config\LiveKeybindings;
use Porta\Config\PortaJsonWriter;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsKeymapController;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsKeymapControllerTest extends TestCase
{
    use StatusReads;

    private LiveKeybindings $live;
    private MenuBarWidget $menuBar;
    private ActionRegistry $actions;
    private PluginHost $host;
    private CommandRegistry $commands;

    private function controller(string $rootDir, StatusBarWidget $status, Keybindings $boot, ?EditorPanes $panes = null): SettingsKeymapController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $this->host = new PluginHost(IdeConfig::load($rootDir), new EventDispatcher(), $rootDir);
        $this->actions = new ActionRegistry($this->host->manager());
        $this->live = new LiveKeybindings(new KeybindingsFactory($this->host, IdeConfig::load($rootDir), $this->actions), $boot, $rootDir);

        $this->menuBar = new MenuBarWidget();
        $this->commands = new CommandRegistry();
        $rebuilder = new MenuRebuilder(
            $this->menuBar,
            $this->commands,
            new CodeEditorWidget(),
            $this->live,
            new Translator('en'),
            new ViewRegistry(),
            new PluginManager(new EventDispatcher(), new PluginSettings($rootDir, [])),
            new MenuContributions(),
        );

        return new SettingsKeymapController(
            $this->actions,
            $this->commands,
            new CodeEditorWidget(),
            $panes ?? new EditorPanes(),
            $this->live,
            $rebuilder,
            new Notifier($status, $tui),
            $rootDir,
            new Translator('en'),
            new PortaJsonWriter(),
        );
    }

    public function testRowsResolveEditorFirstThenIdeBindings(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings(['ide_problems' => ['ctrl+6']]));

        $rows = $controller->rows();
        $byAction = array_column($rows, null, 'action');

        $this->assertSame(\count(\Porta\Config\ActionCatalog::ACTIONS), \count($rows), 'one row per catalog action — no plugin registered any here');
        $this->assertSame('ctrl+d', $byAction['duplicate_line']->binding, 'editor binding wins');
        $this->assertSame('Duplicate Line or Selection', $byAction['duplicate_line']->label);
        $this->assertSame('ctrl+6', $byAction['ide_problems']->binding, 'ide binding fills in when the editor has none');
    }

    public function testRebindPersistsAppliesLiveAndRebakesTheMenus(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $parser = $this->live->current()->getParser();
        // What FileCommandProvider registers: save is a key-bound COMMAND, so
        // InputDispatcher matches it against the rebuilt map before any widget
        // sees the key. That is what makes this rebind live.
        $this->commands->add('save', static function (): void {}, KeyDispatch::Bound);

        $controller->rebind('save', "\x1b[24~"); // F12

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['save']);
        $flash = $this->flashText($status);
        $this->assertStringContainsString('Rebound Save File to F12', $flash);
        $this->assertStringNotContainsString('applies on restart', $flash, 'save is IDE-scope: the rebuilt map dispatches it immediately');
        $this->assertSame(['f12'], $this->live->getBindings('save'), 'the live map re-merged the persisted rebind');
        $this->assertSame($parser, $this->live->current()->getParser(), 'the shared parser rode through');
    }

    public function testRebindWithAModifierChordPersistsTheFullToken(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings());

        $controller->rebind('save', "\x04"); // ctrl+d
        $this->assertSame(['ctrl+d'], IdeConfig::load($project->dir)->keymap['save']);
    }

    public function testAnUnparseableKeyFlashesAndPersistsNothing(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('save', "\x1b[17;2~"); // legacy shift+F6, not decodable here

        $this->assertStringContainsString('Unsupported key', $this->flashText($status));
        $this->assertFalse(is_file($project->path('.porta.json')));
    }

    public function testAFailedPersistIsFlagged(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json')); // block the write

        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $controller->rebind('save', 'a');

        $this->assertStringContainsString('(not saved)', $this->flashText($status));
    }

    public function testAPluginPanesActionGetsARowAndCanBeRebound(): void
    {
        // The whole user-visible loop this seam exists for: a plugin declares an
        // action, the page lists it with the plugin's own label, a rebind
        // persists under the bare id, and the re-merged live map binds it. Core
        // names none of it.
        $project = new TempProject(['.porta/plugins/hex/plugin.php' => <<<'PHP'
                <?php
                return new class('hex') extends \Porta\Plugin\Api\AbstractPlugin {
                    public function activate(\Porta\Plugin\Api\PluginApi $api): void
                    {
                        $api->registerActions(['hex_next_field' => 'Hex: Next Field']);
                    }
                };
                PHP]);
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        // What boot does when it resolves Keybindings, long before a settings
        // page can exist — the registry lists the plugins that have LOADED.
        $this->host->ensureLoaded();

        $byAction = array_column($controller->rows(), null, 'action');
        $this->assertArrayHasKey('hex_next_field', $byAction, 'the plugin\'s action is on the keymap page');
        $this->assertSame('Hex: Next Field', $byAction['hex_next_field']->label);
        $this->assertSame('', $byAction['hex_next_field']->binding, 'no default at IDE scope — the pane binds it');

        $controller->rebind('hex_next_field', "\x1b[24~"); // F12

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['hex_next_field'], 'persisted under the bare id');
        $this->assertStringContainsString('Rebound Hex: Next Field to F12', $this->flashText($status));
        $this->assertSame(['f12'], $this->live->getBindings('hex_next_field'), 'and it survived the factory\'s membership check');
    }

    public function testARowShowsThePaneKeyThatActuallyFires(): void
    {
        // The two halves meet here: the plugin DECLARES the action, its pane
        // BINDS it, and the page must show the key that fires. Resolving
        // through the code editor and the IDE map alone shows every plugin
        // pane's action as unbound — "no key" for a key that works — because a
        // pane's defaults live on the pane and nowhere else.
        $project = new TempProject(['.porta/plugins/demo/plugin.php' => <<<'PHP'
                <?php
                return new class('demo') extends \Porta\Plugin\Api\AbstractPlugin {
                    public function activate(\Porta\Plugin\Api\PluginApi $api): void
                    {
                        $api->registerActions(['test_pane_step' => 'Demo: Step']);
                    }
                };
                PHP]);
        $panes = new EditorPanes();
        $panes->register(new TestPane('demo'), static fn (): string => '');
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings(), $panes);
        $this->host->ensureLoaded();

        $byAction = array_column($controller->rows(), null, 'action');

        $this->assertSame('n', $byAction['test_pane_step']->binding, "the pane's own action shows the pane's key");
        $this->assertSame('ctrl+shift+n', $byAction['goto_file']->binding, "a core action the editor also binds keeps the editor's");
    }

    public function testAWidgetScopeRebindSaysItAppliesOnRestart(): void
    {
        // Two scopes, two truths. An IDE-scope action (save) is dispatched from
        // the map rebuild() swaps, so it is live. A widget-scope one is matched
        // against the keymap the WIDGET merged from the boot WidgetContext —
        // vendor-owned, final, and holding the instance handed to the Tui at
        // startup — so it lands on the next launch. Both are persisted; only
        // one takes effect now, and the flash has to say which.
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('duplicate_line', "\x1b[24~"); // F12

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['duplicate_line'], 'persisted either way');
        $this->assertStringContainsString('Rebound Duplicate Line or Selection to F12 · applies on restart', $this->flashText($status));
    }

    public function testAPluginPanesRebindSaysTheSame(): void
    {
        // A pane's actions are widget-scope by construction — the pane binds
        // them — so a plugin author's rebind carries the note too. Nothing
        // declares it at IDE scope, and nothing can: registerActions() takes no
        // keys, deliberately.
        $project = new TempProject(['.porta/plugins/hex/plugin.php' => <<<'PHP'
                <?php
                return new class('hex') extends \Porta\Plugin\Api\AbstractPlugin {
                    public function activate(\Porta\Plugin\Api\PluginApi $api): void
                    {
                        $api->registerActions(['hex_next_field' => 'Hex: Next Field']);
                    }
                };
                PHP]);
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $this->host->ensureLoaded();

        $controller->rebind('hex_next_field', "\x1b[24~");

        $this->assertStringContainsString('Rebound Hex: Next Field to F12 · applies on restart', $this->flashText($status));
    }
}
