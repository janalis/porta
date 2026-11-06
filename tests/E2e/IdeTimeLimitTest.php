<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\ProcessAlarm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;

/**
 * A test's time limit survives the IDE it boots.
 *
 * PHPUnit's `--enforce-time-limit` is a SIGALRM, and the freeze watchdog the
 * IDE starts used to take the alarm over and re-arm its own on every beat —
 * so no E2e test had a time limit at all, and a step that never finished held
 * its worker until somebody killed it (5.5 hours, once). This stands in for
 * PHPUnit with an alarm of its own, pending before the IDE starts, and a step
 * that would run far past it.
 */
final class IdeTimeLimitTest extends TestCase
{
    use PreservesPath;
    use RunsIde;

    public function testAnAlarmPendingWhenTheIdeStartsStillFiresInsideAStep(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $started = \hrtime(as_number: true);

        $caught = ProcessAlarm::borrow(function () use ($project): ?\Throwable {
            \pcntl_signal(\SIGALRM, static function (): never {
                throw new \RuntimeException('the time limit fired');
            });
            \pcntl_alarm(2);
            try {
                $this->runIde($project, $project->path('a.php'), [
                    200 => function (): void {
                        $this->pump(20.0); // a step that runs far past the limit
                    },
                ]);
            } catch (\Throwable $error) {
                return $error;
            }

            return null;
        });

        $this->assertInstanceOf(\Throwable::class, $caught, 'the step ran its whole 20s: the IDE swallowed the alarm');
        $said = [];
        for ($error = $caught; null !== $error; $error = $error->getPrevious()) {
            $said[] = $error->getMessage();
        }
        $this->assertStringContainsString('the time limit fired', \implode(' / ', $said));
        $this->assertLessThan(10.0, (\hrtime(as_number: true) - $started) / 1e9, 'and it fired at its deadline, not after the step');
    }
}
