<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Symfony\Component\Process\ExecutableFinder;

/**
 * phpantom (github.com/PHPantom-dev/phpantom_lsp) as the default PHP language
 * server. Registers the native `phpantom_lsp` binary (no subcommand — it speaks
 * LSP over stdio) for PHP; the binary resolves from the plugin's `binary`
 * setting, then `phpantom_lsp` on the PATH. When no binary is found the
 * resolver returns null, so PHP is unserved and the status bar suggests
 * installing phpantom.
 */
return new class implements PortaPlugin {
    public function name(): string
    {
        return 'phpantom';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'phpantom_lsp binary', 'text', '', hint: 'empty = phpantom_lsp on PATH');
        $api->registerLanguageServer('php', 'phpantom', function () use ($api): ?array {
            $binary = $this->binary($api);

            return null === $binary ? null : [$binary];
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
