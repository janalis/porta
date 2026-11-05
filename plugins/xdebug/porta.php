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
        // Named …Path and …Binary on purpose: both name a program the debugger
        // runs, and WorkspaceTrust drops settings so named from a restricted
        // project's own .porta.json — a project the user has not trusted
        // must not choose what runs.
        $api->registerSetting('adapterPath', 'php-debug adapter script', 'text', '', hint: 'empty = installed here, then the php-debug of VS Code, Cursor or VSCodium');
        $api->registerSetting('nodeBinary', 'node binary', 'text', 'node', hint: 'runs the adapter');
        $locate = static fn (): AdapterLocator => new AdapterLocator(PortaHome::base(), PortaHome::expand((string) $api->setting('adapterPath')));
        $setup = new AdapterSetup(
            $api,
            $locate,
            static fn (string $target): AdapterInstaller => new AdapterInstaller(['curl', '-fsSL', '--proto', '=https'], AdapterLocator::URL, AdapterLocator::SHA256, $target),
        );

        $api->registerDebugAdapter('php', static function () use ($api, $locate): ?array {
            $script = $locate()->script();

            return null === $script ? null : [(string) $api->setting('nodeBinary'), $script];
        });
        $api->registerCommand('install', 'Xdebug: Install the php-debug Adapter', $setup->install(...));
        // A web request, or a CLI run started elsewhere, connects to the adapter: nothing is run for it.
        $api->registerCommand('listen', 'Xdebug: Start Listening for Connections', static fn () => $api->runs()->debugger()->listen('php'));
        $api->addMenuItem('listen');
        $api->registerInstaller('php-debug', static fn (): bool => null !== $locate()->script(), [[PluginApi::INSTALL_SELF, 'install']], static fn (): string => $locate()->describe());
    }
};
