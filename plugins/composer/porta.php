<?php

declare(strict_types=1);

use Porta\Composer\ComposerRunner;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\Menu\MenuSurface;

/**
 * Composer from the editor: what is out of date, in the dock and marked in
 * composer.json, and install, update, require and remove in a terminal tab.
 *
 * Off by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}),
 * like every plugin that runs a tool of the project's.
 */
return new class('composer', 'composer') extends AbstractToolPlugin {
    #[\Override]
    protected function runsProjectCode(): bool
    {
        return true; // install and update run the project's scripts and Composer plugins
    }

    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api);
        $runner = new ComposerRunner($api, fn (): ?string => $this->acquire($api), $this->release(...));

        $api->registerListTool(ComposerRunner::TOOL, title: 'Packages', rerun: $runner->checkOutdated(...));
        $api->registerCommand('outdated', label: 'Composer: Show Outdated Packages', handler: $runner->checkOutdated(...));
        $api->registerCommand('install', label: 'Composer: Install', handler: $runner->install(...));
        $api->registerCommand('update', label: 'Composer: Update All', handler: $runner->updateAll(...));
        $api->registerCommand('update_at_caret', label: 'Composer: Update Package at Caret', handler: $runner->updateAtCaret(...));
        $api->registerCommand('require', label: 'Composer: Require Package…', handler: $runner->require(...));
        $api->registerCommand('remove_at_caret', label: 'Composer: Remove Package at Caret', handler: $runner->removeAtCaret(...));

        $available = $this->binaryAvailable($api);
        foreach (['outdated', 'install', 'update', 'update_at_caret', 'require', 'remove_at_caret'] as $command) {
            $api->addMenuItem($command, $available);
        }
        $api->addMenuItem('update_at_caret', $available, MenuSurface::EditorContext);

        $api->onBufferSwitched(static fn (string $path) => $runner->annotate());
        $api->onFileReloaded(static fn (string $path) => $runner->annotate()); // composer require rewrote it: the lines moved
    }
};
