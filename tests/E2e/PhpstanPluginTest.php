<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The shipped phpstan plugin (.porta/plugins/phpstan/plugin.php) against stub
 * analyser binaries — the real plugin file is loaded from the repo. Errors
 * surface as editor diagnostics and in the Problems panel; a clean run clears.
 */
final class PhpstanPluginTest extends TestCase
{
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

    /**
     * @param array<int, callable> $steps ms => step($tui, $terminal, $ide)
     */
    private function runIde(TempProject $project, ?string $file, array $steps, ?float $duration = null): VirtualTerminal
    {
        $duration ??= max([] === $steps ? 0.0 : (max(array_keys($steps)) / 1000) + 0.5, 0.7);

        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        $ide = new Ide($project->dir, $file, $terminal);
        $tui = $ide->getTui();

        $timers = [];
        foreach ($steps as $delayMs => $step) {
            $timers[] = EventLoop::delay((int) $delayMs / 1000, static fn () => $step($tui, $terminal, $ide));
        }
        $timers[] = EventLoop::delay($duration, static fn () => $tui->stop());

        try {
            $ide->run();
        } finally {
            foreach ($timers as $timer) {
                EventLoop::cancel($timer);
            }
        }

        return $terminal;
    }

    /** A project with the real plugin file and (optionally) a stub phpstan. */
    private function project(?string $stubBody = null): TempProject
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/phpstan/plugin.php');
        $project = new TempProject([
            '.porta/plugins/phpstan/plugin.php' => $plugin,
            'a.php' => "<?php\n\$x = 1;\n\$y = 2;\n",
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
        file_put_contents($project->path('vendor/bin/phpstan'), '#!'.\PHP_BINARY.' -n'."\n<?php\n".$body);
        chmod($project->path('vendor/bin/phpstan'), 0o755);
    }

    /** A stub reporting one error on 1-based line 2 of the analysed file, exit 1. */
    private function errorStub(): string
    {
        return <<<'PHP'
            $file = $argv[count($argv) - 1];
            echo json_encode(['files' => [$file => ['messages' => [
                ['message' => 'Boom detected', 'line' => 2, 'ignorable' => true],
            ]]], 'errors' => []]);
            exit(1);
            PHP;
    }

    public function testErrorsBecomeDiagnosticsAndACleanRunClearsThem(): void
    {
        $project = $this->project($this->errorStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x01"); // ctrl+alt+a → phpstan:analyse
                },
                1400 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('phpstan: 1 error in a.php', $screen);
                    $this->assertStringContainsString('Boom detected', $screen, 'the diagnostic renders inline in the editor');

                    // The Problems panel aggregates it like any LSP diagnostic.
                    $tui->handleInput("\x1b6"); // alt+6
                    $tui->tick();
                    $this->assertStringContainsString('✗ Boom detected', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b"); // close the panel

                    // Swap in a clean stub and re-analyse: diagnostics clear.
                    $this->installStub($project, "echo json_encode(['files' => [], 'errors' => []]); exit(0);");
                    $tui->handleInput("\x1b\x01");
                },
                2600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('phpstan: no errors in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $editorScreen = implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    $this->assertStringNotContainsString('Boom detected', AnsiUtils::stripAnsiCodes($editorScreen), 'the clean run cleared the diagnostic');
                },
            ],
            2.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testAnalyseOnSaveToggle(): void
    {
        $project = $this->project($this->errorStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('phpstan:toggle-on-save');
                    $tui->tick();
                    $this->assertStringContainsString('phpstan on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['phpstan']['autoLint'] ?? false), 'the toggle persisted to .porta.json');
                    $tui->handleInput("\x13"); // ctrl+s → save → analyse
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('phpstan: 1 error in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'saving analysed the file');
                },
            ],
            1.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testGuardsForMissingBinaryNonPhpFilesAndInternalFailures(): void
    {
        $project = $this->project(); // no stub, PATH is empty
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x01");
                    $tui->tick();
                    $this->assertStringContainsString('phpstan not found (vendor/bin or PATH)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.5,
        );

        $project = $this->project($this->errorStub());
        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x01");
                    $tui->tick();
                    $this->assertStringContainsString('phpstan: open a PHP file first', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.5,
        );

        // An internal phpstan failure (no JSON, exit 255) reports the code.
        $project = $this->project("fwrite(STDOUT, 'fatal'); exit(255);");
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x01");
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('phpstan: exited with code 255', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.7,
        );

        $this->addToAssertionCount(1);
    }
}
