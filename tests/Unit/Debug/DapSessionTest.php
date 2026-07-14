<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DapSession;
use Porta\Debug\DebugSessionCallbacks;
use Porta\Debug\DebugState;
use Porta\Tests\Support\FakeDapClient;

final class DapSessionTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @param array<string, list<int>> $breakpoints */
    private function session(FakeDapClient $client, array $breakpoints = []): DapSession
    {
        $this->events = [];

        return new DapSession($client, 'php', fn (): array => $breakpoints, new DebugSessionCallbacks(
            onStopped: function (string $reason): void {
                $this->events[] = "stopped:{$reason}";
            },
            onContinued: function (): void {
                $this->events[] = 'continued';
            },
            onOutput: function (string $category, string $text): void {
                $this->events[] = "output:{$category}:".trim($text);
            },
            onTerminated: function (): void {
                $this->events[] = 'terminated';
            },
            onError: function (string $message): void {
                $this->events[] = "error:{$message}";
            },
        ));
    }

    public function testHandshakeWithInitializedEventBeforeTheLaunchResponse(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client, ['/p/a.php' => [4, 9]]);

        $this->assertTrue($session->start(['program' => '/p/a.php']));
        $this->assertSame(DebugState::Starting, $session->state());
        $init = $client->argumentsOf('initialize');
        $this->assertSame('php', $init['adapterID']);
        $this->assertTrue($init['linesStartAt1']);
        $this->assertFalse($init['supportsRunInTerminalRequest']);

        $client->respond('initialize', ['supportsConfigurationDoneRequest' => true]);
        $this->assertContains('launch', $client->commands());

        // The awkward order: initialized arrives before the launch response.
        $client->emit('initialized');
        $this->assertSame([['line' => 5], ['line' => 10]], $client->argumentsOf('setBreakpoints')['breakpoints'], '0-based lines shipped 1-based');
        $this->assertContains('configurationDone', $client->commands());

        $client->respond('launch');
        $this->assertSame(DebugState::Running, $session->state());
        $this->assertTrue($session->isActive());
    }

    public function testLateInitializedOrderAndMissingConfigurationDoneCapability(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start(['program' => '/p/a.php']);

        $client->respond('initialize', []); // no supportsConfigurationDoneRequest
        $client->respond('launch');
        $this->assertSame(DebugState::Running, $session->state());

        $client->emit('initialized'); // late, after launch
        $this->assertNotContains('configurationDone', $client->commands(), 'gated on the capability');
    }

    public function testStoppedPausesUsingTheEventThreadIdAndStepsResume(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');

        $client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 7]);
        $this->assertSame(DebugState::Paused, $session->state());
        $this->assertSame(['stopped:breakpoint'], $this->events);

        $session->stepOver();
        $this->assertSame(['threadId' => 7], $client->argumentsOf('next'));
        $this->assertSame(DebugState::Running, $session->state());
        $this->assertContains('continued', $this->events);

        $client->emit('stopped', ['reason' => 'step', 'threadId' => 7]);
        $session->stepIn();
        $this->assertContains('stepIn', $client->commands());
        $client->emit('stopped', ['reason' => 'step', 'threadId' => 7]);
        $session->stepOut();
        $this->assertContains('stepOut', $client->commands());
        $client->emit('stopped', ['reason' => 'step', 'threadId' => 7]);
        $session->continueRun();
        $this->assertContains('continue', $client->commands());

        $session->stepOver();
        $this->assertSame(1, \count(array_filter($client->commands(), static fn (string $c): bool => 'next' === $c)), 'steps while running are ignored');
    }

    public function testStoppedWithoutAThreadIdAsksTheAdapterForThreads(): void
    {
        $client = new FakeDapClient();
        $client->responses['threads'] = [['threads' => [['id' => 3, 'name' => 'main']]], null];
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');

        $client->emit('stopped', ['reason' => 'pause']);
        $this->assertSame(['stopped:pause'], $this->events);
        $session->stepOver();
        $this->assertSame(['threadId' => 3], $client->argumentsOf('next'), 'fell back to the first reported thread');
    }

    public function testLiveBreakpointEditsOutputAndTermination(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');

        $session->setBreakpoints('/p/b.php', [2]);
        $this->assertSame('/p/b.php', $client->argumentsOf('setBreakpoints')['source']['path']);

        $client->emit('output', ['category' => 'stdout', 'output' => "hi\n"]);
        $client->emit('output', ['category' => 'stdout', 'output' => '']); // empty text is dropped
        $this->assertContains('output:stdout:hi', $this->events);

        $client->emit('terminated');
        $client->emit('exited', ['exitCode' => 0]);
        $client->close();
        $this->assertSame(1, \count(array_filter($this->events, static fn (string $e): bool => 'terminated' === $e)), 'reported exactly once');
        $this->assertSame(DebugState::Terminated, $session->state());

        $client->emit('continued'); // a straggler event after termination stays ignored
        $this->assertNotContains('continued', $this->events);

        $session->setBreakpoints('/p/b.php', [5]);
        $this->assertSame([2 + 1], array_column($client->argumentsOf('setBreakpoints')['breakpoints'], 'line'), 'no sends after termination');
    }

    public function testStackScopesAndVariablesConvertAndTolerateErrors(): void
    {
        $client = new FakeDapClient();
        $client->responses['stackTrace'] = [
            [
                'stackFrames' => [
                    ['id' => 1, 'name' => '{main}', 'source' => ['path' => '/p/a.php'], 'line' => 5],
                    ['id' => 2], // sparse frame exercises the defaults
                ],
            ],
            null,
        ];
        $client->responses['scopes'] = [['scopes' => [['name' => 'Locals', 'variablesReference' => 9]]], null];
        $client->responses['variables'] = [['variables' => [['name' => '$x', 'value' => '42', 'variablesReference' => 0]]], null];
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');
        $client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);

        $frames = $scopes = $variables = null;
        $session->stackTrace(function (array $f) use (&$frames): void {
            $frames = $f;
        });
        $session->scopes(1, function (array $s) use (&$scopes): void {
            $scopes = $s;
        });
        $session->variables(9, function (array $v) use (&$variables): void {
            $variables = $v;
        });

        $this->assertSame(
            [
                ['id' => 1, 'name' => '{main}', 'path' => '/p/a.php', 'line' => 4],
                ['id' => 2, 'name' => '?', 'path' => '', 'line' => 0],
            ],
            $frames,
            '1-based wire lines became 0-based',
        );
        $this->assertSame([['name' => 'Locals', 'variablesReference' => 9]], $scopes);
        $this->assertSame([['name' => '$x', 'value' => '42', 'variablesReference' => 0]], $variables);

        // Error responses degrade to empty lists.
        $client->responses['stackTrace'] = [[], 'no stack available'];
        $session->stackTrace(function (array $f) use (&$frames): void {
            $frames = $f;
        });
        $this->assertSame([], $frames);
    }

    public function testSpawnAndHandshakeFailures(): void
    {
        $client = new FakeDapClient();
        $client->failToStart = true;
        $session = $this->session($client);
        $this->assertFalse($session->start([]));
        $this->assertSame(DebugState::Terminated, $session->state());
        $this->assertSame([], $this->events, 'a session that never started reports nothing');

        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', [], 'nope');
        $this->assertSame(['error:initialize failed: nope'], $this->events);
        $this->assertTrue($client->stopped);

        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch', [], 'bad program');
        $this->assertSame(['error:launch failed: bad program'], $this->events);
        $this->assertSame(DebugState::Terminated, $session->state());
    }

    public function testDisconnectIsPoliteAndIdempotent(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');

        $session->disconnect();
        $this->assertSame(['terminateDebuggee' => true], $client->argumentsOf('disconnect'));
        $this->assertTrue($client->stopped);
        $this->assertSame(['terminated'], $this->events);

        $session->disconnect(); // no double reporting, no second request
        $this->assertSame(['terminated'], $this->events);
        $this->assertSame(1, \count(array_filter($client->commands(), static fn (string $c): bool => 'disconnect' === $c)));
    }
}
