<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsController;
use Porta\Config\SettingsKeymapController;
use Porta\Config\SettingsLanguageController;
use Porta\Config\SettingsLspController;
use Porta\Lsp\LspSessionPool;
use Porta\I18n\Translator;
use Porta\Settings\SettingsViewController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class SettingsViewControllerTest extends TestCase
{
    private TempProject $project;
    private Tui $tui;
    private SettingsNavWidget $nav;
    private SettingsFormWidget $form;
    private KeybindingsListWidget $keymapList;
    private ContainerWidget $main;
    private ContainerWidget $content;
    private int $closed = 0;

    private function controller(): SettingsViewController
    {
        $this->project = new TempProject();
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);

        $this->nav = new SettingsNavWidget(new Translator('en'));
        $this->form = new SettingsFormWidget(new Translator('en'));
        $this->form->setId('settings-form');
        $this->keymapList = new KeybindingsListWidget(new Translator('en'));
        $this->keymapList->setId('settings-keys');
        $this->content = new ContainerWidget();
        $area = new ContainerWidget();
        $area->setId('settings-area');
        $area->add($this->nav)->add($this->content);
        $menuBar = new MenuBarWidget();
        $menuBar->setId('menu');

        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $editor = new CodeEditorWidget();
        $status = new StatusBarWidget();
        $settings = new SettingsController(IdeConfig::DEFAULT_SETTINGS, $editor, $status, $this->tui, $this->project->dir, new Translator('en'), static function (string $locale): void {});
        $keymap = new SettingsKeymapController($editor, new Keybindings([]), $status, $this->tui, $this->project->dir, new Translator('en'));
        $languages = new SettingsLanguageController($editor, $status, $this->tui, $this->project->dir, new Translator('en'), []);
        $pool = new LspSessionPool($this->project->dir, static fn (): ?string => null);
        $lsp = new SettingsLspController($this->project->dir, $pool, [], null, static function (): void {}, $status, $this->tui, new Translator('en'));
        $this->closed = 0;

        return new SettingsViewController(
            $this->nav,
            $this->form,
            $this->keymapList,
            $this->main,
            $area,
            $this->content,
            $menuBar,
            [$editWidget],
            $this->tui,
            $settings,
            $keymap,
            $languages,
            $lsp,
            onClosed: function (): void { ++$this->closed; },
        );
    }

    /** @return list<string> */
    private function ids(ContainerWidget $container): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $container->all());
    }

    public function testOpenSwapsMainAndShowsGeneralFocused(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->assertTrue($controller->isOpen());
        $this->assertSame(['settings-area'], $this->ids($this->main));
        $this->assertSame(['settings-form'], $this->ids($this->content));
        $this->assertSame($this->form, $this->tui->getFocus());
        $this->assertSame('general', $this->nav->selectedId());
    }

    public function testNavSelectingKeybindingsSwapsTheContent(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->nav->handleInput("\x1b[B"); // → Keybindings
        $this->assertSame(['settings-keys'], $this->ids($this->content));
        $this->assertSame($this->keymapList, $controller->focusTarget());

        $screen = implode("\n", $this->keymapList->render(new \Symfony\Component\Tui\Render\RenderContext(80, 20)));
        $this->assertStringContainsString('actions', $screen, 'the keybindings list is populated');
    }

    public function testNavSelectingALanguageShowsIndentRowsAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // skips the header → PHP
        $this->assertSame('lang:php', $this->nav->selectedId());
        $this->assertSame(['settings-form'], $this->ids($this->content));

        $screen = implode("\n", $this->form->render(new \Symfony\Component\Tui\Render\RenderContext(80, 8)));
        $this->assertStringContainsString('Indent style', $screen);
        $this->assertStringContainsString('Indent size', $screen);
        $this->assertStringContainsString('LSP server', $screen, 'the language section also shows its LSP server row');

        // Actuate the indent-style row (auto → spaces) → persists to the languages block.
        $this->form->handleClick(0);
        $this->assertSame('spaces', IdeConfig::load($this->project->dir)->languages['php']['indentStyle']);
    }

    public function testEditingTheLspRowRoutesToTheLspControllerAndPersists(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->nav->handleInput("\x1b[B"); // Keybindings
        $this->nav->handleInput("\x1b[B"); // PHP

        // The LSP row is the third row of a language section; edit it inline.
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\x1b[B");
        $this->form->handleInput("\r"); // start editing
        foreach (str_split('phpactor') as $char) {
            $this->form->handleInput($char);
        }
        $this->form->handleInput("\r"); // commit

        $this->assertSame('phpactor', IdeConfig::load($this->project->dir)->lspServers['php']);
    }

    public function testChangingAGeneralSettingPersistsToTheSettingsBlock(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->form->handleClick(1); // toggle Inline diagnostics (row 0 is Language now; default true → false)
        $this->assertFalse(IdeConfig::load($this->project->dir)->settings['inlineDiagnostics']);
    }

    public function testRebindingFromTheKeybindingsSection(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->nav->handleInput("\x1b[B"); // Keybindings

        $this->keymapList->handleInput("\r"); // arm capture on the first action
        $this->keymapList->handleInput('a');
        $this->assertSame(['a'], IdeConfig::load($this->project->dir)->keymap['cursor_up']);
    }

    public function testTabCyclesBetweenNavAndContent(): void
    {
        $controller = $this->controller();
        $controller->open(); // focus starts on the form (content)

        $this->form->handleInput("\t"); // content → nav
        $this->assertSame($this->nav, $this->tui->getFocus());

        $this->nav->handleInput("\t"); // nav → content
        $this->assertSame($this->form, $this->tui->getFocus());
    }

    public function testEscFromNavOrContentClosesTheView(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->nav->handleInput("\x1b");
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], $this->ids($this->main));
        $this->assertSame(1, $this->closed);

        $controller = $this->controller();
        $controller->open();
        $this->form->handleInput("\x1b");
        $this->assertFalse($controller->isOpen());
    }

    public function testToggleAndCloseWhenClosedIsANoOp(): void
    {
        $controller = $this->controller();
        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $controller->open(); // idempotent
        $this->assertSame(['settings-area'], $this->ids($this->main));
        $controller->toggle();
        $this->assertFalse($controller->isOpen());

        $controller->close(); // already closed
        $this->assertSame(1, $this->closed);
    }
}
