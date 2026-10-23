<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Tui;

/**
 * The scenario rig's own contract: every step a test hands {@see RunsIde::runIde()}
 * runs, or the test fails. Without it an IDE that stopped early skipped the
 * later steps — and their assertions — and the test passed on whatever ran.
 */
final class RunsIdeStepsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testAScenarioWhoseLastStepQuitsStillPasses(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.txt' => "one\n"]);

        $this->runIde($project, $project->path('a.txt'), [
            100 => static fn (Tui $tui) => $tui->stop(), // the terminator never runs, on purpose
        ]);
    }

    public function testAStepTheIdeNeverReachedFailsTheScenario(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.txt' => "one\n"]);
        $reached = false;

        try {
            $this->runIde($project, $project->path('a.txt'), [
                100 => static fn (Tui $tui) => $tui->stop(),
                200 => static function () use (&$reached): void {
                    $reached = true;
                },
            ]);
        } catch (ExpectationFailedException $failure) {
            $this->assertFalse($reached, 'the IDE stopped before the second step');
            $this->assertStringContainsString('every step ran', $failure->getMessage());

            return;
        }

        $this->fail('a step the IDE never reached passed silently');
    }
}
