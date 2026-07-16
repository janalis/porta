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
}
