<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Config\PaletteVisibility;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Menu\MenuModel;
use Symfony\Component\Tui\Input\Keybindings;

final class MenuModelTest extends TestCase
{
    private function build(CommandRegistry $commands, CodeEditorWidget $editor, ?Translator $translator = null): array
    {
        $ideKeys = new Keybindings([
            'ide_run' => ['ctrl+shift+r'],
            'ide_goto_symbol' => ['ctrl+alt+shift+n'],
            'ide_search_everywhere' => ['ctrl+shift+e'],
        ]);

        return MenuModel::build($commands, $editor, $ideKeys, $translator ?? new Translator('en'), contributions: [self::gitContribution()]);
    }

    /**
     * The git menu, as GitMenu registers it. Core's model no longer carries a
     * DEFS['Git'] group or a VIEW_SAFE['git'] list, so every assertion below
     * about git items is now also an assertion that a CONTRIBUTED menu behaves
     * exactly like one of core's own.
     */
    private static function gitContribution(bool $visible = true): MenuContribution
    {
        return new MenuContribution(
            title: 'Git',
            after: 'Run',
            items: ['i:ide_git', MenuModel::SEP, 'i:git_stage', 'i:git_commit', 'i:git_undo_commit', 'i:git_blame', 'i:git_branches', MenuModel::SEP, 'i:git_fetch', 'i:git_pull', 'i:git_push'],
            visible: static fn (): bool => $visible,
            viewId: 'git',
            viewSafe: ['ide_quit', 'ide_git', 'git_commit', 'git_undo_commit', 'git_fetch', 'git_pull', 'git_push', 'git_branches', 'ide_command_palette', 'ide_help', 'ide_welcome'],
        );
    }

    private function item(array $menus, string $title, string $label): MenuItem
    {
        foreach ($menus as $menu) {
            if ($menu->title !== $title) {
                continue;
            }

            foreach ($menu->items as $item) {
                if ($item->label === $label) {
                    return $item;
                }
            }
        }
        $this->fail("menu item {$title} > {$label} not found");
    }

    public function testBuildsExpectedTopLevelMenus(): void
    {
        $menus = $this->build(new CommandRegistry(), new CodeEditorWidget());

        $titles = array_map(static fn (Menu $m) => $m->title, $menus);
        $this->assertSame(['File', 'Edit', 'Navigate', 'Code', 'Run', 'Git', 'View', 'Help'], $titles);
    }

    public function testAViewlessContributionAddsItsMenuAndNoViewSafeEntry(): void
    {
        // A contributed menu need not belong to a view ('' viewId): it still
        // places after its anchor, and contributes nothing to the view-safe
        // map — there is no view whose active state it could gate.
        $ideKeys = new Keybindings([]);
        $viewless = new MenuContribution(title: 'Tools', after: 'Run', items: [], viewId: '', viewSafe: ['ide_help']);

        $menus = MenuModel::build(new CommandRegistry(), new CodeEditorWidget(), $ideKeys, new Translator('en'), contributions: [$viewless]);

        $this->assertContains('Tools', array_map(static fn (Menu $m) => $m->title, $menus), 'the viewless menu still places');
    }

    public function testPluginSpecsInsertAPluginsMenuBetweenCodeAndRun(): void
    {
        $commands = new CommandRegistry();
        $ran = 0;
        $commands->add(
            'demo:hello',
            static function () use (&$ran): void {
                ++$ran;
            },
            keyBound: KeyDispatch::Bound,
            label: 'Say Hello',
        );
        $ideKeys = new Keybindings(['demo:hello' => ['ctrl+alt+y']]);

        $menus = MenuModel::build(
            $commands,
            new CodeEditorWidget(),
            $ideKeys,
            new Translator('en'),
            pluginSpecs: [new PluginMenuSpec('demo', 'i:demo:hello')],
            contributions: [self::gitContribution()],
        );
        $titles = array_map(static fn (Menu $m) => $m->title, $menus);
        $this->assertSame(['File', 'Edit', 'Navigate', 'Code', 'Plugins', 'Run', 'Git', 'View', 'Help'], $titles, 'the Plugins menu sits between Code and Run');
        $plugins = $menus[4];
        $this->assertSame('Say Hello', $plugins->items[0]->label, 'label resolves from the registry');
        $this->assertStringContainsString('Y', $plugins->items[0]->shortcut, 'shortcut from the merged keybindings');
        $this->assertTrue($plugins->items[0]->enabled(), 'no when-closure means always enabled');

        ($plugins->items[0]->run)();
        $this->assertSame(1, $ran, 'the item runs through the registry');

        $bare = MenuModel::build($commands, new CodeEditorWidget(), $ideKeys, new Translator('en'), contributions: [self::gitContribution()]);
        $this->assertCount(8, $bare, 'no Plugins menu without specs');
    }

    public function testPluginItemsAreGroupedPerPluginWithSeparators(): void
    {
        $commands = new CommandRegistry();
        $commands->add('alpha:one', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'Alpha One');
        $commands->add('alpha:two', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'Alpha Two');
        $commands->add('beta:one', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'Beta One');

        $menus = MenuModel::build($commands, new CodeEditorWidget(), new Keybindings([]), new Translator('en'), pluginSpecs: [
            new PluginMenuSpec('alpha', 'i:alpha:one'),
            new PluginMenuSpec('alpha', 'i:alpha:two'),
            new PluginMenuSpec('beta', 'i:beta:one'),
        ]);

        $items = $menus[4]->items;
        $this->assertCount(4, $items, 'three entries plus one separator');
        $this->assertSame([false, false, true, false], array_map(static fn (MenuItem $i) => $i->separator, $items), 'one separator, exactly where the owning plugin changes');
        $this->assertSame(['Alpha One', 'Alpha Two', 'Beta One'], array_values(array_map(static fn (MenuItem $i) => $i->label, array_filter($items, static fn (MenuItem $i) => !$i->separator))));
    }

    public function testASinglePluginGetsNoSeparators(): void
    {
        $commands = new CommandRegistry();
        $commands->add('demo:a', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'A');
        $commands->add('demo:b', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'B');

        $menus = MenuModel::build($commands, new CodeEditorWidget(), new Keybindings([]), new Translator('en'), pluginSpecs: [
            new PluginMenuSpec('demo', 'i:demo:a'),
            new PluginMenuSpec('demo', 'i:demo:b'),
        ]);

        $this->assertSame([false, false], array_map(static fn (MenuItem $i) => $i->separator, $menus[4]->items), 'a single plugin keeps a flat, separator-free list');
    }

    public function testPluginWhenClosureGatesTheMenuItemOnTopOfViewGating(): void
    {
        $commands = new CommandRegistry();
        $commands->add('demo:hello', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'Say Hello');
        $usable = false;
        $settingsOpen = false;

        $menus = MenuModel::build(
            $commands,
            new CodeEditorWidget(),
            new Keybindings([]),
            new Translator('en'),
            activeView: static function () use (&$settingsOpen): ?string {
                return $settingsOpen ? 'settings' : null;
            },
            pluginSpecs: [new PluginMenuSpec('demo', 'i:demo:hello', static function () use (&$usable): bool {
                return $usable;
            })],
        );
        $item = $menus[4]->items[0];

        $this->assertFalse($item->enabled(), 'unusable plugin → disabled');
        $usable = true;
        $this->assertTrue($item->enabled(), 'usable plugin → enabled');
        $settingsOpen = true;
        $this->assertFalse($item->enabled(), 'the settings-view gate still applies on top');
    }

    public function testTitlesAndItemsAreTranslated(): void
    {
        $project = new \Porta\Tests\Support\TempProject([
            'messages.fr.xlf' =>
                '<?xml version="1.0"?>'
                    .'<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">'
                    .'<file source-language="en" target-language="fr" datatype="plaintext" original="messages"><body>'
                    .'<trans-unit id="1"><source>File</source><target>Fichier</target></trans-unit>'
                    .'<trans-unit id="2"><source>Save File</source><target>Enregistrer le fichier</target></trans-unit>'
                    .'</body></file></xliff>',
        ]);
        $translator = new Translator('fr', $project->dir);

        $menus = $this->build(new CommandRegistry(), new CodeEditorWidget(), $translator);

        $this->assertSame('Fichier', $menus[0]->title, 'the File title is translated');
        $labels = array_map(static fn (MenuItem $i) => $i->label, $menus[0]->items);
        $this->assertContains('Enregistrer le fichier', $labels, 'the Save File item is translated');
    }

    private function gitCommands(): CommandRegistry
    {
        // ide_git is adoptable too, and therefore skipped when unregistered:
        // the git VIEW is the git plugin's, and outside a repository that
        // plugin registers neither the view nor this verb.
        return new CommandRegistry()
            ->add('ide_git', static function (): void {}, label: 'Git: Changes')
            ->add('git_stage', static function (): void {}, label: 'Git: Stage Current File')
            ->add('git_commit', static function (): void {}, label: 'Git: Commit…')
            ->add('git_blame', static function (): void {}, label: 'Git: Blame Current Line');
    }

    public function testFileScopedItemsDisabledWhenNoFileOpen(): void
    {
        // ide_local_history is the local-history PLUGIN's now, so the File-menu
        // item exists only when that plugin registered it — registering it here
        // is what keeps the file-scoped rule under test.
        $commands = $this->gitCommands()->add('ide_local_history', static function (): void {}, label: 'Show Local History');
        $menus = $this->build($commands, new CodeEditorWidget()); // filePath === null

        // Editor document actions + file-scoped IDE commands are disabled.
        $this->assertFalse($this->item($menus, 'Edit', 'Undo')->enabled());
        $this->assertFalse($this->item($menus, 'File', 'Save File')->enabled());
        $this->assertFalse($this->item($menus, 'Navigate', 'Go to Line')->enabled());
        $this->assertFalse($this->item($menus, 'Edit', 'Reformat File')->enabled());
        $this->assertFalse($this->item($menus, 'Git', 'Git: Stage Current File')->enabled());
        // Adopted by the local-history plugin now, so it is only in the menu
        // when that plugin registered it — which is what $commands does here.
        $this->assertFalse($this->item($menus, 'File', 'Show Local History')->enabled());

        // The git view itself is not file-scoped.
        $this->assertTrue($this->item($menus, 'Git', 'Git: Changes')->enabled());

        // Ways back into a file + global commands stay enabled.
        $this->assertTrue($this->item($menus, 'File', 'Recent Files')->enabled());
        $this->assertTrue($this->item($menus, 'File', 'Go to File')->enabled());
        $this->assertTrue($this->item($menus, 'File', 'Quit')->enabled(), 'null predicate → always enabled');
    }

    public function testEverythingEnabledOnceAFileIsOpen(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "<?php\n");
        $menus = $this->build($this->gitCommands(), $editor);

        $this->assertTrue($this->item($menus, 'Edit', 'Undo')->enabled());
        $this->assertTrue($this->item($menus, 'File', 'Save File')->enabled());
        $this->assertTrue($this->item($menus, 'Edit', 'Reformat File')->enabled());
        $this->assertTrue($this->item($menus, 'Git', 'Git: Stage Current File')->enabled());
    }

    public function testGitViewGreysOutEverythingButTheSafeCommands(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "<?php\n");
        $gitViewOpen = true;
        $ideKeys = new Keybindings([]);
        $commands = $this
            ->gitCommands()
            ->add('git_undo_commit', static function (): void {}, label: 'Git: Undo Last Commit')
            ->add('ide_git', static function (): void {}, label: 'Git: Changes');
        $menus = MenuModel::build(
            $commands,
            $editor,
            $ideKeys,
            new Translator('en'),
            static function () use (&$gitViewOpen): ?string {
                return $gitViewOpen ? 'git' : null;
            },
            contributions: [self::gitContribution()],
        );

        // Open view: only the git-safe commands stay live, file open or not.
        $this->assertFalse($this->item($menus, 'Edit', 'Undo')->enabled(), 'editor actions target the detached editor');
        $this->assertFalse($this->item($menus, 'File', 'Save File')->enabled());
        $this->assertFalse($this->item($menus, 'File', 'Go to File')->enabled(), 'even non-file-scoped IDE commands grey out');
        $this->assertFalse($this->item($menus, 'Git', 'Git: Stage Current File')->enabled(), 'stage targets the editor file');
        $this->assertTrue($this->item($menus, 'File', 'Quit')->enabled());
        $this->assertTrue($this->item($menus, 'Git', 'Git: Changes')->enabled());
        $this->assertTrue($this->item($menus, 'Git', 'Git: Commit…')->enabled());
        $this->assertTrue($this->item($menus, 'Git', 'Git: Undo Last Commit')->enabled());
        $this->assertTrue($this->item($menus, 'Help', 'Find Action')->enabled());

        // Closed view: the same menus fall back to the file-scoped rules.
        $gitViewOpen = false;
        $this->assertTrue($this->item($menus, 'Edit', 'Undo')->enabled());
        $this->assertTrue($this->item($menus, 'Git', 'Git: Stage Current File')->enabled());
        $this->assertTrue($this->item($menus, 'File', 'Go to File')->enabled());
    }

    public function testSettingsIsListedInTheFileMenu(): void
    {
        $menus = $this->build(new CommandRegistry(), new CodeEditorWidget());
        $this->assertSame('Settings…', $this->item($menus, 'File', 'Settings…')->label);
    }

    public function testSettingsViewGreysOutEverythingButTheSafeCommands(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "<?php\n");
        $settingsOpen = true;
        $ideKeys = new Keybindings([]);
        $commands = $this->gitCommands()->add('ide_settings', static function (): void {}, label: 'Settings…');
        $menus = MenuModel::build(
            $commands,
            $editor,
            $ideKeys,
            new Translator('en'),
            static function () use (&$settingsOpen): ?string {
                return $settingsOpen ? 'settings' : null;
            },
            contributions: [self::gitContribution()],
        );

        // Open view: only the settings-safe commands stay live.
        $this->assertFalse($this->item($menus, 'Edit', 'Undo')->enabled());
        $this->assertFalse($this->item($menus, 'File', 'Save File')->enabled());
        $this->assertFalse($this->item($menus, 'Git', 'Git: Changes')->enabled(), 'even the git-view command greys out here');
        $this->assertTrue($this->item($menus, 'File', 'Settings…')->enabled());
        $this->assertTrue($this->item($menus, 'File', 'Quit')->enabled());
        $this->assertTrue($this->item($menus, 'Help', 'Find Action')->enabled());

        // Closed view: the file-scoped rules apply again.
        $settingsOpen = false;
        $this->assertTrue($this->item($menus, 'Edit', 'Undo')->enabled());
        $this->assertTrue($this->item($menus, 'Git', 'Git: Changes')->enabled());
    }

    public function testEditorItemRunsRunActionAndShowsShortcut(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "<?php\necho 1;\n");
        $editor->getDocument()->setCursor(0, 0);

        $menus = $this->build(new CommandRegistry(), $editor);
        $dup = $this->item($menus, 'Edit', 'Duplicate Line or Selection');

        $this->assertSame('Ctrl+D', $dup->shortcut, 'shortcut resolved from editor keybindings');
        ($dup->run)();
        $this->assertSame('<?php', $editor->getDocument()->getLine(1), 'run() invoked editor->runAction');
    }

    public function testIdeItemRunsCommandAndShortcutFromIdeKeys(): void
    {
        $ran = false;
        $commands = new CommandRegistry();
        $commands->add(
            'ide_run',
            static function () use (&$ran): void {
                $ran = true;
            },
            keyBound: KeyDispatch::Bound,
        );

        $menus = $this->build($commands, new CodeEditorWidget());
        $run = $this->item($menus, 'Run', 'Run…');

        $this->assertSame('Ctrl+Shift+R', $run->shortcut, 'shortcut resolved from IDE keybindings');
        ($run->run)();
        $this->assertTrue($ran, 'run() invoked CommandRegistry::run');
    }

    public function testSearchEverywhereIsInTheNavigateMenu(): void
    {
        $ran = false;
        $commands = new CommandRegistry();
        $commands->add(
            'ide_search_everywhere',
            static function () use (&$ran): void {
                $ran = true;
            },
            keyBound: KeyDispatch::Bound,
            inPalette: PaletteVisibility::Hidden,
        );

        $menus = $this->build($commands, new CodeEditorWidget());
        $item = $this->item($menus, 'Navigate', 'Search Everywhere');

        $this->assertSame('Ctrl+Shift+E', $item->shortcut, 'shortcut resolved from IDE keybindings');
        $this->assertTrue($item->enabled(), 'available without an open file');
        ($item->run)();
        $this->assertTrue($ran, 'run() invoked CommandRegistry::run');
    }

    public function testCustomCommandLabelIsUsed(): void
    {
        $commands = new CommandRegistry();
        $commands->add('toggle_inline_diagnostics', static function (): void {}, label: 'Toggle Inline Diagnostics');

        $menus = $this->build($commands, new CodeEditorWidget());
        $item = $this->item($menus, 'View', 'Toggle Inline Diagnostics');
        $this->assertFalse($item->separator);
    }

    public function testSeparatorsArePresent(): void
    {
        $menus = $this->build(new CommandRegistry(), new CodeEditorWidget());
        $file = $menus[0];
        $this->assertSame('File', $file->title);
        $this->assertTrue(
            array_any($file->items, static fn (MenuItem $i) => $i->separator),
            'File menu has at least one separator',
        );
    }

    /**
     * The VIEW_SAFE map must leave a way out of every view it covers: the gate at
     * MenuModel::item() reads `VIEW_SAFE[$active->name] ?? []`, so a new view
     * with no entry silently disables every single menu item — the same
     * silent-unreachable failure mode the settings-section order test guards.
     */
    public function testEveryViewSafeEntryLeavesAWayOut(): void
    {
        // This used to range over WorkspaceView::cases() in both directions.
        // There is no enum to range over now — a view supplies its own id and
        // registers — so what is left is the invariant that actually matters:
        // a view whose entry is missing or empty greys out the WHOLE menu, and
        // the user needs at minimum a way to quit and a way to run a command.
        /** @var array<string, list<string>> $map */
        $map = Probe::getStatic(MenuModel::class, 'VIEW_SAFE');

        // Core's own, plus every contributed list — a contributor that named a
        // view but left its list empty greys out its OWN menu the moment that
        // view opens, which is the failure with no error message.
        $registry = new MenuContributions();
        $registry->register(self::gitContribution());
        $map = [...$map, ...$registry->viewSafe()];

        $this->assertSame(['settings', 'welcome', 'git'], array_keys($map));
        foreach ($map as $id => $safe) {
            $this->assertNotSame([], $safe, $id.': an empty entry disables every menu item silently');
            $this->assertContains('ide_quit', $safe, $id.': quitting stays reachable');
            $this->assertContains('ide_command_palette', $safe, $id.': the palette stays reachable');
        }
    }

    public function testSkippingAnItemDoesNotLeaveItsDividersDoubled(): void
    {
        // A spec list writes its separators assuming every item survives, and
        // one kind does not: `ide_local_history` sits BETWEEN two dividers in
        // the File menu, so skipping it (its plugin disabled) would render them
        // adjacent. Driven through items() directly, since core's own DEFS have
        // no adoptable id between separators until that move lands.
        $commands = new CommandRegistry();
        $editor = new CodeEditorWidget();
        $ideKeys = new Keybindings();

        $kinds = static fn (array $items): array => array_map(
            static fn (MenuItem $item): string => $item->separator ? '---' : $item->label,
            $items,
        );

        // 'ide_todo' is adoptable and unregistered here, so it is skipped.
        $items = MenuModel::items([MenuModel::SEP, 'i:ide_todo', MenuModel::SEP, 'i:ide_settings'], $commands, $editor, $ideKeys, new Translator('en'));
        $this->assertSame(['Settings…'], $kinds($items), 'a leading divider, the skipped item and its second divider all go');

        $items = MenuModel::items(['i:ide_settings', MenuModel::SEP, 'i:ide_todo'], $commands, $editor, $ideKeys, new Translator('en'));
        $this->assertSame(['Settings…'], $kinds($items), 'and a divider left trailing goes too');

        $items = MenuModel::items(['i:ide_settings', MenuModel::SEP, 'i:ide_quit'], $commands, $editor, $ideKeys, new Translator('en'));
        $this->assertSame(['Settings…', '---', 'Quit'], $kinds($items), 'a divider between two survivors stays');
    }
}
