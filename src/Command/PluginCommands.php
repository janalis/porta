<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Editor\ModalHost;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Tool\ToolCatalog;
use Porta\Plugin\Tool\ToolJobs;
use Porta\Settings\SettingsViewController;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Switching plugins on and off, and getting them the tools they run, without
 * going through Settings: the palette's "Plugins: …" verbs. Each is the same
 * call the Settings page makes — enable() and disable() persist and apply
 * live, and the tool verbs queue through {@see ToolJobs} — so this is a
 * route, never a second copy.
 */
#[AsTaggedItem(priority: 250)]
final readonly class PluginCommands implements CommandProvider
{
    public function __construct(
        private PluginLifecycleOps $lifecycle,
        private ToolCatalog $catalog,
        private ToolJobs $jobs,
        private ModalHost $modals,
        private Notifier $notifier,
        private Translator $translator,
        // Trailing and optional so the provider's hand-built tests stand; the
        // container always wires it.
        private ?SettingsViewController $settings = null,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('plugins_enable', $this->pickToEnable(...), label: 'Plugins: Enable…')
            ->add('plugins_disable', $this->pickToDisable(...), label: 'Plugins: Disable…')
            ->add('plugins_install_tools', $this->installMissing(...), label: 'Plugins: Install Missing Tools')
            ->add('plugins_update_tools', $this->updateAll(...), label: 'Plugins: Update Tools')
            ->add('plugins_store', fn () => $this->settings?->openOn('plugins'), label: 'Plugins: Open Store');
    }

    private function pickToEnable(): void
    {
        $this->pick(
            \array_values(\array_filter($this->lifecycle->listedNames(), $this->lifecycle->isDisabled(...))),
            $this->translator->trans('Enable plugin'),
            $this->translator->trans('Every plugin is enabled'),
            $this->lifecycle->enable(...),
        );
    }

    private function pickToDisable(): void
    {
        $this->pick($this->enabled(), $this->translator->trans('Disable plugin'), $this->translator->trans('No plugin is enabled'), $this->lifecycle->disable(...));
    }

    /**
     * $names to pick one from, each with its description beside it, so a
     * name alone need not say what the plugin does — or $none when there are
     * none to pick.
     *
     * @param list<string>           $names
     * @param \Closure(string): void $onPick
     */
    private function pick(array $names, string $title, string $none, \Closure $onPick): void
    {
        if ([] === $names) {
            $this->notifier->flash($none);

            return;
        }
        $rows = \array_map(fn (string $name): array => ['label' => $name, 'hint' => $this->catalog->manifest($name)->description ?? '', 'value' => $name], $names);
        $this->modals->openListOverlay($title, $rows, $onPick);
    }

    private function installMissing(): void
    {
        if (0 === $this->jobs->installMissing($this->enabled())) {
            $this->notifier->flash($this->translator->trans('Nothing to install: every enabled plugin has the tools it requires'));
        }
    }

    private function updateAll(): void
    {
        if (0 === $this->jobs->updateAll($this->enabled())) {
            $this->notifier->flash($this->translator->trans('Nothing to update: Porta manages none of the installed tools'));
        }
    }

    /** @return list<string> */
    private function enabled(): array
    {
        return \array_values(\array_filter($this->lifecycle->listedNames(), fn (string $name): bool => !$this->lifecycle->isDisabled($name)));
    }
}
