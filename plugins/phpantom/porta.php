<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ServerNotFound;

/**
 * phpantom (github.com/PHPantom-dev/phpantom_lsp) as the default PHP language
 * server plus its CLI. Registers the native `phpantom_lsp` binary (no
 * subcommand — it speaks LSP over stdio) for PHP; the binary resolves from the
 * plugin's `binary` setting, then `phpantom_lsp` on the PATH, then
 * `~/.porta/bin` — a Rust binary, so {@see BinarySearch::PathOnly}: composer
 * never puts one in vendor/bin. When no binary is found the resolver answers
 * {@see ServerNotFound}: PHP is unserved, the status bar says "phpantom_lsp
 * not found (install it in Settings)", and the plugin's Settings page installs
 * it through Homebrew (`phpantom-lsp`) or cargo (`phpantom_lsp`), as its
 * manifest declares. The CLI drives "Fix Current File" / "Fix Project"
 * (`phpantom_lsp fix`, in place; the single-file fix is applied into the buffer,
 * where "Fix Project" leaves its many files to the filesystem poller)
 * with a persisted "Fix on save" setting, and "Generate Config"
 * (`phpantom_lsp init`). Everything runs asynchronously on the shared loop.
 */
return new class('phpantom', 'phpantom_lsp', BinarySearch::PathOnly) extends AbstractToolPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api);
        $api->registerSetting('autoFix', label: 'Fix on save', kind: 'bool', default: false);

        $api->registerLanguageServer('php', name: 'phpantom', resolveCommand: function () use ($api): array|ServerNotFound {
            $binary = $this->binary($api);

            return null === $binary ? new ServerNotFound() : [$binary];
        });

        $api->registerCommand(
            'fix',
            label: 'PHPantom: Fix Current File',
            handler: function () use ($api): void {
                $file = $this->activeFileOrFlash($api);
                if (null !== $file) {
                    $this->runAndApply($api, ['fix', $file], $api->trans('%tool%: fixed %file%', ['%tool%' => $this->name(), '%file%' => basename($file)]), $file);
                }
            },
            keys: ['ctrl+alt+m'],
        );
        $api->registerCommand('fix-project', label: 'PHPantom: Fix Project', handler: function () use ($api): void {
            $this->runAndApply($api, ['fix'], $api->trans('%tool%: fixed project', ['%tool%' => $this->name()]));
        });
        $api->registerCommand('init', label: 'PHPantom: Generate Config', handler: function () use ($api): void {
            $this->runAndApply($api, ['init'], $api->trans('%tool%: generated config', ['%tool%' => $this->name()]));
        });
        $this->registerToggle($api, id: 'toggle-auto-fix', label: 'PHPantom: Toggle Fix on Save', settingKey: 'autoFix', phrase: 'phpantom fix on save');

        $usable = $this->binaryAvailable($api);
        $api->addMenuItem('fix', $usable);
        $api->addMenuItem('fix-project', $usable);
        $api->addMenuItem('init', $usable);
        $api->addMenuItem('toggle-auto-fix');

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoFix') && str_ends_with($path, '.php')) {
                $this->runAndApply($api, ['fix', $path], $api->trans('%tool%: fixed %file%', ['%tool%' => $this->name(), '%file%' => basename($path)]));
            }
        });
    }
};
