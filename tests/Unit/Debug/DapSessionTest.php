<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DapSession;
use Porta\Debug\DebugState;
use Porta\Tests\Support\FakeDapClient;
use Porta\Tests\Support\RecordingDebugObserver;

final class DapSessionTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @var (\Closure(list<array{filter: string, label: string, default: bool}>): list<string>)|null per-test override */
    private ?\Closure $exceptionFiltersProvider = null;

    /** @param array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>> $breakpoints */
    private function session(FakeDapClient $client, array $breakpoints = []): DapSession
    {
        $this->events = [];

        return new DapSession(
            $client,
            'php',
            static fn (): array => $breakpoints,
            new RecordingDebugObserver(function (string $event): void {
                $this->events[] = $event;
            }),
            $this->exceptionFiltersProvider ?? static fn (array $advertised): array => array_column(array_filter($advertised, static fn (array $f): bool => $f['default']), 'filter'),
        );
    }

    public function testHandshakeWithInitializedEventBeforeTheLaunchResponse(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client, ['/p/a.php' => [self::bp(4), self::bp(9)]]);

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

        $session->setBreakpoints('/p/b.php', [self::bp(2)]);
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

        $session->setBreakpoints('/p/b.php', [self::bp(5)]);
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

        $frames = null;
        $scopes = null;
        $variables = null;
        $session->stackTrace(static function (array $f) use (&$frames): void {
            $frames = $f;
        });
        $session->scopes(1, static function (array $s) use (&$scopes): void {
            $scopes = $s;
        });
        $session->variables(9, static function (array $v) use (&$variables): void {
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
        $session->stackTrace(static function (array $f) use (&$frames): void {
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

    public function testConditionFieldsAreCapabilityGatedWithAConsoleWarning(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', ['supportsConditionalBreakpoints' => true]); // hit conditions unsupported
        $client->respond('launch');

        $session->setBreakpoints('/p/a.php', [self::bp(2, '$x > 1', '3'), self::bp(6)]);
        $this->assertSame(
            [['line' => 3, 'condition' => '$x > 1'], ['line' => 7]],
            $client->argumentsOf('setBreakpoints')['breakpoints'],
            'the condition rides along; the unsupported hitCondition is stripped',
        );
        $warnings = array_values(array_filter($this->events, static fn (string $e): bool => str_contains($e, 'output:console:')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("does not support 'hitCondition'", $warnings[0]);
        $this->assertStringContainsString('/p/a.php:3', $warnings[0]);
    }

    public function testLogMessageRidesAlongWhenTheAdapterSupportsLogpoints(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', ['supportsLogPoints' => true]);
        $client->respond('launch');

        $session->setBreakpoints('/p/a.php', [self::bp(2, logMessage: 'x={$x}')]);
        $this->assertSame(
            [['line' => 3, 'logMessage' => 'x={$x}']],
            $client->argumentsOf('setBreakpoints')['breakpoints'],
            'the log message rides along as a logpoint',
        );
    }

    public function testSetVariableSendsTheRequestAndDeliversTheNewValue(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', ['supportsSetVariable' => true]);
        $client->respond('launch');
        $this->assertTrue($session->supportsSetVariable());

        $client->responses['setVariable'] = [['value' => '99'], null];
        $delivered = null;
        $session->setVariable(1000, '$x', '99', static function (string $value, ?string $error) use (&$delivered): void {
            $delivered = [$value, $error];
        });
        $this->assertSame(['variablesReference' => 1000, 'name' => '$x', 'value' => '99'], $client->argumentsOf('setVariable'));
        $this->assertSame(['99', null], $delivered, 'the adapter-formatted value comes back');
    }

    public function testRestartSendsTheRequestWhenSupported(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', ['supportsRestartRequest' => true]);
        $client->respond('launch');
        $this->assertTrue($session->supportsRestart());

        $session->restart();
        $this->assertContains('restart', $client->commands(), 'the native restart request is sent');
    }

    public function testRestartIsInertWhenNotActive(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client); // never started
        $session->restart();
        $this->assertNotContains('restart', $client->commands());
    }

    public function testSetVariableIsInertWhenTheSessionIsNotActive(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client); // never started → not active
        $called = false;
        $session->setVariable(1000, '$x', '1', static function () use (&$called): void {
            $called = true;
        });
        $this->assertSame([], $client->argumentsOf('setVariable'), 'no request while inactive');
        $this->assertFalse($called, 'and no delivery');
    }

    public function testSetVariableIsRefusedWhenUnsupported(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []); // no setVariable support
        $client->respond('launch');
        $this->assertFalse($session->supportsSetVariable());

        $delivered = null;
        $session->setVariable(1000, '$x', '99', static function (string $value, ?string $error) use (&$delivered): void {
            $delivered = [$value, $error];
        });
        $this->assertSame([], $client->argumentsOf('setVariable'), 'no request is sent');
        $this->assertNotNull($delivered[1] ?? null, 'an error is delivered instead');
    }

    public function testLogMessageIsStrippedWithAWarningWhenUnsupported(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', []); // no logpoint support
        $client->respond('launch');

        $session->setBreakpoints('/p/a.php', [self::bp(4, logMessage: 'hit')]);
        $this->assertSame([['line' => 5]], $client->argumentsOf('setBreakpoints')['breakpoints'], 'the unsupported log message is stripped');
        $warnings = array_values(array_filter($this->events, static fn (string $e): bool => str_contains($e, "does not support 'logMessage'")));
        $this->assertCount(1, $warnings);
    }

    public function testEvaluateNormalizesResultsErrorsAndFrameContext(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);

        $delivered = null;
        $session->evaluate('$x', 7, 'watch', static function (array $r) use (&$delivered): void {
            $delivered = $r;
        });
        $this->assertNotContains('evaluate', $client->commands(), 'an inactive session evaluates nothing');

        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');

        $client->responses['evaluate'] = [['result' => '42', 'variablesReference' => 9], null];
        $session->evaluate('$x', 7, 'watch', static function (array $r) use (&$delivered): void {
            $delivered = $r;
        });
        $this->assertSame(['expression' => '$x', 'context' => 'watch', 'frameId' => 7], $client->argumentsOf('evaluate'));
        $this->assertSame(['result' => '42', 'variablesReference' => 9, 'error' => null], $delivered);

        $client->responses['evaluate'] = [[], 'cannot evaluate'];
        $session->evaluate('$bad', null, 'repl', static function (array $r) use (&$delivered): void {
            $delivered = $r;
        });
        $this->assertArrayNotHasKey('frameId', $client->argumentsOf('evaluate'), 'no frame → no frameId key');
        $this->assertSame(['result' => '', 'variablesReference' => 0, 'error' => 'cannot evaluate'], $delivered);
    }

    public function testExceptionFiltersAreCapturedSentAndSyncable(): void
    {
        $client = new FakeDapClient();
        $captured = null;
        $this->exceptionFiltersProvider = static function (array $advertised) use (&$captured): array {
            $captured = $advertised;

            return ['exception'];
        };
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', [
            'supportsConfigurationDoneRequest' => true,
            'exceptionBreakpointFilters' => [
                ['filter' => 'warning', 'label' => 'Warnings'],
                ['filter' => 'exception', 'default' => true], // no label: falls back to the id
                ['nope' => 'malformed'],
            ],
        ]);
        $client->respond('launch');

        $this->assertSame(
            [
                ['filter' => 'warning', 'label' => 'Warnings', 'default' => false],
                ['filter' => 'exception', 'label' => 'exception', 'default' => true],
            ],
            $session->exceptionBreakpointFilters(),
        );

        $client->emit('initialized');
        $this->assertSame(['filters' => ['exception']], $client->argumentsOf('setExceptionBreakpoints'));
        $this->assertSame($session->exceptionBreakpointFilters(), $captured, 'the provider saw the advertised set');
        $commands = $client->commands();
        $this->assertGreaterThan(
            array_search('setExceptionBreakpoints', $commands, strict: true),
            array_search('configurationDone', $commands, strict: true),
            'filters are sent before configurationDone',
        );

        // A live toggle re-sends.
        $this->exceptionFiltersProvider = static fn (): array => [];
        $session->syncExceptionBreakpoints();
        $this->assertSame(2, \count(array_filter($client->commands(), static fn (string $c): bool => 'setExceptionBreakpoints' === $c)));
    }

    public function testAdaptersWithoutFiltersNeverGetTheRequest(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client);
        $session->start([]);
        $client->respond('initialize', ['supportsConfigurationDoneRequest' => true]);
        $client->respond('launch');
        $client->emit('initialized');
        $session->syncExceptionBreakpoints(); // explicitly, too

        $this->assertNotContains('setExceptionBreakpoints', $client->commands());
    }

    public function testRunToMergesATempBreakpointAndRetiresItOnTheNextStop(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client, ['/p/a.php' => [self::bp(2, '$x > 1')]]);
        $session->start([]);
        $client->respond('initialize', ['supportsConditionalBreakpoints' => true]);
        $client->respond('launch');

        $session->runTo('/p/a.php', 8); // while running: a no-op
        $this->assertNotContains('setBreakpoints', $client->commands());

        $client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
        $session->runTo('/p/a.php', 8);
        $this->assertSame(
            [['line' => 3, 'condition' => '$x > 1'], ['line' => 9]],
            $client->argumentsOf('setBreakpoints')['breakpoints'],
            'the one-shot merged into the wire list',
        );
        $this->assertContains('continue', $client->commands());

        // A user toggle in the same file mid-run keeps the temp merged.
        $session->setBreakpoints('/p/a.php', [self::bp(2, '$x > 1'), self::bp(5)]);
        $this->assertSame([3, 6, 9], array_column($client->argumentsOf('setBreakpoints')['breakpoints'], 'line'));

        // The next stop (any reason) retires the temp and re-sends without it.
        $client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        $this->assertSame([3], array_column($client->argumentsOf('setBreakpoints')['breakpoints'], 'line'), 'the store list only (the toggle above never entered the provider)');
    }

    public function testRunToOntoAnExistingBreakpointJustContinues(): void
    {
        $client = new FakeDapClient();
        $session = $this->session($client, ['/p/a.php' => [self::bp(4)]]);
        $session->start([]);
        $client->respond('initialize', []);
        $client->respond('launch');
        $client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);

        $session->runTo('/p/a.php', 4);
        $this->assertNotContains('setBreakpoints', $client->commands(), 'no wire churn — the real breakpoint (and its condition) stands');
        $this->assertContains('continue', $client->commands());

        // The next stop has no temp to retire: no extra setBreakpoints either.
        $client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
        $this->assertNotContains('setBreakpoints', $client->commands());
    }

    /** @return array{line: int, condition: ?string, hitCondition: ?string} */
    private static function bp(int $line, ?string $condition = null, ?string $hitCondition = null, ?string $logMessage = null): array
    {
        return ['line' => $line, 'condition' => $condition, 'hitCondition' => $hitCondition, 'logMessage' => $logMessage];
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
