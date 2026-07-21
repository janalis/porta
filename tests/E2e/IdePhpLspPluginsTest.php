<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The bundled PHP language-server plugins (phpantom/phpactor): a plugin
 * provides the PHP server via PluginApi::registerLanguageServer, its binary is
 * configurable on its settings page, and toggling it live re-resolves the LSP
 * servers — so the status bar flips between the server and the
 * "install phpantom" hint without a restart.
 */
final class IdePhpLspPluginsTest extends TestCase
{
    use RunsIde;

    private string $originalPath;
    private string $originalEmbedded;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        $this->originalEmbedded = (string) getenv('PORTA_EMBEDDED_PLUGINS');
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_EMBEDDED_PLUGINS='.$this->originalEmbedded);
    }

    /** An embedded dir holding only the real phpantom plugin (loads its actual code). */
    private function embeddedPhpantom(): TempProject
    {
        return new TempProject(['phpantom/plugin.php' => (string) file_get_contents(__DIR__.'/../../.porta/plugins/phpantom/plugin.php')]);
    }

    public function testPhpantomProvidesThePhpServerAndTogglingItReconfiguresLive(): void
    {
        $embedded = $this->embeddedPhpantom();
        putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        // The plugin's binary is set, so its resolver yields a php server.
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => json_encode(['enabledPlugins' => ['phpantom'], 'plugins' => ['phpantom' => ['binary' => '/opt/phpantom_lsp']]]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('LSP:', $screen);
                    $this->assertStringNotContainsString('install phpantom', $screen, 'phpantom serves PHP, so no install hint');
                },
                450 => function ($tui, $terminal, $ide): void {
                    // Open the phpantom settings page: it lists the binary row.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $tui->handleInput("\x1b[B"); // Interface
                    $tui->handleInput("\x1b[B"); // Keybindings
                    $tui->handleInput("\x1b[B"); // Plugins
                    $tui->handleInput("\x1b[B"); // phpantom sub-item
                    $tui->tick();
                    $this->assertStringContainsString('phpantom_lsp binary', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the binary setting shows on the page');

                    // Disable phpantom → the php server is dropped live.
                    $terminal->clearOutput();
                    $tui->handleInput("\t"); // nav → form
                    $tui->handleInput("\x1b[A"); // clamp to the Enabled row
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r"); // toggle off
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "phpantom" disabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('install phpantom', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'disabling the provider unserves PHP → the install hint returns');

                    // Re-enable → PHP is served again.
                    $terminal->clearOutput();
                    $tui->handleInput("\r"); // toggle back on
                    $tui->tick();
                    $this->assertStringContainsString('Plugin "phpantom" enabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
                750 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringNotContainsString('install phpantom', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpantom serves PHP again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testWithoutABinaryPhpantomSuggestsInstalling(): void
    {
        $embedded = $this->embeddedPhpantom();
        putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        // phpantom enabled but no binary configured and none on PATH → serves nothing.
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => json_encode(['enabledPlugins' => ['phpantom']])]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('LSP:none (install phpantom)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
