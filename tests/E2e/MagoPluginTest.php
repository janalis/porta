<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The shipped mago plugin (.porta/plugins/mago/plugin.php) against a stub
 * binary that serves both faces: `format` rewrites the file, `lint` emits the
 * JSON issue report. The real plugin file is loaded from the repo.
 */
final class MagoPluginTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    private function project(?string $stubBody = null): TempProject
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/mago/plugin.php');
        $project = new TempProject([
            '.porta/plugins/mago/plugin.php' => $plugin,
            'a.php' => "<?php\n\$x = 1;\n",
            'notes.txt' => "plain\n",
        ]);

        if (null !== $stubBody) {
            $this->installStub($project, $stubBody);
        }

        return $project;
    }

    private function installStub(TempProject $project, string $body): void
    {
        // PATH is /nonexistent, so the shebang must point at the php binary directly.
        @mkdir($project->path('vendor/bin'), 0777, true);
        file_put_contents($project->path('vendor/bin/mago'), '#!'.\PHP_BINARY.' -n'."\n<?php\n".$body);
        chmod($project->path('vendor/bin/mago'), 0o755);
    }

    /** A stub handling both subcommands: format appends a marker, lint reports one Error on 0-based line 1. */
    private function dualStub(): string
    {
        return <<<'PHP'
            $file = $argv[count($argv) - 1];
            if ('format' === $argv[1]) {
                file_put_contents($file, file_get_contents($file)."// formatted\n");
                exit(0);
            }
            echo json_encode(['issues' => [[
                'level' => 'Error',
                'code' => 'test-rule',
                'message' => 'Bad thing',
                'annotations' => [['kind' => 'Primary', 'span' => ['start' => ['offset' => 7, 'line' => 1]]]],
            ]]]);
            exit(0);
            PHP;
    }

    public function testFormatCommandRewritesAndThePollerReloads(): void
    {
        $project = $this->project($this->dualStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x07"); // ctrl+alt+g → mago:format
                },
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: formatted a.php'));
                    $tui->tick();
                    $this->assertStringContainsString('mago: formatted a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->await(fn (): bool => str_contains((string) @file_get_contents($project->path('a.php')), '// formatted'));
                    $this->assertStringContainsString('// formatted', (string) file_get_contents($project->path('a.php')));

                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->pollFilesystem();
                    $this->assertStringContainsString('// formatted', $tui->getById('editor')->getDocument()->getText(), 'buffer reloaded');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testLintPublishesDiagnosticsAndACleanRunClears(): void
    {
        $project = $this->project($this->dualStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x05"); // ctrl+alt+e → mago:lint
                },
                300 => function ($tui, $terminal) use ($project): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: 1 issue in a.php'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('mago: 1 issue in a.php', $screen);
                    $this->assertStringContainsString('test-rule: Bad thing', $screen, 'the diagnostic renders inline');

                    $tui->handleInput("\x1b6"); // alt+6 → Problems panel
                    $tui->tick();
                    $this->assertStringContainsString('✗ test-rule: Bad thing', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b");

                    $this->installStub($project, "echo json_encode(['issues' => []]); exit(0);");
                    $tui->handleInput("\x1b\x05");
                },
                450 => function ($tui, $terminal): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: no issues in a.php'));
                    $tui->tick();
                    $this->assertStringContainsString('mago: no issues in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $editorScreen = implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    $this->assertStringNotContainsString('Bad thing', AnsiUtils::stripAnsiCodes($editorScreen), 'clean lint cleared the diagnostic');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testOnSaveTogglesChainFormatThenLint(): void
    {
        $project = $this->project($this->dualStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($project): void {
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $commands->run('mago:toggle-auto-format');
                    $tui->tick(); // render this flash before the next replaces it
                    $commands->run('mago:toggle-auto-lint');
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('mago format on save: on', $screen);
                    $this->assertStringContainsString('mago lint on save: on', $screen);
                    $plugins = \Porta\Config\IdeConfig::load($project->dir)->plugins['mago'] ?? [];
                    $this->assertTrue((bool) ($plugins['autoFormat'] ?? false), 'autoFormat persisted');
                    $this->assertTrue((bool) ($plugins['autoLint'] ?? false), 'autoLint persisted');
                    $tui->handleInput("\x13"); // ctrl+s → save → format, then lint
                },
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->await(fn (): bool => str_contains((string) @file_get_contents($project->path('a.php')), '// formatted'));
                    $this->assertStringContainsString('// formatted', (string) file_get_contents($project->path('a.php')), 'save formatted the file');
                    $this->await(function () use ($tui, $terminal): bool {
                        $tui->tick();

                        return str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: 1 issue in a.php');
                    });
                    $this->assertStringContainsString('mago: 1 issue in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the chained lint ran on the formatted file');

                    // Toggle both off: the next save leaves the file alone.
                    $commands = new \ReflectionProperty($ide, 'commands')->getValue($ide);
                    $commands->run('mago:toggle-auto-format');
                    $commands->run('mago:toggle-auto-lint');
                    $tui->tick();
                    $this->assertStringContainsString('mago lint on save: off', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertFalse((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['mago']['autoFormat'] ?? true), 'off state persisted');
                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->pollFilesystem(); // absorb the change
                    $tui->handleInput("\x13");
                },
                450 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertSame(1, substr_count((string) file_get_contents($project->path('a.php')), '// formatted'), 'no format while toggled off');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testLintOnlyOnSavePublishesDiagnosticsWithoutFormatting(): void
    {
        $project = $this->project($this->dualStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('mago:toggle-auto-lint');
                    $tui->tick();
                    $this->assertStringContainsString('mago lint on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x13"); // ctrl+s → save → lint only
                },
                300 => function ($tui, $terminal) use ($project): void {
                    $this->await(function () use ($tui, $terminal): bool {
                        $tui->tick();

                        return str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: 1 issue in a.php');
                    });
                    $this->assertStringNotContainsString('// formatted', (string) file_get_contents($project->path('a.php')), 'autoFormat off: the file is untouched');
                    $this->assertStringContainsString('mago: 1 issue in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the lint ran on its own');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGuardsForMissingBinaryNonPhpFilesAndFailures(): void
    {
        $project = $this->project(); // no stub, empty PATH
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x07");
                    $tui->tick();
                    $this->assertStringContainsString('mago not found (vendor/bin or PATH)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $project = $this->project($this->dualStub());
        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x05");
                    $tui->tick();
                    $this->assertStringContainsString('mago: open a PHP file first', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // A failing format and a broken lint (garbage output) each report their code.
        $project = $this->project("if ('format' === \$argv[1]) { exit(9); } fwrite(STDOUT, 'garbage'); exit(7);");
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x07"); // format path → exit 9
                },
                300 => function ($tui, $terminal): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: exited with code 9'));
                    $tui->tick();
                    $this->assertStringContainsString('mago: exited with code 9', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b\x05"); // lint path → garbage JSON, exit 7
                },
                450 => function ($tui, $terminal): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'mago: exited with code 7'));
                    $tui->tick();
                    $this->assertStringContainsString('mago: exited with code 7', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the broken-lint path flashed too');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
