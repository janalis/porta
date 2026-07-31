<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\AsyncProcess;

/**
 * php-cs-fixer integration: "Fix Current File" (Ctrl+Alt+F) and a persisted
 * "Format on save" setting (toggled from the palette or the plugin's settings
 * page). The fixer runs asynchronously on the shared event loop; the
 * rewritten file is picked up by Porta's filesystem poller, which reloads the
 * (clean) buffer within a second. The binary, the single-flight slot and the
 * on-save toggle all come from {@see AbstractToolPlugin}; the `args` setting
 * appends extra arguments to the fix command. The menu entry dims while no
 * binary is resolvable.
 */
return new class('php-cs-fixer', 'php-cs-fixer') extends AbstractToolPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'friendsofphp/php-cs-fixer']]);
        $api->registerSetting('args', 'Extra fix arguments', 'text', '', hint: 'appended to the fix command');
        $api->registerSetting('autoFormat', 'Format on save', 'bool', false);

        $api->registerCommand(
            'fix',
            'PHP CS Fixer: Fix Current File',
            function () use ($api): void {
                $file = $this->activeFileOrFlash($api);
                if (null !== $file) {
                    $this->fix($api, $file);
                }
            },
            ['ctrl+alt+f'],
        );
        $this->registerToggle($api, 'toggle-on-save', 'PHP CS Fixer: Toggle Fix on Save', 'autoFormat', 'php-cs-fixer on save');

        $api->addMenuItem('fix', $this->binaryAvailable($api));
        $api->addMenuItem('toggle-on-save');

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoFormat') && str_ends_with($path, '.php')) {
                $this->fix($api, $path);
            }
        });
    }

    private function fix(PluginApi $api, string $file): void
    {
        $binary = $this->acquire($api);
        if (null === $binary) {
            return;
        }
        $split = preg_split('/\s+/', trim((string) $api->setting('args')), -1, PREG_SPLIT_NO_EMPTY);
        $args = false === $split ? [] : $split;
        $before = $this->textBefore($api, $file);
        new AsyncProcess([$binary, 'fix', ...$args, $file], $api->projectRoot())->start(function (int $code) use ($api, $file, $before): void {
            $this->release();
            $this->applyFormatted($api, $file, $before);
            $this->flashExit($api, $code, 'php-cs-fixer: fixed '.basename($file));
        });
    }
};
