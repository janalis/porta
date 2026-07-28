<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\CommandRegistry;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Plugin\PluginManager;
use Porta\Ui\WorkspaceView;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\MenuBarWidget;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Rebuilds the application menu bar. The menu bakes translated, colored
 * labels and key hints at setMenus() time, so every live change that affects
 * them (locale, theme, plugin set) re-runs this.
 */
#[AsIdeService]
final class MenuRebuilder
{
    public function __construct(
        private readonly MenuBarWidget $menuBar,
        private readonly CommandRegistry $commands,
        private readonly EditorCommandTarget $editor,
        private readonly Keybindings $keybindings,
        private readonly Translator $translator,
        private readonly WorkspaceViews $views,
        private readonly PluginManager $plugins,
    ) {}

    public function rebuild(): void
    {
        $this->menuBar->setMenus(MenuModel::build(
            $this->commands,
            $this->editor,
            $this->keybindings,
            $this->translator,
            fn (): ?WorkspaceView => $this->views->activeView(),
            $this->plugins->menuSpecs(),
        ));
    }
}
