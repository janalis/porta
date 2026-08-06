<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\Git\GitInfo;
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
final class MenuRebuilder
{
    public function __construct(
        private readonly MenuBarWidget $menuBar,
        private readonly CommandRegistry $commands,
        private readonly EditorCommandTarget $editor,
        private readonly LiveKeybindings $keybindings,
        private readonly Translator $translator,
        private readonly ViewRegistry $views,
        private readonly PluginManager $plugins,
        private readonly GitInfo $git,
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
            fn (string $title): bool => 'Git' !== $title || $this->git->isRepository(),
        ));
    }
}
