<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Drives the live bottom-dock list tools through a real Ide: the Problems
 * tab refreshes in place as diagnostics land (and keeps its count fresh
 * while concealed), without stealing the focus.
 */
final class IdeDockToolsTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    private static function bufferService(mixed $ide): BufferService
    {
        /** @var BufferService */
        return new \ReflectionProperty(Ide::class, 'locator')->getValue($ide)->get(BufferService::class);
    }

    public function testProblemsTabRefreshesLiveAsDiagnosticsLand(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// two\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Show Problems
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen, 'the Problems tab opened on the dock strip');
                    $this->assertStringContainsString('0 results', $screen, 'no diagnostics yet');
                },
                350 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->setFocus($tui->getById('editor'));
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [1 => [['severity' => 1, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]]],
                        );
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '✗ live boom'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('✗ live boom', $screen, 'the open Problems tab refreshed in place');
                    $this->assertStringContainsString('1 results', $screen, 'the strip count followed');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the live refresh did not steal the focus');
                },
                500 => function ($tui, $terminal, $ide) use ($project): void {
                    // Concealed (Esc-hidden dock): the tab still refreshes, so
                    // revealing it shows the fresh rows.
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b"); // esc → hide the dock
                    self::bufferService($ide)
                        ->applyDiagnostics(
                            Uri::fromPath($project->path('a.php')),
                            [
                                0 => [['severity' => 2, 'message' => 'warn one', 'col' => 0, 'endCol' => 3]],
                                1 => [['severity' => 1, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]],
                            ],
                        );
                    $tui->tick();
                    $this->assertFalse($tui->getById('results')->isVisible(), 'the dock is hidden');
                    $tui->handleInput("\x1b6"); // alt+6 → reveal Problems again
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('▲ warn one', $screen, 'the concealed tab picked up the second diagnostic');
                    $this->assertStringContainsString('2 results', $screen);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTodoScanListsJumpsAndRerunsWithF5(): void
    {
        putenv('PATH='.$this->originalPath); // real grep for the async scan
        $project = new TempProject([
            'src/A.php' => "<?php\n// TODO extract this\n",
            'src/B.py' => "# FIXME races\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b8"); // alt+8 → Show TODOs
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'FIXME races'));
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('TODO extract this', $screen, 'the scan found both markers');
                    $this->assertStringContainsString('2 results', $screen);
                    // The list is path-sorted: src/A.php first, selected. Enter jumps.
                    $tui->handleInput("\r");
                },
                400 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    // grep's row order is directory-walk order, so the first
                    // (auto-selected) row may be either file.
                    $this->assertMatchesRegularExpression('~src/(A\.php|B\.py)$~', (string) $tui->getById('editor')->getFilePath(), 'Enter jumped to a TODO match');
                    // F5 re-scans and picks up a marker added on disk meanwhile.
                    $project->write('src/C.php', "<?php\n// TODO added later\n");
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b[15~"); // F5
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '3 results'));
                    $this->assertStringContainsString('TODO added later', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the rerun saw the new file');
                },
                700 => function ($tui, $terminal): void {
                    // Stale completion: kick a scan and immediately repurpose
                    // the panel — the late grep result must not clobber it.
                    $tui->handleInput("\x1b8"); // alt+8 → scan starts
                    $tui->handleInput("\x1b6"); // alt+6 → Problems takes the panel before grep lands
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Problems'));
                },
                1000 => function ($tui): void {
                    // Assert on the widget, not the accumulated terminal
                    // output (which still carries every earlier frame).
                    $tui->tick();
                    $results = $tui->getById('results');
                    $this->assertSame('Problems', $results->getTitle(), 'the Problems tab kept the panel');
                    $this->assertStringContainsString('0 results', $results->statusText(), 'the stale TODO completion did not overwrite the rows');
                    $panel = AnsiUtils::stripAnsiCodes(implode("\n", $results->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12))));
                    $this->assertStringNotContainsString('FIXME races', $panel, 'the stale TODO completion was dropped');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
