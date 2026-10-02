<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DapClient;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;

final class DapClientTest extends TestCase
{
    use PumpsLoop;

    /** @var list<DapClient> */
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stop();
        }
        $this->clients = [];
    }

    private function client(string $adapter = 'mock-dap-adapter.php', ?callable $logger = null): DapClient
    {
        return $this->clients[] = new DapClient(
            [\PHP_BINARY, __DIR__.'/../Support/'.$adapter],
            \dirname(__DIR__, levels: 2),
            $logger,
        );
    }

    /** Run the event loop until $done() is true or the timeout elapses. */
    public function testFullLifecycleAgainstTheMockAdapter(): void
    {
        $log = [];
        $client = $this->client(logger: static function (string $line) use (&$log): void {
            $log[] = $line;
        });

        $events = [];
        foreach (['initialized', 'output', 'stopped', 'terminated', 'exited', 'reverseRejected'] as $name) {
            $client->onEvent($name, static function (array $body) use (&$events, $name): void {
                $events[] = [$name, $body];
            });
        }

        $this->assertTrue($client->start());
        $this->assertTrue($client->isRunning());

        $capabilities = null;
        $client->request('initialize', ['clientID' => 'porta'], static function (array $body, ?string $error) use (&$capabilities): void {
            $capabilities = $body;
        });
        $this->await(static function () use (&$capabilities): bool {
            return null !== $capabilities;
        });
        $this->assertTrue($capabilities['supportsConfigurationDoneRequest'] ?? false);

        $this->await(static function () use (&$events): bool {
            return \in_array('initialized', \array_column($events, 0), strict: true);
        });
        $this->await(static function () use (&$events): bool {
            return \in_array('reverseRejected', \array_column($events, 0), strict: true);
        }, 2.0);
        $this->assertContains('reverseRejected', \array_column($events, 0), 'the client rejected the reverse request');

        $verified = null;
        $client->request(
            'setBreakpoints',
            [
                'source' => ['path' => '/tmp/prog.php'],
                'breakpoints' => [['line' => 5]],
            ],
            static function (array $body) use (&$verified): void {
                $verified = $body['breakpoints'] ?? null;
            },
        );
        $client->request('launch', ['program' => '/tmp/prog.php']);
        $client->request('configurationDone');

        $this->await(static function () use (&$events): bool {
            return \in_array('stopped', \array_column($events, 0), strict: true);
        });
        $stopped = \array_first(\array_filter($events, static fn (array $e): bool => 'stopped' === $e[0]))[1];
        $this->assertSame('breakpoint', $stopped['reason']);
        $this->assertSame([['verified' => true, 'line' => 5]], $verified);
        $this->assertContains('output', \array_column($events, 0), 'debuggee stdout arrived as an output event');

        $frames = null;
        $client->request('stackTrace', ['threadId' => 1], static function (array $body) use (&$frames): void {
            $frames = $body['stackFrames'] ?? null;
        });
        $this->await(static function () use (&$frames): bool {
            return null !== $frames;
        });
        $this->assertSame(5, $frames[0]['line']);
        $this->assertSame('{main}', $frames[0]['name']);

        $client->request('continue', ['threadId' => 1]);
        $this->await(static function () use (&$events): bool {
            return \in_array('terminated', \array_column($events, 0), strict: true);
        });
        $this->assertContains('exited', \array_column($events, 0));

        $this->await(static function () use (&$log): bool {
            return [] !== $log;
        });
        $this->assertContains('mock-dap-adapter started', $log, 'stderr reached the logger');

        $client->stop();
        $client->stop(); // idempotent
        $this->assertFalse($client->isRunning());
    }

    public function testRequestErrorsAreDeliveredAsMessages(): void
    {
        $client = $this->client();
        $this->assertTrue($client->start());

        $done = false;
        $client->request('initialize', [], static function () use (&$done): void {
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        });

        // stackTrace with no launch → currentLine 0… force the scripted error via line >= 90:
        $client->request('setBreakpoints', ['source' => ['path' => '/x'], 'breakpoints' => [['line' => 95]]]);
        $client->request('launch', ['program' => '/x']);
        $client->request('configurationDone');
        $stoppedSeen = false;
        $client->onEvent('stopped', static function () use (&$stoppedSeen): void {
            $stoppedSeen = true;
        });
        $this->await(static function () use (&$stoppedSeen): bool {
            return $stoppedSeen;
        });

        $error = null;
        $client->request('stackTrace', ['threadId' => 1], static function (array $body, ?string $message) use (&$error): void {
            $error = $message;
        });
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        });
        $this->assertSame('no stack available', $error);
    }

    public function testACrashingAdapterFiresOnCloseOnce(): void
    {
        $client = $this->client('crashing-dap-adapter.php');
        $closed = 0;
        $client->onClose(static function () use (&$closed): void {
            ++$closed;
        });

        $this->assertTrue($client->start());
        $this->await(static function () use (&$closed): bool {
            return $closed > 0;
        });
        $this->assertSame(1, $closed);
    }

    public function testStartFailsForAnUnspawnableCommand(): void
    {
        $client = new DapClient([''], \dirname(__DIR__, levels: 2));
        $this->assertFalse($client->start());
        $client->stop();

        $missing = new DapClient(['/nonexistent-porta-debug-adapter'], \dirname(__DIR__, levels: 2));
        $this->assertFalse($missing->start(), 'a missing binary fails the spawn');
        $missing->stop();
    }

    public function testStopSkipsAnAlreadyClosedPipe(): void
    {
        $client = $this->client();
        $this->assertTrue($client->start());

        // Close stderr behind the client's back: stop() must skip the now-dead
        // resource in its pipe-closing loop and still close the others cleanly.
        $transport = Probe::get($client, 'transport');
        $stderr = Probe::get($transport, 'stderr');
        if (\is_resource($stderr)) {
            \fclose($stderr);
        }

        $client->stop();
        $this->assertFalse($client->isRunning());
    }

    public function testIoAfterStopAndFailedWritesAreSafeNoOps(): void
    {
        $log = [];
        $client = $this->client('crashing-dap-adapter.php', static function (string $line) use (&$log): void {
            $log[] = $line;
        });
        $closed = false;
        $client->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $this->assertTrue($client->start());
        $this->await(static function () use (&$closed): bool {
            return $closed;
        });

        // The adapter is dead: nothing is written to it, and a request fails at once.
        $answer = null;
        $client->request('initialize', [], static function (array $body, ?string $error) use (&$answer): void {
            $answer = $error;
        });
        $this->await(static function () use (&$answer): bool {
            return null !== $answer;
        }, 2.0);
        $this->assertSame('the debug adapter stopped', $answer);
        $this->assertSame([], \array_filter($log, static fn (string $line): bool => \str_contains($line, 'write to debug adapter failed')), 'nothing was written');

        $client->stop();
        $client->request('initialize'); // stopped, no callback → silent no-op

        // The internal reader guard: a drain after stop must not error.
        $transport = Probe::get($client, 'transport');
        Probe::invoke($transport, 'drainStdout', static function (): void {}, null);
        $this->assertFalse($client->isRunning());
    }

    public function testARequestNobodyAnswersFailsInsteadOfWaitingForever(): void
    {
        $client = new DapClient([\PHP_BINARY, __DIR__.'/../Support/mock-dap-adapter.php'], \dirname(__DIR__, levels: 2), requestTimeout: 0.2);
        $this->clients[] = $client;
        $this->assertTrue($client->start());
        $answer = null;

        $client->request('portaNever', [], static function (array $body, ?string $error) use (&$answer): void {
            $answer = $error;
        });

        $this->await(static function () use (&$answer): bool {
            return null !== $answer;
        }, what: 'the deadline');
        $this->assertSame('the debug adapter did not answer portaNever in 0.2 seconds', $answer);
    }

    public function testStoppingFailsWhatStillWaits(): void
    {
        $client = $this->client();
        $this->assertTrue($client->start());
        $answer = null;
        $client->request('portaNever', [], static function (array $body, ?string $error) use (&$answer): void {
            $answer = $error;
        });

        $client->stop();
        $this->assertNull($answer, 'never from inside stop()');

        $this->await(static function () use (&$answer): bool {
            return null !== $answer;
        }, what: 'the failure');
        $this->assertSame('the debug adapter stopped', $answer);
    }
}
