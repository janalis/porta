<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\IsolatesPluginDiscovery;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The bundled PHP language-server plugins (phpantom/phpactor): a plugin
 * provides the PHP server via PluginApi::registerLanguageServer, its binary is
 * configurable on its settings page, and toggling it live re-resolves the LSP
 * servers — so the status bar flips between the server and the
 * "enable a PHP server in Settings" hint without a restart. When the binary is
 * missing, the plugin's Settings page offers a one-click install, and its CLI
 * powers the Fix / Generate Config commands.
 */
final class IdePhpLspPluginsTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;

    use IsolatesPluginDiscovery;
    use RunsIde;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /** An embedded dir holding only the real phpantom plugin (loads its actual code). */
    private function embeddedPhpantom(): TempProject
    {
        // The manifest too: the tool the Settings page offers is declared there.
        return new TempProject([
            'phpantom/plugin.php' => (string) \file_get_contents(__DIR__.'/../../plugins/phpantom/plugin.php'),
            'phpantom/composer.json' => (string) \file_get_contents(__DIR__.'/../../plugins/phpantom/composer.json'),
        ]);
    }

    public function testPhpantomProvidesThePhpServerAndTogglingItReconfiguresLive(): void
    {
        $embedded = $this->embeddedPhpantom();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        // The plugin's binary is set, so its resolver yields a php server.
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['phpantom'], 'plugins' => ['phpantom' => ['binary' => '/opt/phpantom_lsp']]], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('LSP:', $screen);
                    $this->assertStringNotContainsString('enable a PHP server', $screen, 'phpantom serves PHP, so no install hint');
                },
                450 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // Open the phpantom settings page: it lists the binary row.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugins');
                    $tui->handleInput("\x1b[B"); // phpantom sub-item
                    $this->awaitPainted($tui, $terminal, 'phpantom_lsp binary');
                    $this->assertStringContainsString('phpantom_lsp binary', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the binary setting shows on the page');

                    // Disable phpantom → the php server is dropped live.
                    $terminal->clearOutput();
                    $tui->handleInput("\t"); // nav → form
                    $tui->handleInput("\x1b[A"); // clamp to the Enabled row
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r"); // toggle off
                    $this->awaitPainted($tui, $terminal, 'Plugin "phpantom" disabled');
                    $this->assertStringContainsString('Plugin "phpantom" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'enable a PHP server in Settings');
                    $this->assertStringContainsString('enable a PHP server in Settings', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'disabling the provider unserves PHP → the hint returns');

                    // Re-enable → PHP is served again.
                    $terminal->clearOutput();
                    $tui->handleInput("\r"); // toggle back on
                    $this->awaitPainted($tui, $terminal, 'Plugin "phpantom" enabled');
                    $this->assertStringContainsString('Plugin "phpantom" enabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                750 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:');
                    $this->assertStringNotContainsString('enable a PHP server', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpantom serves PHP again');
                },
            ],
        );
    }

    public function testWithoutABinaryPhpantomIsSaidToBeNotFound(): void
    {
        $embedded = $this->embeddedPhpantom();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        // phpantom enabled but no binary configured and none on PATH → serves
        // nothing, and the status bar names it: enabled, its binary not there.
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => \json_encode(['enabledPlugins' => ['phpantom']], \JSON_THROW_ON_ERROR)]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:phpantom not found');
                    $this->assertStringContainsString('LSP:phpantom not found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testThePhpantomSettingsPageOffersToInstallTheBinary(): void
    {
        $embedded = $this->embeddedPhpantom();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => \json_encode(['enabledPlugins' => ['phpantom']], \JSON_THROW_ON_ERROR)]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugins');
                    $tui->handleInput("\x1b[B"); // phpantom sub-item
                    $this->awaitPainted($tui, $terminal, 'phpantom_lsp — the PHP language server');
                    $this->assertMatchesRegularExpression(
                        '/phpantom_lsp — the PHP language server\s+[│┆]\s+Install\s+[│┆]/u',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'the missing binary offers an install',
                    );
                },
            ],
        );
    }

    /** A project whose phpantom binary is a stub that fixes files and writes a config. */
    private function phpantomCliProject(): TempProject
    {
        $stub =
            '#!'
            .\PHP_BINARY
            .' -n'
            ."\n<?php\n"
            ."\$cmd = \$argv[1] ?? '';\n"
            ."if ('fix' === \$cmd && isset(\$argv[2])) { file_put_contents(\$argv[2], file_get_contents(\$argv[2]).\"// fixed\\n\"); }\n"
            ."if ('init' === \$cmd) { file_put_contents(getcwd().'/phpantom.json', \"{}\\n\"); }\n"
            ."exit(0);\n";
        $project = new TempProject(['a.php' => "<?php\n\$x=1;\n"]);
        $binPath = $project->path('phpantom_stub');
        \file_put_contents($binPath, $stub);
        \chmod($binPath, permissions: 0o755);
        \file_put_contents($project->path('.porta.json'), \json_encode(['enabledPlugins' => ['phpantom'], 'plugins' => ['phpantom' => ['binary' => $binPath]]], \JSON_THROW_ON_ERROR));

        return $project;
    }

    public function testFixAndInitCommandsDriveTheCli(): void
    {
        $embedded = $this->embeddedPhpantom();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = $this->phpantomCliProject();

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('phpantom:fix');
                },
                300 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpantom: fixed a.php'));
                    $tui->tick();
                    $this->assertStringContainsString('phpantom: fixed a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->await(static fn (): bool => \str_contains((string) (\is_file($project->path('a.php')) ? \file_get_contents($project->path('a.php')) : false), '// fixed'));
                    Services::resolve($ide, BufferService::class)
                        ->pollFilesystem();
                    $this->assertStringContainsString('// fixed', Widgets::editor($tui)->getDocument()->getText(), 'buffer reloaded with the fixed content');

                    Services::resolve($ide, CommandRegistry::class)->run('phpantom:init');
                },
                500 => function ($tui, $terminal) use ($project): void {
                    // Await the FLASH, not the file: the stub writes the config
                    // before it exits, so the file exists a moment before the
                    // exit callback flashes — and one tick() may not paint it.
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpantom: generated config'));
                    $this->assertStringContainsString('phpantom: generated config', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertFileExists($project->path('phpantom.json'));
                },
            ],
        );
    }
}
