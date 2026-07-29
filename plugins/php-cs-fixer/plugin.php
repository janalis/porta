<?php

declare(strict_types=1);

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PortaPlugin;
use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * php-cs-fixer integration: "Fix Current File" (Ctrl+Alt+F) and a persisted
 * "Format on save" setting (toggled from the palette or the plugin's settings
 * page). The fixer runs asynchronously on the shared event loop; the
 * rewritten file is picked up by Porta's filesystem poller, which reloads the
 * (clean) buffer within a second. The binary comes from the plugin's `binary`
 * setting when set, else the project's vendor/bin, then the PATH; the `args`
 * setting appends extra arguments to the fix command. The menu entry dims
 * while no binary is resolvable.
 */
return new class implements PortaPlugin {
    private bool $running = false;

    public function name(): string
    {
        return 'php-cs-fixer';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'php-cs-fixer binary', 'text', '', hint: 'empty = vendor/bin, then PATH');
        $api->registerSetting('args', 'Extra fix arguments', 'text', '', hint: 'appended to the fix command');
        $api->registerSetting('autoFormat', 'Format on save', 'bool', false);
        $api->registerInstaller('php-cs-fixer', fn (): bool => null !== $this->binary($api), [['composer', 'friendsofphp/php-cs-fixer']]);

        $api->registerCommand(
            'fix',
            'PHP CS Fixer: Fix Current File',
            function () use ($api): void {
                $file = $api->activeFile();
                if (null === $file || !str_ends_with($file, '.php')) {
                    $api->flash('php-cs-fixer: open a PHP file first');

                    return;
                }
                $this->fix($api, $file);
            },
            ['ctrl+alt+f'],
        );

        $api->registerCommand('toggle-on-save', 'PHP CS Fixer: Toggle Fix on Save', static function () use ($api): void {
            $next = !(bool) $api->setting('autoFormat');
            $api->changeSetting('autoFormat', $next);
            $api->flash('php-cs-fixer on save: '.($next ? 'on' : 'off'));
        });

        $api->addMenuItem('fix', fn (): bool => null !== $this->binary($api));
        $api->addMenuItem('toggle-on-save');

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoFormat') && str_ends_with($path, '.php')) {
                $this->fix($api, $path);
            }
        });
    }

    private function fix(PluginApi $api, string $file): void
    {
        if ($this->running) {
            $api->flash('php-cs-fixer: already running');

            return;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash('php-cs-fixer not found (vendor/bin or PATH)', 4.0);

            return;
        }

        $this->running = true;
        $args = preg_split('/\s+/', trim((string) $api->setting('args')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        new AsyncProcess([$binary, 'fix', ...$args, $file], $api->projectRoot())->start(function (int $code) use ($api, $file): void {
            $this->running = false;
            $api->flash(
                0 === $code
                    ? 'php-cs-fixer: fixed '.basename($file)
                    : 'php-cs-fixer: exited with code '.$code,
                4.0,
            );
        });
    }

    private function binary(PluginApi $api): ?string
    {
        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        $local = $api->projectRoot().'/vendor/bin/php-cs-fixer';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        return new ExecutableFinder()->find('php-cs-fixer');
    }
};
