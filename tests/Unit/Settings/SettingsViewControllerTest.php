<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Config\SettingsKeymapController;
use Porta\Settings\SettingsViewController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsTabsWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Drives SettingsViewController with real widgets on a virtual-terminal Tui —
 * no full Ide boot, so every branch is cheap to reach.
 */
final class SettingsViewControllerTest extends TestCase
{
    private TempProject $project;
    private Tui $tui;
    private SettingsTabsWidget $tabs;
    private SettingsFormWidget $form;
    private KeybindingsListWidget $keymapList;
    private ContainerWidget $main;
    private ContainerWidget $content;
    private MenuBarWidget $menuBar;
    private int $closed = 0;

    private function controller(): SettingsViewController
    {
        $this->project = new TempProject();
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);

        $this->tabs = new SettingsTabsWidget();
        $this->form = new SettingsFormWidget();
        $this->form->setId('settings-form');
        $this->keymapList = new KeybindingsListWidget();
        $this->keymapList->setId('settings-keys');
        $this->content = new ContainerWidget();
        $area = new ContainerWidget();
        $area->setId('settings-area');
        $area->add($this->tabs)->add($this->content);
        $this->menuBar = new MenuBarWidget();
        $this->menuBar->setId('menu');

        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $editor = new CodeEditorWidget();
        $status = new StatusBarWidget();
        $settings = new SettingsController(IdeConfig::DEFAULT_SETTINGS, $editor, $status, $this->tui, $this->project->dir);
        $keymap = new SettingsKeymapController($editor, new Keybindings([]), $status, $this->tui, $this->project->dir);
        $this->closed = 0;

        return new SettingsViewController(
            $this->tabs,
            $this->form,
            $this->keymapList,
            $this->main,
            $area,
            $this->content,
            $this->menuBar,
            [$editWidget],
            $this->tui,
            $settings,
            $keymap,
            onClosed: function (): void { ++$this->closed; },
        );
    }

    /** @return list<string> ids of the main/content children */
    private function ids(ContainerWidget $container): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $container->all());
    }

    public function testOpenSwapsMainAndFocusesTheForm(): void
    {
        $controller = $this->controller();
        $this->assertFalse($controller->isOpen());

        $controller->open();
        $this->assertTrue($controller->isOpen());
        $this->assertSame(['settings-area'], $this->ids($this->main));
        $this->assertSame(['settings-form'], $this->ids($this->content));
        $this->assertSame($this->form, $this->tui->getFocus());
        $this->assertSame('settings', $controller->focusTarget() === $this->form ? 'settings' : 'other');
    }

    public function testToggleClosesAndRestoresTheEditor(): void
    {
        $controller = $this->controller();
        $controller->toggle(); // open
        $this->assertTrue($controller->isOpen());

        $controller->open(); // idempotent
        $this->assertSame(['settings-area'], $this->ids($this->main));

        $controller->toggle(); // close
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], $this->ids($this->main));
        $this->assertSame(1, $this->closed);
    }

    public function testCloseWhenClosedIsANoOp(): void
    {
        $controller = $this->controller();
        $controller->close();
        $this->assertSame(0, $this->closed);
    }

    public function testSwitchingToKeybindingsFocusesTheListAndLoadsRows(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->form->handleInput('2'); // → onShowKeybindings → switchMode
        $this->assertSame(['settings-keys'], $this->ids($this->content));
        $this->assertSame($this->keymapList, $this->tui->getFocus());
        $this->assertSame($this->keymapList, $controller->focusTarget());

        $screen = implode("\n", $this->keymapList->render(new \Symfony\Component\Tui\Render\RenderContext(100, 20)));
        $this->assertStringContainsString('actions', $screen, 'the list is populated on switch');

        // …and back to settings.
        $this->keymapList->handleInput('1');
        $this->assertSame(['settings-form'], $this->ids($this->content));
        $this->assertSame($this->form, $this->tui->getFocus());
    }

    public function testChangingAFormSettingPersists(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->form->handleClick(0); // toggle Inline diagnostics (default true → false)
        $this->assertFalse(IdeConfig::load($this->project->dir)->settings['inlineDiagnostics']);
    }

    public function testRebindingFromTheListPersists(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->form->handleInput('2'); // to keybindings

        $this->keymapList->handleInput("\r"); // arm capture on the first action
        $this->keymapList->handleInput('a');  // captured
        $this->assertSame(['a'], IdeConfig::load($this->project->dir)->keymap['cursor_up']);
    }

    public function testEscFromEitherPaneClosesTheView(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->form->handleInput("\x1b");
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], $this->ids($this->main));

        $controller = $this->controller();
        $controller->open();
        $this->form->handleInput('2');
        $this->keymapList->handleInput("\x1b");
        $this->assertFalse($controller->isOpen());
    }

    public function testTabReFocusesTheActivePane(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->form->handleInput("\t"); // focusNext in settings mode
        $this->assertSame($this->form, $this->tui->getFocus());

        $this->form->handleInput('2');
        $this->keymapList->handleInput("\t"); // focusNext in keybindings mode
        $this->assertSame($this->keymapList, $this->tui->getFocus());
    }

    public function testTabStripClickSwitchesTabs(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->tabs->render(new \Symfony\Component\Tui\Render\RenderContext(100, 1)); // populate layout

        $this->tabs->handleClick(14); // the Keybindings tab
        $this->assertSame(['settings-keys'], $this->ids($this->content));
    }
}
