<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * phpactor as a PHP language server. Registers `phpactor language-server` for
 * PHP; the binary resolves from the plugin's `binary` setting, then
 * vendor/bin/phpactor, then the PATH ({@see AbstractToolPlugin}). When no
 * binary is found the resolver returns null, so PHP is simply unserved (and
 * the "install" hint shows) rather than launching a broken process. Bundled
 * but disabled by default — phpantom is the suggested PHP server; enable this
 * to use phpactor instead.
 */
return new class('phpactor', 'phpactor') extends AbstractToolPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'phpactor/phpactor']]);
        $api->registerLanguageServer('php', 'phpactor', function () use ($api): ?array {
            $binary = $this->binary($api);

            return null === $binary ? null : [$binary, 'language-server'];
        });
    }
};
