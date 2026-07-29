<?php

declare(strict_types=1);

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PortaPlugin;
use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * phpantom (github.com/PHPantom-dev/phpantom_lsp) as the default PHP language
 * server plus its CLI. Registers the native `phpantom_lsp` binary (no
 * subcommand — it speaks LSP over stdio) for PHP; the binary resolves from the
 * plugin's `binary` setting, then `phpantom_lsp` on the PATH. When no binary is
 * found the LSP resolver returns null (PHP is unserved) and the Settings page
 * offers to install it via Homebrew (`brew install phpantom-lsp`) or cargo
 * (`cargo install phpantom_lsp --locked`). The CLI drives "Fix Current File" /
 * "Fix Project" (`phpantom_lsp fix`, in place — the filesystem poller reloads
 * the buffer) with a persisted "Fix on save" setting, and "Generate Config"
 * (`phpantom_lsp init`). Everything runs asynchronously on the shared loop.
 */
return new class implements PortaPlugin {
    private bool $running = false;

    public function name(): string
    {
        return 'phpantom';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'phpantom_lsp binary', 'text', '', hint: 'empty = phpantom_lsp on PATH');
        $api->registerSetting('autoFix', 'Fix on save', 'bool', false);
        $api->registerInstaller('phpantom', fn (): bool => null !== $this->binary($api), [
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ]);

        $api->registerLanguageServer('php', 'phpantom', function () use ($api): ?array {
            $binary = $this->binary($api);

            return null === $binary ? null : [$binary];
        });

        $api->registerCommand(
            'fix',
            'PHPantom: Fix Current File',
            function () use ($api): void {
                $file = $api->activeFile();
                if (null === $file || !str_ends_with($file, '.php')) {
                    $api->flash('phpantom: open a PHP file first');

                    return;
                }
                $this->fix($api, [$file], basename($file));
            },
            ['ctrl+alt+m'],
        );

        $api->registerCommand('fix-project', 'PHPantom: Fix Project', function () use ($api): void {
            $this->fix($api, [], 'project');
        });

        $api->registerCommand('init', 'PHPantom: Generate Config', function () use ($api): void {
            $this->init($api);
        });

        $api->registerCommand('toggle-auto-fix', 'PHPantom: Toggle Fix on Save', static function () use ($api): void {
            $next = !(bool) $api->setting('autoFix');
            $api->changeSetting('autoFix', $next);
            $api->flash('phpantom fix on save: '.($next ? 'on' : 'off'));
        });

        $usable = fn (): bool => null !== $this->binary($api);
        $api->addMenuItem('fix', $usable);
        $api->addMenuItem('fix-project', $usable);
        $api->addMenuItem('init', $usable);
        $api->addMenuItem('toggle-auto-fix');

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoFix') && str_ends_with($path, '.php')) {
                $this->fix($api, [$path], basename($path));
            }
        });
    }

    /**
     * Run `phpantom_lsp fix` (over $paths, or the whole project when empty) in
     * place; the filesystem poller reloads the rewritten buffer.
     *
     * @param list<string> $paths
     */
    private function fix(PluginApi $api, array $paths, string $label): void
    {
        if ($this->running) {
            $api->flash('phpantom: already running');

            return;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash('phpantom not found (set its binary or install it)', 4.0);

            return;
        }

        $this->running = true;
        new AsyncProcess([$binary, 'fix', ...$paths], $api->projectRoot())->start(function (int $code) use ($api, $label): void {
            $this->running = false;
            $api->flash(
                0 === $code
                    ? 'phpantom: fixed '.$label
                    : 'phpantom: exited with code '.$code,
                4.0,
            );
        });
    }

    private function init(PluginApi $api): void
    {
        if ($this->running) {
            $api->flash('phpantom: already running');

            return;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash('phpantom not found (set its binary or install it)', 4.0);

            return;
        }

        $this->running = true;
        new AsyncProcess([$binary, 'init'], $api->projectRoot())->start(function (int $code) use ($api): void {
            $this->running = false;
            $api->flash(
                0 === $code
                    ? 'phpantom: generated config'
                    : 'phpantom: exited with code '.$code,
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

        return new ExecutableFinder()->find('phpantom_lsp');
    }
};
