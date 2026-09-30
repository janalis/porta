<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\SingleSlotProcess;
use Porta\Tests\Support\PumpsLoop;

/**
 * One child at a time, latest wins, success keyed on the exit code. Core's own
 * test of the slot the graph and image viewer plugins run their converters in.
 */
final class SingleSlotProcessTest extends TestCase
{
    use PumpsLoop;

    /**
     * @param list<string> $argv
     *
     * @return array{list<string>, list<string>} the successes and the failures reported
     */
    private function runToEnd(SingleSlotProcess $slot, array $argv, ?string $stdin = null): array
    {
        $ok = [];
        $failed = [];
        $slot->run(
            $argv,
            (string) \getcwd(),
            $stdin,
            'dot',
            static function (string $out) use (&$ok): void {
                $ok[] = $out;
            },
            static function (string $why) use (&$failed): void {
                $failed[] = $why;
            },
        );
        $this->await(static function () use (&$ok, &$failed): bool {
            return [] !== $ok || [] !== $failed;
        }, what: 'the process to report');

        return [$ok, $failed];
    }

    public function testExitZeroReportsStdoutEvenWithStderrChatter(): void
    {
        [$ok, $failed] = $this->runToEnd(new SingleSlotProcess(), [\PHP_BINARY, '-r', 'fwrite(STDERR, "warning\n"); echo strtoupper(stream_get_contents(STDIN));'], 'graph');

        $this->assertSame(['GRAPH'], $ok, 'stdin was fed, and stderr alone is not a failure');
        $this->assertSame([], $failed);
    }

    public function testANonZeroExitReportsTheTrimmedStderr(): void
    {
        [$ok, $failed] = $this->runToEnd(new SingleSlotProcess(), [\PHP_BINARY, '-r', 'fwrite(STDERR, "  syntax error on line 3\n"); exit(1);']);

        $this->assertSame([], $ok);
        $this->assertSame(['syntax error on line 3'], $failed);
    }

    public function testANonZeroExitWithNothingOnStderrNamesTheLabelAndCode(): void
    {
        [, $failed] = $this->runToEnd(new SingleSlotProcess(), [\PHP_BINARY, '-r', 'exit(4);']);

        $this->assertSame(['dot failed (exit 4)'], $failed);
    }

    public function testStartingAnotherRunCancelsTheOneStillInTheSlot(): void
    {
        $slot = new SingleSlotProcess();
        $first = [];
        $slot->run(
            [\PHP_BINARY, '-r', 'usleep(300000); echo "stale";'],
            (string) \getcwd(),
            null,
            'dot',
            static function (string $out) use (&$first): void {
                $first[] = $out;
            },
            static function (string $why) use (&$first): void {
                $first[] = 'failed: '.$why;
            },
        );

        [$ok] = $this->runToEnd($slot, [\PHP_BINARY, '-r', 'echo "fresh";']);
        $this->pump(0.4);

        $this->assertSame(['fresh'], $ok);
        $this->assertNotContains('stale', $first, 'the cancelled run never reports its answer as a success');
    }

    public function testCancelStopsTheRunInFlightAndIsIdempotent(): void
    {
        $slot = new SingleSlotProcess();
        $reported = [];
        $slot->run(
            [\PHP_BINARY, '-r', 'usleep(300000); echo "late";'],
            (string) \getcwd(),
            null,
            'dot',
            static function (string $out) use (&$reported): void {
                $reported[] = $out;
            },
            static function (string $why) use (&$reported): void {
                $reported[] = 'failed: '.$why;
            },
        );

        $slot->cancel();
        $slot->cancel();
        $this->pump(0.4);

        $this->assertNotContains('late', $reported);
    }
}
