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
 * The shipped psalm plugin (.porta/plugins/psalm/plugin.php) against stub
 * analyser binaries — the real plugin file is loaded from the repo. Issues
 * surface as editor diagnostics and in the Problems panel; a clean run clears.
 */
final class PsalmPluginTest extends TestCase
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

    /** A project with the real plugin file and (optionally) a stub psalm. */
    private function project(?string $stubBody = null): TempProject
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/psalm/plugin.php');
        $project = new TempProject([
            '.porta/plugins/psalm/plugin.php' => $plugin,
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
        file_put_contents($project->path('vendor/bin/psalm'), '#!'.\PHP_BINARY.' -n'."\n<?php\n".$body);
        chmod($project->path('vendor/bin/psalm'), 0o755);
    }

    /** A stub reporting one error on 1-based line 2 of the analysed file, exit 2. */
    private function errorStub(): string
    {
        return <<<'PHP'
            echo json_encode([[
                'severity' => 'error',
                'type' => 'UndefinedVariable',
                'message' => 'Boom detected',
                'line_from' => 2,
                'column_from' => 1,
            ]]);
            exit(2);
            PHP;
    }

    public function testIssuesBecomeDiagnosticsAndACleanRunClearsThem(): void
    {
        $project = $this->project($this->errorStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x10"); // ctrl+alt+p → psalm:analyse
                },
                1400 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('psalm: 1 issue in a.php', $screen);
                    $this->assertStringContainsString('UndefinedVariable: Boom detected', $screen, 'the diagnostic renders inline in the editor');

                    // The Problems panel aggregates it like any LSP diagnostic.
                    $tui->handleInput("\x1b6"); // alt+6
                    $tui->tick();
                    $this->assertStringContainsString('✗ UndefinedVariable: Boom detected', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b"); // close the panel

                    // Swap in a clean stub and re-analyse: diagnostics clear.
                    $this->installStub($project, 'echo json_encode([]); exit(0);');
                    $tui->handleInput("\x1b\x10");
                },
                2600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('psalm: no issues in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
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
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('psalm:toggle-on-save');
                    $tui->tick();
                    $this->assertStringContainsString('psalm on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['psalm']['autoLint'] ?? false), 'the toggle persisted to .porta.json');

                    // The persisted setting shows on the plugin's settings page.
                    $rows = new \ReflectionProperty($ide, 'locator')
                        ->getValue($ide)
                        ->get(\Porta\Plugin\PluginHost::class)
                        ->settings()
                        ->rows('psalm');
                    $byKey = array_column($rows, null, 'key');
                    $this->assertArrayHasKey('pluginSetting:autoLint', $byKey, 'the on-save option is a settings row');
                    $this->assertSame('Analyse on save', $byKey['pluginSetting:autoLint']->label);
                    $this->assertSame('bool', $byKey['pluginSetting:autoLint']->kind);

                    $tui->handleInput("\x13"); // ctrl+s → save → analyse
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('psalm: 1 issue in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'saving analysed the file');
                },
            ],
            1.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testPersistedAutoLintAnalysesOnSaveAcrossRestarts(): void
    {
        // A .porta.json seeded with autoLint=true behaves as if the toggle had
        // been flipped in an earlier session: the first save analyses, no toggle needed.
        $project = $this->project($this->errorStub());
        $project->write('.porta.json', (string) json_encode(['plugins' => ['psalm' => ['autoLint' => true]]]));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x13"); // ctrl+s → save → analyse (no toggle this session)
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('psalm: 1 issue in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the persisted setting survived the restart');
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
                    $tui->handleInput("\x1b\x10");
                    $tui->tick();
                    $this->assertStringContainsString('psalm not found (vendor/bin or PATH)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
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
                    $tui->handleInput("\x1b\x10");
                    $tui->tick();
                    $this->assertStringContainsString('psalm: open a PHP file first', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.5,
        );

        // An internal psalm failure (no JSON, e.g. missing psalm.xml) reports the code.
        $project = $this->project("fwrite(STDERR, 'Could not locate a config XML file'); exit(1);");
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x10");
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('psalm: exited with code 1', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.7,
        );

        $this->addToAssertionCount(1);
    }
}
