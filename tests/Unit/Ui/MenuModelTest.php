<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ui\Menu;
use Porta\Ui\MenuItem;
use Porta\Ui\MenuModel;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Keybindings;

final class MenuModelTest extends TestCase
{
    private function build(CommandRegistry $commands, CodeEditorWidget $editor): array
    {
        $ideKeys = new Keybindings([
            'ide_run' => ['ctrl+shift+r'],
            'ide_goto_symbol' => ['ctrl+alt+shift+n'],
        ]);

        return MenuModel::build($commands, $editor, $ideKeys);
    }

    private function item(array $menus, string $title, string $label): MenuItem
    {
        foreach ($menus as $menu) {
            if ($menu->title === $title) {
                foreach ($menu->items as $item) {
                    if ($item->label === $label) {
                        return $item;
                    }
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
            function () use (&$ran): void {
                $ran = true;
            },
            keyBound: true,
        );

        $menus = $this->build($commands, new CodeEditorWidget());
        $run = $this->item($menus, 'Run', 'Run…');

        $this->assertSame('Ctrl+Shift+R', $run->shortcut, 'shortcut resolved from IDE keybindings');
        ($run->run)();
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
