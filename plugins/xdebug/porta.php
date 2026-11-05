<?php

declare(strict_types=1);

use Porta\Config\PortaHome;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Xdebug\AdapterInstaller;
use Porta\Xdebug\AdapterLocator;
use Porta\Xdebug\AdapterSetup;

/**
 * PHP debugging with Xdebug, set up in one step: this supplies the debug
 * adapter for php — vscode-php-debug, run with node — so Shift+F9 debugs the
 * PHP file in front with nothing written into `.porta.json`.
 *
 * The adapter is the one the user named, else the copy installed from the
 * plugin's Settings page, else the php-debug extension an editor of the VS
 * Code family already installed. The Install button fetches one pinned
 * release, checked against the SHA-256 in {@see AdapterLocator}. An adapter a
 * user configured in `.porta.json` still wins over this one.
 */
return new class('xdebug') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerSetting('adapter', 'php-debug adapter script', 'text', '', hint: 'empty = installed here, then the php-debug of VS Code, Cursor or VSCodium');
        $api->registerSetting('node', 'node binary', 'text', 'node', hint: 'runs the adapter');
        $locate = static fn (): AdapterLocator => new AdapterLocator(PortaHome::base(), PortaHome::expand((string) $api->setting('adapter')));
        $setup = new AdapterSetup(
            $api,
            $locate,
            static fn (string $target): AdapterInstaller => new AdapterInstaller(['curl', '-fsSL', '--proto', '=https'], AdapterLocator::URL, AdapterLocator::SHA256, $target),
        );

        $api->registerDebugAdapter('php', static function () use ($api, $locate): ?array {
            $script = $locate()->script();

            return null === $script ? null : [(string) $api->setting('node'), $script];
        });
        $api->registerCommand('install', 'Xdebug: Install the php-debug Adapter', $setup->install(...));
        $api->registerInstaller('php-debug', static fn (): bool => null !== $locate()->script(), [[PluginApi::INSTALL_SELF, 'install']], static fn (): string => $locate()->describe());
    }
};
