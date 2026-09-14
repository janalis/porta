<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\View\ViewRegistry;

/**
 * Rebuilds the application menu bar. The menu bakes translated, colored
 * labels and key hints at setMenus() time, so every live change that affects
 * them (locale, theme, plugin set) re-runs this.
 */
#[AsIdeService]
final readonly class MenuRebuilder
{
    public function __construct(
        private MenuBarWidget $menuBar,
        private CommandRegistry $commands,
        private EditorCommandTarget $editor,
        private LiveKeybindings $keybindings,
        private Translator $translator,
        private ViewRegistry $views,
        private PluginManager $plugins,
        private MenuContributions $contributions,
    ) {}

    public function rebuild(): void
    {
        $this->menuBar->setMenus(MenuModel::build(
            $this->commands,
            $this->editor,
            $this->keybindings->current(),
            $this->translator,
            fn (): ?string => $this->views->activeId(),
            $this->plugins->menuSpecs(MenuSurface::Plugins),
            $this->contributions->all(),
        ));
    }
}
