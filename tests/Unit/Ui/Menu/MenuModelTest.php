<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Config\PaletteVisibility;
use Porta\I18n\Translator;
use Porta\Plugin\PluginMenuSpec;
use Porta\Ui\Menu\Menu;
use Porta\Ui\Menu\MenuItem;
use Porta\Ui\Menu\MenuModel;
use Porta\Ui\WorkspaceView;
use Porta\Widget\CodeEditorWidget;
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

        return MenuModel::build($commands, $editor, $ideKeys, $translator ?? new Translator('en'));
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

        $menus = MenuModel::build($commands, new CodeEditorWidget(), $ideKeys, new Translator('en'), pluginSpecs: [new PluginMenuSpec('demo', 'i:demo:hello')]);
        $titles = array_map(static fn (Menu $m) => $m->title, $menus);
        $this->assertSame(['File', 'Edit', 'Navigate', 'Code', 'Plugins', 'Run', 'Git', 'View', 'Help'], $titles, 'the Plugins menu sits between Code and Run');
        $plugins = $menus[4];
        $this->assertSame('Say Hello', $plugins->items[0]->label, 'label resolves from the registry');
        $this->assertStringContainsString('Y', $plugins->items[0]->shortcut, 'shortcut from the merged keybindings');
        $this->assertTrue($plugins->items[0]->enabled(), 'no when-closure means always enabled');

        ($plugins->items[0]->run)();
        $this->assertSame(1, $ran, 'the item runs through the registry');

        $bare = MenuModel::build($commands, new CodeEditorWidget(), $ideKeys, new Translator('en'));
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
            activeView: static function () use (&$settingsOpen): ?WorkspaceView {
                return $settingsOpen ? WorkspaceView::Settings : null;
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
        return new CommandRegistry()
            ->add('git_stage', static function (): void {}, label: 'Git: Stage Current File')
            ->add('git_commit', static function (): void {}, label: 'Git: Commit…')
            ->add('git_blame', static function (): void {}, label: 'Git: Blame Current Line');
    }

    public function testFileScopedItemsDisabledWhenNoFileOpen(): void
    {
        $menus = $this->build($this->gitCommands(), new CodeEditorWidget()); // filePath === null

        // Editor document actions + file-scoped IDE commands are disabled.
        $this->assertFalse($this->item($menus, 'Edit', 'Undo')->enabled());
        $this->assertFalse($this->item($menus, 'File', 'Save File')->enabled());
        $this->assertFalse($this->item($menus, 'Navigate', 'Go to Line')->enabled());
        $this->assertFalse($this->item($menus, 'Edit', 'Reformat File')->enabled());
        $this->assertFalse($this->item($menus, 'Git', 'Git: Stage Current File')->enabled());
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
        $menus = MenuModel::build($commands, $editor, $ideKeys, new Translator('en'), static function () use (&$gitViewOpen): ?WorkspaceView {
            return $gitViewOpen ? WorkspaceView::Git : null;
        });

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
        $menus = MenuModel::build($commands, $editor, $ideKeys, new Translator('en'), static function () use (&$settingsOpen): ?WorkspaceView {
            return $settingsOpen ? WorkspaceView::Settings : null;
        });

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
}
