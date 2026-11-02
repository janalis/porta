<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Search\ProjectSearch;
use Porta\Todo\TodoScan;
use Porta\Todo\TodoTags;

/**
 * The project-wide TODO/FIXME scan as a bundled plugin — the first core feature
 * to move out on the dock LIST TOOL seam, the way the two viewers moved out on
 * the pane seam.
 *
 * It keeps its identity: `adoptCommand('ide_todo')` registers the handler under
 * the BARE core id, so alt+8 still works, an existing `.porta.json` rebind of
 * `ide_todo` still binds, Settings → Keymap still lists "Show TODOs" under
 * core's label, and the entry stays in the VIEW menu instead of moving to the
 * trailing Plugins menu. Core still NAMES the verb in its ActionCatalog; only
 * the implementation is here. That is why there is no `addMenuItem()` call
 * below — the menu entry it would add is the one core already has.
 *
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS});
 * disabling it takes alt+8 and the View-menu item with it, which is what
 * disabling a feature should look like.
 */
return new class('todo') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerSetting('tags', 'TODO tags to scan for', 'text', TodoTags::DEFAULT, hint: 'comma-separated; matched case-sensitively');
        $api->registerListTool(TodoScan::TOOL, 'TODO', rerun: $this->scan($api));
        $api->adoptCommand('ide_todo', $this->scan($api));
    }

    /**
     * The scan, as the closure both the command and F5 fire.
     *
     * Built per call rather than memoised: the engine is cheap, and a scan
     * reads the `tags` setting when it runs, so editing the setting takes
     * effect on the next scan with nothing to invalidate.
     *
     * @return \Closure(): void
     */
    private function scan(PluginApi $api): \Closure
    {
        return static function () use ($api): void {
            new TodoScan($api, new ProjectSearch($api->projectRoot()))->run();
        };
    }
};
