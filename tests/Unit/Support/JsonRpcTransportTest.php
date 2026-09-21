<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Support\JsonRpcFramer;
use Porta\Support\JsonRpcTransport;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

final class JsonRpcTransportTest extends TestCase
{
    use PumpsLoop;

    public function testStopReapsAStragglerWithoutLeavingAZombie(): void
    {
        $project = new TempProject();
        $log = '';
        $transport = new JsonRpcTransport([\PHP_BINARY, __DIR__.'/../../Support/stubborn-lsp-server.php'], $project->dir, 'stubborn peer', static function (string $line) use (&$log): void {
            $log .= $line;
        });
        $this->assertTrue($transport->start(static function (): void {}));

        $process = Probe::get($transport, 'process');
        \assert(\is_resource($process), description: 'the transport holds the spawned process');
        $pid = \proc_get_status($process)['pid'];

        // The child says when its SIGTERM-ignore handler is installed, and that
        // is awaited: before this line the default disposition (die on SIGTERM)
        // is still in effect, so terminating the child here would exercise the
        // trivial "already dead" branch of the reaper instead of the
        // poll-until-dead one. A 0.3s usleep() stood in for this, which cost
        // 0.3s every run and — by its own comment — still exercised the wrong
        // branch whenever a loaded machine was slower than the guess.
        // By REFERENCE: an arrow fn would capture $log's value at creation and
        // never see the line arrive.
        $this->await(static function () use (&$log): bool {
            return \str_contains($log, 'sigterm-ignored');
        }, what: 'the child to ignore SIGTERM');
        $transport->stop();

        // The child ignores SIGTERM and only exits after its 1s sleep: give
        // the reaper's poll timer real time to reap it — $this->process being
        // cleared (an unconditional line) proves nothing about the OS process.
        $this->await(static fn (): bool => false === \posix_kill($pid, signal: 0), timeout: 3.0, what: 'the child to be reaped, not left a zombie');
    }

    public function testAFramerOverflowReachesTheLoggerNamedForThePeer(): void
    {
        $project = new TempProject();
        $lines = [];
        $transport = new JsonRpcTransport([\PHP_BINARY, '-v'], $project->dir, 'chatty peer', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $framer = Probe::object($transport, 'framer');
        Assert::assertInstanceOf(JsonRpcFramer::class, $framer, 'the transport owns a framer');
        $framer->feed(\str_repeat('x', JsonRpcFramer::MAX_FRAME + 1));

        $this->assertCount(1, $lines, 'the transport wires the framer overflow into its logger');
        $this->assertStringContainsString('chatty peer overflowed the read buffer', $lines[0]);
    }
}
