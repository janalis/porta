<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\IsolatesPluginDiscovery;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Two language servers on ONE languageId, through a real IDE.
 *
 * Porta routed one server per language, and which plugin got `php` came down
 * to load order — the directory glob, so alphabetical. Symfony's language
 * tools is built to run BESIDE a general PHP server and lost the claim because
 * `symfony-lsp` sorts after `phpantom`; it is a declared ROLE now.
 *
 * Fixture plugins rather than the bundled ones, because this is about the
 * routing and not about anybody's binary being installed.
 */
final class IdeLspMultiServerTest extends TestCase
{
    use ClearsPortaLsp;
    use IsolatesPluginDiscovery;
    use PreservesPath;
    use RunsIde;

    /**
     * Two fixture plugins claiming php — one as the language's own server, one
     * beside it — each logging the wire messages it receives.
     *
     * The supplementary one is named to sort BEFORE the primary, so a pass
     * would prove nothing if load order still decided this.
     */
    private static function pluginsClaimingPhp(string $ownLog, string $besideLog): TempProject
    {
        $server = \dirname(__DIR__).'/Support/logging-lsp-server.php';
        $php = \PHP_BINARY;

        return new TempProject([
            'zeta/plugin.php' => <<<PHP
                <?php
                use Porta\\Plugin\\Api\\PluginApi;
                use Porta\\Plugin\\Api\\PortaPlugin;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'zeta';
                    }

                    public function activate(PluginApi \$api): void
                    {
                        \$api->registerLanguageServer('php', 'zeta-ls', static fn (): array => ['{$php}', '{$server}', '{$ownLog}']);
                    }
                };
                PHP,
            'alpha/plugin.php' => <<<PHP
                <?php
                use Porta\\Plugin\\Api\\PluginApi;
                use Porta\\Plugin\\Api\\PortaPlugin;
                use Porta\\Plugin\\Api\\ServerRole;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'alpha';
                    }

                    public function activate(PluginApi \$api): void
                    {
                        \$api->registerLanguageServer(
                            'php',
                            'alpha-ls',
                            static fn (): array => ['{$php}', '{$server}', '{$besideLog}'],
                            null,
                            ServerRole::Supplementary,
                        );
                    }
                };
                PHP,
        ]);
    }

    /**
     * Whether $log holds a message of $method yet.
     *
     * Matched on the bare verb, because the log is `json_encode`d and that
     * escapes the slash — a search for `textDocument/didOpen` never matches
     * `textDocument\/didOpen` and waits out its whole timeout saying nothing.
     */
    private static function saw(string $log, string $method): bool
    {
        return \is_file($log) && \str_contains((string) \file_get_contents($log), '\\/'.$method.'"');
    }

    public function testBothServersAreLaunchedAndFedAndTheOwnOneLeads(): void
    {
        $logs = new TempProject();
        $own = $logs->path('own.log');
        $beside = $logs->path('beside.log');
        $plugins = self::pluginsClaimingPhp($own, $beside);
        \putenv('PORTA_EMBEDDED_PLUGINS='.$plugins->dir);
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['zeta', 'alpha']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => function (Tui $tui, VirtualTerminal $terminal) use ($own, $beside): void {
                    // The on-disk wire logs are the effect to await, not the
                    // status bar: both servers are the same binary, so a
                    // painted name says nothing about which one is up.
                    // The on-disk wire logs are the effect to await, not the
                    // status bar: both servers are the same binary, so a
                    // painted name says nothing about which one is up.
                    $this->await(
                        static fn (): bool => self::saw($own, 'didOpen') && self::saw($beside, 'didOpen'),
                        what: 'both servers to be told the file is open',
                    );

                    $this->awaitPainted($tui, $terminal, 'LSP:zeta-ls +alpha-ls');
                    $this->assertStringContainsString(
                        'LSP:zeta-ls +alpha-ls',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'the status cell names both, the language\'s own server first — and alpha sorts first, so this is the ROLE talking',
                    );
                },
                700 => function (Tui $tui) use ($own, $beside): void {
                    // An edit and a save reach both. Half a lifecycle is worse
                    // than none: a server holding text from three keystrokes
                    // ago answers about a document the user has moved on from,
                    // which reads as a wrong answer rather than as no answer.
                    $tui->handleInput('x');
                    $tui->handleInput("\x13"); // ctrl+s
                    $this->await(
                        static fn (): bool => self::saw($own, 'didSave') && self::saw($beside, 'didSave'),
                        what: 'both servers to be told the file was saved',
                    );
                },
            ],
        );
    }

    /**
     * Two fixture plugins claiming php with servers that NAME their
     * completion items after themselves.
     */
    private static function pluginsCompletingPhp(): TempProject
    {
        $server = \dirname(__DIR__).'/Support/labelled-lsp-server.php';
        $php = \PHP_BINARY;

        return new TempProject([
            'zeta/plugin.php' => <<<PHP
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'zeta';
                    }

                    public function activate(PluginApi \$api): void
                    {
                        \$api->registerLanguageServer('php', 'zeta-ls', static fn (): array => ['{$php}', '{$server}', 'zeta']);
                    }
                };
                PHP,
            'alpha/plugin.php' => <<<PHP
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;
                use Porta\Plugin\Api\ServerRole;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'alpha';
                    }

                    public function activate(PluginApi \$api): void
                    {
                        \$api->registerLanguageServer(
                            'php',
                            'alpha-ls',
                            static fn (): array => ['{$php}', '{$server}', 'alpha'],
                            null,
                            ServerRole::Supplementary,
                        );
                    }
                };
                PHP,
        ]);
    }

    /**
     * One popup, both servers' items.
     *
     * This is what a second server on a language is FOR: a Symfony server's
     * route names beside a PHP server's methods, in the list the user is
     * already looking at.
     */
    public function testOnePopupCarriesItemsFromBothServers(): void
    {
        $plugins = self::pluginsCompletingPhp();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$plugins->dir);
        $project = new TempProject([
            'a.php' => "<?php\n\$x = st\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['zeta', 'alpha']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:zeta-ls +alpha-ls');

                    Widgets::editor($tui)->getDocument()->setCursor(1, 7);
                    $tui->handleInput("\x00"); // ctrl+space

                    // Awaited, not asserted after a gap: the two answers land
                    // independently, so the popup holds one item before it
                    // holds both.
                    $this->awaitPainted($tui, $terminal, 'alpha_item');
                    $this->awaitPainted($tui, $terminal, 'zeta_item');
                },
            ],
        );
    }
}
