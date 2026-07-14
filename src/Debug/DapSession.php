<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * One debug launch: drives the DAP handshake and run/pause lifecycle over a
 * {@see DapClientInterface}. Lines are 0-based internally (the editor's
 * convention) and converted at this boundary — the wire is 1-based. The
 * `initialized` event may arrive before or after the launch response
 * (adapters differ); both orders are handled.
 */
final class DapSession
{
    private DebugState $state = DebugState::Idle;
    private ?int $threadId = null;
    private bool $supportsConfigurationDone = false;
    private bool $terminationReported = false;

    /**
     * @param \Closure(): array<string, list<int>> $breakpointsProvider 0-based lines per absolute path
     */
    public function __construct(
        private readonly DapClientInterface $client,
        private readonly string $languageId,
        private readonly \Closure $breakpointsProvider,
        private readonly DebugSessionCallbacks $callbacks,
    ) {}

    public function state(): DebugState
    {
        return $this->state;
    }

    public function isActive(): bool
    {
        return DebugState::Idle !== $this->state && DebugState::Terminated !== $this->state;
    }

    /**
     * Spawn the adapter and run initialize → launch. False when the adapter
     * process could not be started at all.
     *
     * @param array<string, mixed> $launchArguments
     */
    public function start(array $launchArguments): bool
    {
        $this->client->onEvent('initialized', fn () => $this->sendConfiguration());
        $this->client->onEvent(
            'stopped',
            /** @param array<string, mixed> $body */
            fn (array $body) => $this->handleStopped($body),
        );
        $this->client->onEvent('continued', fn () => $this->markRunning());
        $this->client->onEvent('output', function (array $body): void {
            $category = \is_string($body['category'] ?? null) ? $body['category'] : 'console';
            $text = \is_string($body['output'] ?? null) ? $body['output'] : '';
            if (null !== $this->callbacks->onOutput && '' !== $text) {
                ($this->callbacks->onOutput)($category, $text);
            }
        });
        $this->client->onEvent('terminated', fn () => $this->handleTerminated());
        $this->client->onEvent('exited', fn () => $this->handleTerminated());
        $this->client->onClose(fn () => $this->handleTerminated());

        if (!$this->client->start()) {
            $this->state = DebugState::Terminated;
            $this->terminationReported = true; // never started; no onTerminated

            return false;
        }

        $this->state = DebugState::Starting;
        $this->client->request(
            'initialize',
            [
                'clientID' => 'porta',
                'clientName' => 'Porta',
                'adapterID' => $this->languageId,
                'linesStartAt1' => true,
                'columnsStartAt1' => true,
                'pathFormat' => 'path',
                'supportsVariableType' => true,
                'supportsRunInTerminalRequest' => false,
            ],
            function (array $body, ?string $error) use ($launchArguments): void {
                if (null !== $error) {
                    $this->fail('initialize failed: '.$error);

                    return;
                }
                $this->supportsConfigurationDone = true === ($body['supportsConfigurationDoneRequest'] ?? false);
                $this->client->request('launch', $launchArguments, function (array $ignored, ?string $launchError): void {
                    if (null !== $launchError) {
                        $this->fail('launch failed: '.$launchError);

                        return;
                    }
                    if (DebugState::Starting === $this->state) {
                        $this->state = DebugState::Running;
                    }
                });
            },
        );

        return true;
    }

    /**
     * Push one file's breakpoints (0-based lines) to the adapter.
     *
     * @param list<int> $lines
     */
    public function setBreakpoints(string $path, array $lines): void
    {
        if (!$this->isActive()) {
            return;
        }
        $this->client->request('setBreakpoints', [
            'source' => ['path' => $path],
            'breakpoints' => array_map(static fn (int $line): array => ['line' => $line + 1], $lines),
        ]);
    }

    public function stepOver(): void
    {
        $this->step('next');
    }

    public function stepIn(): void
    {
        $this->step('stepIn');
    }

    public function stepOut(): void
    {
        $this->step('stepOut');
    }

    public function continueRun(): void
    {
        $this->step('continue');
    }

    /**
     * The paused stack, delivered as 0-based frames (empty on error).
     *
     * @param \Closure(list<array{id: int, name: string, path: string, line: int}>): void $deliver
     */
    public function stackTrace(\Closure $deliver): void
    {
        $this->client->request('stackTrace', ['threadId' => $this->threadId ?? 1], static function (array $body, ?string $error) use ($deliver): void {
            $frames = [];
            if (null === $error && \is_array($body['stackFrames'] ?? null)) {
                /** @var array<array-key, array{id?: int, name?: string, line?: int, source?: array{path?: string}}> $rawFrames */
                $rawFrames = $body['stackFrames'];
                foreach ($rawFrames as $frame) {
                    $frames[] = [
                        'id' => $frame['id'] ?? 0,
                        'name' => $frame['name'] ?? '?',
                        'path' => $frame['source']['path'] ?? '',
                        'line' => max(0, ($frame['line'] ?? 1) - 1),
                    ];
                }
            }
            $deliver($frames);
        });
    }

    /**
     * A frame's scopes: list of {name, variablesReference}.
     *
     * @param \Closure(list<array{name: string, variablesReference: int}>): void $deliver
     */
    public function scopes(int $frameId, \Closure $deliver): void
    {
        $this->client->request('scopes', ['frameId' => $frameId], static function (array $body, ?string $error) use ($deliver): void {
            $scopes = [];
            if (null === $error && \is_array($body['scopes'] ?? null)) {
                /** @var array<array-key, array{name?: string, variablesReference?: int}> $rawScopes */
                $rawScopes = $body['scopes'];
                foreach ($rawScopes as $scope) {
                    $scopes[] = ['name' => $scope['name'] ?? '?', 'variablesReference' => $scope['variablesReference'] ?? 0];
                }
            }
            $deliver($scopes);
        });
    }

    /**
     * A container's children: list of {name, value, variablesReference}.
     *
     * @param \Closure(list<array{name: string, value: string, variablesReference: int}>): void $deliver
     */
    public function variables(int $reference, \Closure $deliver): void
    {
        $this->client->request('variables', ['variablesReference' => $reference], static function (array $body, ?string $error) use ($deliver): void {
            $variables = [];
            if (null === $error && \is_array($body['variables'] ?? null)) {
                /** @var array<array-key, array{name?: string, value?: string, variablesReference?: int}> $rawVariables */
                $rawVariables = $body['variables'];
                foreach ($rawVariables as $variable) {
                    $variables[] = [
                        'name' => $variable['name'] ?? '?',
                        'value' => $variable['value'] ?? '',
                        'variablesReference' => $variable['variablesReference'] ?? 0,
                    ];
                }
            }
            $deliver($variables);
        });
    }

    /** Politely end the session, then tear the transport down. Idempotent. */
    public function disconnect(): void
    {
        if ($this->isActive()) {
            $this->client->request('disconnect', ['terminateDebuggee' => true]);
        }
        $this->handleTerminated();
        $this->client->stop();
    }

    /** The adapter announced its readiness for configuration: sync breakpoints. */
    private function sendConfiguration(): void
    {
        foreach (($this->breakpointsProvider)() as $path => $lines) {
            $this->setBreakpoints($path, $lines);
        }
        if ($this->supportsConfigurationDone) {
            $this->client->request('configurationDone');
        }
    }

    /** @param array<string, mixed> $body */
    private function handleStopped(array $body): void
    {
        $this->state = DebugState::Paused;
        /** @var mixed $threadId */
        $threadId = $body['threadId'] ?? null;
        if (\is_int($threadId)) {
            $this->threadId = $threadId;
            $this->reportStopped($body);

            return;
        }
        $this->client->request('threads', [], function (array $threadsBody) use ($body): void {
            /** @var array<array-key, array{id?: int}> $threads */
            $threads = \is_array($threadsBody['threads'] ?? null) ? $threadsBody['threads'] : [];
            $this->threadId = $threads[0]['id'] ?? 1;
            $this->reportStopped($body);
        });
    }

    /** @param array<string, mixed> $body */
    private function reportStopped(array $body): void
    {
        if (null !== $this->callbacks->onStopped) {
            ($this->callbacks->onStopped)(\is_string($body['reason'] ?? null) ? $body['reason'] : 'pause');
        }
    }

    private function step(string $command): void
    {
        if (DebugState::Paused !== $this->state) {
            return;
        }
        $this->client->request($command, ['threadId' => $this->threadId ?? 1]);
        $this->markRunning();
    }

    private function markRunning(): void
    {
        if (!$this->isActive()) {
            return;
        }
        $this->state = DebugState::Running;
        if (null !== $this->callbacks->onContinued) {
            ($this->callbacks->onContinued)();
        }
    }

    private function fail(string $message): void
    {
        $wasUnreported = !$this->terminationReported;
        $this->terminationReported = true;
        $this->state = DebugState::Terminated;
        if ($wasUnreported && null !== $this->callbacks->onError) {
            ($this->callbacks->onError)($message);
        }
        $this->client->stop();
    }

    private function handleTerminated(): void
    {
        if ($this->terminationReported) {
            return;
        }
        $this->terminationReported = true;
        $this->state = DebugState::Terminated;
        if (null !== $this->callbacks->onTerminated) {
            ($this->callbacks->onTerminated)();
        }
    }
}
