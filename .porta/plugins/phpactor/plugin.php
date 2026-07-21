<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Symfony\Component\Process\ExecutableFinder;

/**
 * phpactor as a PHP language server. Registers `phpactor language-server` for
 * PHP; the binary resolves from the plugin's `binary` setting, then
 * vendor/bin/phpactor, then the PATH. When no binary is found the resolver
 * returns null, so PHP is simply unserved (and the "install" hint shows)
 * rather than launching a broken process. Bundled but disabled by default —
 * phpantom is the suggested PHP server; enable this to use phpactor instead.
 */
return new class implements PortaPlugin {
    public function name(): string
    {
        return 'phpactor';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'phpactor binary', 'text', '', hint: 'empty = vendor/bin, then PATH');
        $api->registerComposerDependency('phpactor/phpactor', fn (): bool => null !== $this->binary($api));
        $api->registerLanguageServer('php', 'phpactor', function () use ($api): ?array {
            $binary = $this->binary($api);

            return null === $binary ? null : [$binary, 'language-server'];
        });
    }

    private function binary(PluginApi $api): ?string
    {
        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        $local = $api->projectRoot().'/vendor/bin/phpactor';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        return new ExecutableFinder()->find('phpactor');
    }
};
