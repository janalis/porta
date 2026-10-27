<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
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
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPane;
use Porta\Ui\ShortcutFormatter;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewCommandGate;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsKeymapControllerTest extends TestCase
{
    use PreservesPortaHome;
    use StatusReads;

    private LiveKeybindings $live;
    /** Held so the destructor does not remove the global fixture mid-test. */
    private TempProject $home;
    private MenuBarWidget $menuBar;
    private ActionRegistry $actions;
    private PluginHost $host;
    private CommandRegistry $commands;

    private function controller(string $rootDir, StatusBarWidget $status, Keybindings $boot, ?EditorPanes $panes = null): SettingsKeymapController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $this->host = new PluginHost(IdeConfig::load($rootDir), new EventDispatcher(), $rootDir);
        $this->actions = new ActionRegistry($this->host->manager);
        $this->live = new LiveKeybindings(new KeybindingsFactory($this->host, IdeConfig::load($rootDir), $this->actions), $boot, $rootDir);

        $this->menuBar = new MenuBarWidget();
        $this->commands = new CommandRegistry();
        $rebuilder = new MenuRebuilder(
            $this->menuBar,
            $this->commands,
            new CodeEditorWidget(),
            $this->live,
            new Translator('en'),
            new ViewCommandGate(new ViewRegistry(), new MenuContributions()),
            new PluginManager(new EventDispatcher(), new PluginSettings($rootDir, [])),
            new MenuContributions(),
        );

        // Every assertion below reads the project's .porta.json back, so the
        // session scope is pinned to Project; SettingsScope defaults to Global.
        $scope = new SettingsScope(ConfigFiles::for($rootDir));
        $scope->set(ConfigScope::Project);

        return new SettingsKeymapController(
            $this->actions,
            $this->commands,
            new CodeEditorWidget(),
            $panes ?? new EditorPanes(),
            $this->live,
            $rebuilder,
            new Notifier($status, $tui),
            new Translator('en'),
            new PortaJsonWriter(ConfigFiles::for($rootDir)),
            $scope,
            ConfigFiles::for($rootDir),
        );
    }

    public function testRowsResolveEditorFirstThenIdeBindings(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings(['ide_problems' => ['ctrl+6']]));

        $rows = $controller->rows();
        $byAction = \array_column($rows, null, 'action');

        $this->assertCount(\count(ActionCatalog::ACTIONS), $rows, 'one row per catalog action — no plugin registered any here');
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

        $this->assertSame([], Probe::get($this->menuBar, 'menus'), 'the menu bar is unbuilt until something rebakes it');

        $controller->rebind('save', "\x1b[24~"); // F12

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['save']);
        $flash = $this->flashText($status);
        $this->assertStringContainsString('Rebound Save File to F12', $flash);
        $this->assertStringNotContainsString('applies on restart', $flash, 'save is IDE-scope: the rebuilt map dispatches it immediately');
        $this->assertSame(['f12'], $this->live->getBindings('save'), 'the live map re-merged the persisted rebind');
        $this->assertSame($parser, $this->live->current()->getParser(), 'the shared parser rode through');
        $this->assertNotSame([], Probe::get($this->menuBar, 'menus'), 'a rebind rebakes the menu bar');
    }

    /**
     * Binding an action to the key it ALREADY fires on writes nothing.
     *
     * This is the entry sitting in the user's own `.porta.json` —
     * `"cursor_left": ["left"]`, which is precisely the shipped default. An
     * override is not only a binding: it PINS the action against every future
     * change to that default, silently and for good. Writing one for a key that
     * already works buys nothing and costs that.
     */
    public function testBindingAnActionToTheKeyItAlreadyUsesWritesNothing(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        // Left is cursor_left's shipped editor default, and nothing overrides it.
        $controller->rebind('cursor_left', "\x1b[D");

        $this->assertSame([], IdeConfig::load($project->dir)->keymap, 'no override was created');
        $this->assertStringContainsString(
            'Move Caret Left already uses '.ShortcutFormatter::format('left'),
            $this->flashText($status),
            'and it says why, rather than reporting a save that did not happen',
        );
    }

    public function testTheKeyThatAlreadyFiresCountsWhicheverTierGivesIt(): void
    {
        // The editor tier answers for the case above; this is the IDE-level map,
        // which is where a plugin's or a command's key comes from. An override
        // restating one of those is the same redundant pin.
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings(['copy_path' => ['f12']]));

        $controller->rebind('copy_path', "\x1b[24~"); // F12, which it already fires on

        $this->assertSame([], IdeConfig::load($project->dir)->keymap, 'nothing was written');
        $this->assertStringContainsString('already uses F12', $this->flashText($status));
    }

    public function testRebindingAnActionThisFileAlreadyOverridesStillWrites(): void
    {
        // The other half is deliberately NOT done here: once an override exists,
        // rewriting it is an ordinary rebind, and Del is the documented way to
        // take it back — {@see testResetDropsTheOverrideAndRebuildsTheLiveMap}.
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings());
        $controller->rebind('copy_path', "\x1b[24~"); // F12

        $controller->rebind('copy_path', "\x1b[23~"); // F11

        $this->assertSame(['f11'], IdeConfig::load($project->dir)->keymap['copy_path']);
    }

    /**
     * The counterpart the page never had: a rebind could be made and never
     * unmade. Pressing Enter on a row and then any key wrote an override —
     * even one identical to the default — and nothing short of hand-editing
     * .porta.json could take it back.
     */
    public function testResetDropsTheOverrideAndRebuildsTheLiveMap(): void
    {
        $project = new TempProject();
        // An action the CATALOG gives a default key, so "back to the default"
        // is a key this test can name rather than an absence it has to trust.
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $controller->rebind('copy_path', "\x1b[24~"); // F12
        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['copy_path'], 'overridden first');
        $this->assertSame(['f12'], $this->live->getBindings('copy_path'), 'and live');

        $controller->resetBinding('copy_path');

        $this->assertArrayNotHasKey('copy_path', IdeConfig::load($project->dir)->keymap);
        $this->assertStringContainsString('Reset Copy Path to Ctrl+Shift+C', $this->flashText($status));
        $this->assertSame(['ctrl+shift+c'], $this->live->getBindings('copy_path'), 'the shipped default fires again');
    }

    /**
     * An override is not only a binding — it PINS the action against every
     * future change to the default. A file left holding `"keymap": {}` reads
     * like a decision and is one nobody made, so the block goes when its last
     * entry does.
     */
    public function testResettingTheLastOverrideTakesTheWholeKeymapBlock(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings());
        $controller->rebind('copy_path', "\x1b[24~");
        $controller->rebind('fold_all', "\x1b[23~"); // F11

        $controller->resetBinding('copy_path');
        $this->assertArrayHasKey('keymap', $this->configFile($project->dir), 'fold_all still holds it open');

        $controller->resetBinding('fold_all');
        $this->assertArrayNotHasKey('keymap', $this->configFile($project->dir));
    }

    /**
     * Removing the override is not the same as getting the default back.
     *
     * `keymap` merges per ACTION — a file naming an action replaces its whole
     * chord list — so dropping this file's line hands the action to the OTHER
     * file if that one binds it too. "Reset X to <default>" would then be the
     * only thing on screen and false, which is the same trap
     * {@see SettingsKeymapController::rebind()} already guards, told the same
     * way and with the same string.
     */
    public function testResettingWhatTheOtherFileAlsoBindsSaysTheBindingStays(): void
    {
        $this->home = new TempProject(['.porta/config.json' => \json_encode(['keymap' => ['copy_path' => ['f9']]], \JSON_THROW_ON_ERROR)]);
        \putenv('PORTA_HOME='.$this->home->dir);
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $controller->rebind('copy_path', "\x1b[24~"); // F12, in the project file

        $controller->resetBinding('copy_path');

        $this->assertArrayNotHasKey('copy_path', $this->configFile($project->dir)['keymap'] ?? [], 'the project line went');
        // No ToastCenter in a directly constructed Notifier, so the warning
        // lands on the status bar — the same message either way.
        $shown = $this->flashText($status);
        $this->assertStringContainsString('keeps its binding', $shown);
        $this->assertStringNotContainsString('Reset Copy Path', $shown, 'and never claimed the default came back');
        $this->assertSame(['f9'], IdeConfig::load($project->dir)->keymap['copy_path'], 'because the global file still decides it');
    }

    /** Resetting what this file never bound is said plainly, not reported as a failed write. */
    public function testResettingAnActionThisFileNeverBoundSaysSo(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->resetBinding('copy_path');

        $this->assertStringContainsString('Copy Path is not bound in', $this->flashText($status));
        $this->assertSame([], $this->configFile($project->dir), 'and nothing was written');
    }

    /** Which rows have something to reset — the one thing 200 identical-looking rows never said. */
    public function testRowsSayWhichActionsAConfigFileOverrides(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings());
        $controller->rebind('copy_path', "\x1b[24~");

        $byAction = \array_column($controller->rows(), null, 'action');

        $this->assertTrue($byAction['copy_path']->overridden);
        $this->assertFalse($byAction['duplicate_line']->overridden, 'a shipped default is not a choice');
    }

    /** The project's .porta.json as written, or [] when nothing ever wrote one. @return array<array-key, mixed> */
    private function configFile(string $rootDir): array
    {
        $file = $rootDir.'/.porta.json';
        $decoded = \is_file($file) ? \json_decode((string) \file_get_contents($file), associative: true, flags: \JSON_THROW_ON_ERROR) : null;

        return \is_array($decoded) ? $decoded : [];
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

        $controller->rebind('save', "\x1b[57399u"); // kitty keypad 0, which the parser does not name

        $this->assertStringContainsString('Unsupported key', $this->flashText($status));
        $this->assertFalse(\is_file($project->path('.porta.json')));
    }

    public function testAFailedPersistIsFlagged(): void
    {
        $project = new TempProject();
        \mkdir($project->path('.porta.json')); // block the write

        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $controller->rebind('save', 'a');

        $this->assertStringContainsString('Rebound Save File to A (not saved)', $this->flashText($status), 'the "(not saved)" suffix trails the message, not the other way around');
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

        $byAction = \array_column($controller->rows(), null, 'action');
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

        $byAction = \array_column($controller->rows(), null, 'action');

        $this->assertSame('n', $byAction['test_pane_step']->binding, "the pane's own action shows the pane's key");
        $this->assertSame('ctrl+s', $byAction['save']->binding, "a core action the editor also binds keeps the editor's");
    }

    public function testAPaneBindingBeatsAnIdeScopeBindingWhenTheEditorHasNeither(): void
    {
        // Priority is editor, THEN pane, THEN ide scope — an action bound at
        // both pane and ide scope (but not editor scope) must show the pane's
        // key, since that is the one that actually fires.
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
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings(['test_pane_step' => ['x']]), $panes);
        $this->host->ensureLoaded();

        $byAction = \array_column($controller->rows(), null, 'action');

        $this->assertSame('n', $byAction['test_pane_step']->binding, "the pane's own key wins over the ide-scope default");
    }

    public function testARebindOfAnActionTheEditorMatchesIsLive(): void
    {
        // The editor merges its keys from the boot WidgetContext, so before it
        // followed the live map an editor rebind landed on the next launch.
        // Now every rebuilt map is handed to it (KeymapFollowers).
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('duplicate_line', "\x1b[24~"); // F12

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
        $this->assertStringContainsString('Rebound Duplicate Line or Selection to F12', $this->flashText($status));
        $this->assertStringNotContainsString('applies on restart', $this->flashText($status));
    }

    public function testARebindOfAnIdeScopeActionThatIsNoCommandIsLive(): void
    {
        // Copy Path is matched against the IDE map itself, which rebuild()
        // swaps — live, though no key-bound command carries it.
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('copy_path', "\x1b[24~");

        $this->assertStringContainsString('Rebound Copy Path to F12', $this->flashText($status));
        $this->assertStringNotContainsString('applies on restart', $this->flashText($status));
    }

    public function testARebindOfAnActionOnlyAPaneMatchesIsLiveToo(): void
    {
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
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings(), $panes);
        $this->host->ensureLoaded();

        $controller->rebind('test_pane_step', "\x1b[24~");

        $this->assertStringContainsString('Rebound Demo: Step to F12', $this->flashText($status));
        $this->assertStringNotContainsString('applies on restart', $this->flashText($status));
    }

    public function testARebindNoFollowingWidgetMatchesSaysItAppliesOnRestart(): void
    {
        // The terminal panel matches its own keys against the map it merged
        // from the boot context, and does not follow the live one: persisted
        // now, fired on the next launch — and the flash has to say so.
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('terminal_next_session', "\x1b[24~");

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['terminal_next_session'], 'persisted either way');
        $this->assertStringContainsString('applies on restart', $this->flashText($status));
    }

    public function testADeclaredActionNothingMatchesSaysTheSame(): void
    {
        // registerActions() takes no keys, deliberately: an action no pane
        // binds has nowhere live to land.
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

    public function testResettingAnEditorActionNamesTheKeyThatFiresNow(): void
    {
        // An editor action has no catalog key: the flash used to read
        // "Reset … to " with nothing after it.
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $controller->rebind('duplicate_line', "\x1b[24~");

        $controller->resetBinding('duplicate_line');

        $this->assertStringContainsString('Reset Duplicate Line or Selection to Ctrl+D', $this->flashText($status));
    }
}
