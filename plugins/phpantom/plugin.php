<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\AsyncProcess;

/**
 * phpantom (github.com/PHPantom-dev/phpantom_lsp) as the default PHP language
 * server plus its CLI. Registers the native `phpantom_lsp` binary (no
 * subcommand — it speaks LSP over stdio) for PHP; the binary resolves from the
 * plugin's `binary` setting, then `phpantom_lsp` on the PATH — a Rust binary,
 * so {@see BinarySearch::PathOnly}: composer never puts one in vendor/bin.
 * When no binary is found the LSP resolver returns null (PHP is unserved) and
 * the Settings page offers to install it via Homebrew
 * (`brew install phpantom-lsp`) or cargo (`cargo install phpantom_lsp
 * --locked`). The CLI drives "Fix Current File" / "Fix Project"
 * (`phpantom_lsp fix`, in place — the filesystem poller reloads the buffer)
 * with a persisted "Fix on save" setting, and "Generate Config"
 * (`phpantom_lsp init`). Everything runs asynchronously on the shared loop.
 */
return new class('phpantom', 'phpantom_lsp', BinarySearch::PathOnly) extends AbstractToolPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ]);
        $api->registerSetting('autoFix', 'Fix on save', 'bool', false);

        $api->registerLanguageServer('php', 'phpantom', function () use ($api): ?array {
            $binary = $this->binary($api);

            return null === $binary ? null : [$binary];
        });

        $api->registerCommand(
            'fix',
            'PHPantom: Fix Current File',
            function () use ($api): void {
                $file = $this->activeFileOrFlash($api);
                if (null !== $file) {
                    $this->run($api, ['fix', $file], 'fixed '.basename($file), $file);
                }
            },
            ['ctrl+alt+m'],
        );
        $api->registerCommand('fix-project', 'PHPantom: Fix Project', function () use ($api): void {
            $this->run($api, ['fix'], 'fixed project');
        });
        $api->registerCommand('init', 'PHPantom: Generate Config', function () use ($api): void {
            $this->run($api, ['init'], 'generated config');
        });
        $this->registerToggle($api, 'toggle-auto-fix', 'PHPantom: Toggle Fix on Save', 'autoFix', 'phpantom fix on save');

        $usable = $this->binaryAvailable($api);
        $api->addMenuItem('fix', $usable);
        $api->addMenuItem('fix-project', $usable);
        $api->addMenuItem('init', $usable);
        $api->addMenuItem('toggle-auto-fix');

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoFix') && str_ends_with($path, '.php')) {
                $this->run($api, ['fix', $path], 'fixed '.basename($path));
            }
        });
    }

    /**
     * One CLI invocation under the shared single-flight slot.
     *
     * $formatted names the ONE file this run rewrites, so its result can be
     * applied into the buffer — one Ctrl+Z away, and without waiting for a poll
     * tick. "Fix Project" passes null on purpose: it touches many files, and
     * the filesystem poller is the right answer for the ones the user is not
     * looking at.
     *
     * @param list<string> $arguments
     */
    private function run(PluginApi $api, array $arguments, string $success, ?string $formatted = null): void
    {
        $binary = $this->acquire($api);
        if (null === $binary) {
            return;
        }
        $before = null === $formatted ? null : $this->textBefore($api, $formatted);
        new AsyncProcess([$binary, ...$arguments], $api->projectRoot())->start(function (int $code) use ($api, $success, $formatted, $before): void {
            $this->release();
            if (null !== $formatted) {
                $this->applyFormatted($api, $formatted, $before);
            }
            $this->flashExit($api, $code, 'phpantom: '.$success);
        });
    }
};
