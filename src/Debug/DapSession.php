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
    private bool $supportsConditionalBreakpoints = false;
    private bool $supportsHitConditionalBreakpoints = false;
    private bool $supportsLogPoints = false;
    private bool $supportsSetVariable = false;
    private bool $terminationReported = false;

    /** @var list<array{filter: string, label: string, default: bool}> the adapter's advertised exception filters */
    private array $exceptionFilters = [];

    /** @var array{path: string, line: int}|null run-to-cursor's one-shot breakpoint (0-based; never in the store) */
    private ?array $tempBreakpoint = null;

    /**
     * @param \Closure(): array<string, list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}>> $breakpointsProvider      0-based lines per absolute path
     * @param \Closure(list<array{filter: string, label: string, default: bool}>): list<string>            $exceptionFiltersProvider enabled ids for the advertised filters
     */
    public function __construct(
        private readonly DapClientInterface $client,
        private readonly string $languageId,
        private readonly \Closure $breakpointsProvider,
        private readonly DebugSessionObserver $observer,
        private readonly \Closure $exceptionFiltersProvider,
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
        $this->client->onEvent('initialized', $this->sendConfiguration(...));
        $this->client->onEvent(
            'stopped',
            /** @param array<string, mixed> $body */
            $this->handleStopped(...),
        );
        $this->client->onEvent('continued', $this->markRunning(...));
        $this->client->onEvent('output', function (array $body): void {
            $category = \is_string($body['category'] ?? null) ? $body['category'] : 'console';
            $text = \is_string($body['output'] ?? null) ? $body['output'] : '';
            if ('' !== $text) {
                $this->observer->onOutput($category, $text);
            }
        });
        $this->client->onEvent('terminated', $this->handleTerminated(...));
        $this->client->onEvent('exited', $this->handleTerminated(...));
        $this->client->onClose($this->handleTerminated(...));

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
                $this->supportsConditionalBreakpoints = true === ($body['supportsConditionalBreakpoints'] ?? false);
                $this->supportsHitConditionalBreakpoints = true === ($body['supportsHitConditionalBreakpoints'] ?? false);
                $this->supportsLogPoints = true === ($body['supportsLogPoints'] ?? false);
                $this->supportsSetVariable = true === ($body['supportsSetVariable'] ?? false);
                $this->exceptionFilters = self::normalizeExceptionFilters($body['exceptionBreakpointFilters'] ?? null);
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
     * Push one file's breakpoints (0-based lines) to the adapter. Condition
     * fields ride along only when the adapter advertised the capability —
     * otherwise they are stripped with a console warning, so a silently
     * unconditional breakpoint never masquerades as a conditional one.
     *
     * @param list<array{line: int, condition: ?string, hitCondition: ?string, logMessage: ?string}> $breakpoints
     */
    public function setBreakpoints(string $path, array $breakpoints): void
    {
        if (!$this->isActive()) {
            return;
        }
        $wire = [];
        foreach ($breakpoints as $breakpoint) {
            $entry = ['line' => $breakpoint['line'] + 1];
            foreach (['condition' => $this->supportsConditionalBreakpoints, 'hitCondition' => $this->supportsHitConditionalBreakpoints, 'logMessage' => $this->supportsLogPoints] as $field => $supported) {
                if (null === $breakpoint[$field]) {
                    continue;
                }
                if ($supported) {
                    $entry[$field] = $breakpoint[$field];

                    continue;
                }
                $this->observer->onOutput('console', \sprintf("Breakpoint at %s:%d: the adapter does not support '%s' — set unconditionally.\n", $path, $breakpoint['line'] + 1, $field));
            }
            $wire[] = $entry;
        }
        // Run-to-cursor's one-shot rides along on every send for its file, so
        // a user toggling another breakpoint mid-run cannot wipe it.
        $temp = $this->tempBreakpoint;
        if (null !== $temp && $temp['path'] === $path && !\in_array($temp['line'] + 1, array_column($wire, 'line'), strict: true)) {
            $wire[] = ['line' => $temp['line'] + 1];
        }
        $this->client->request('setBreakpoints', [
            'source' => ['path' => $path],
            'breakpoints' => $wire,
        ]);
    }

    /**
     * Evaluate an expression against a frame. Context is 'watch' for the
     * panel (side-effect-averse) or 'repl' for a one-shot. Delivery is
     * normalized: an error response arrives as {result: '', ref: 0, error}.
     *
     * @param \Closure(array{result: string, variablesReference: int, error: ?string}): void $deliver
     */
    public function evaluate(string $expression, ?int $frameId, string $context, \Closure $deliver): void
    {
        if (!$this->isActive()) {
            return;
        }
        $arguments = ['expression' => $expression, 'context' => $context];
        if (null !== $frameId) {
            $arguments['frameId'] = $frameId;
        }
        $this->client->request('evaluate', $arguments, static function (array $body, ?string $error) use ($deliver): void {
            $deliver([
                'result' => \is_string($body['result'] ?? null) ? $body['result'] : '',
                'variablesReference' => \is_int($body['variablesReference'] ?? null) ? $body['variablesReference'] : 0,
                'error' => $error,
            ]);
        });
    }

    /** @return list<array{filter: string, label: string, default: bool}> the adapter's advertised exception filters */
    public function exceptionBreakpointFilters(): array
    {
        return $this->exceptionFilters;
    }

    /** Re-send the enabled exception filters (a live toggle). No-op for adapters without filters. */
    public function syncExceptionBreakpoints(): void
    {
        if (!$this->isActive() || [] === $this->exceptionFilters) {
            return;
        }
        $this->client->request('setExceptionBreakpoints', ['filters' => ($this->exceptionFiltersProvider)($this->exceptionFilters)]);
    }

    /**
     * Run to a caret position: a one-shot breakpoint merged at the wire level
     * (never entering the store), removed on the next stop — any stop. A line
     * that already carries a real breakpoint just continues (its condition is
     * respected rather than bypassed).
     */
    public function runTo(string $path, int $line): void
    {
        if (DebugState::Paused !== $this->state) {
            return;
        }
        $existing = ($this->breakpointsProvider)()[$path] ?? [];
        if (!\in_array($line, array_column($existing, 'line'), strict: true)) {
            $this->tempBreakpoint = ['path' => $path, 'line' => $line];
            $this->setBreakpoints($path, $existing);
        }
        $this->continueRun();
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

    /** Whether the adapter advertised support for editing variables while paused. */
    public function supportsSetVariable(): bool
    {
        return $this->supportsSetVariable;
    }

    /**
     * Set a variable's value in its container while paused. Delivers the
     * adapter's new (formatted) value on success, or an error string; a no-op
     * (delivering an error) when the adapter lacks the capability.
     *
     * @param \Closure(string $value, ?string $error): void $deliver
     */
    public function setVariable(int $reference, string $name, string $value, \Closure $deliver): void
    {
        if (!$this->isActive()) {
            return;
        }
        if (!$this->supportsSetVariable) {
            $deliver('', 'the debug adapter does not support editing variables');

            return;
        }
        $this->client->request('setVariable', [
            'variablesReference' => $reference,
            'name' => $name,
            'value' => $value,
        ], static function (array $body, ?string $error) use ($deliver, $value): void {
            $deliver(\is_string($body['value'] ?? null) ? $body['value'] : $value, $error);
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
        foreach (($this->breakpointsProvider)() as $path => $breakpoints) {
            $this->setBreakpoints($path, $breakpoints);
        }
        $this->syncExceptionBreakpoints();
        if ($this->supportsConfigurationDone) {
            $this->client->request('configurationDone');
        }
    }

    /** @param array<string, mixed> $body */
    private function handleStopped(array $body): void
    {
        $this->state = DebugState::Paused;
        if (null !== ($temp = $this->tempBreakpoint)) {
            // Any stop retires the one-shot: re-send its file without it.
            $this->tempBreakpoint = null;
            $this->setBreakpoints($temp['path'], ($this->breakpointsProvider)()[$temp['path']] ?? []);
        }
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
        $this->observer->onStopped(\is_string($body['reason'] ?? null) ? $body['reason'] : 'pause');
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
        $this->observer->onContinued();
    }

    private function fail(string $message): void
    {
        $this->tempBreakpoint = null;
        $wasUnreported = !$this->terminationReported;
        $this->terminationReported = true;
        $this->state = DebugState::Terminated;
        if ($wasUnreported) {
            $this->observer->onError($message);
        }
        $this->client->stop();
    }

    /**
     * @return list<array{filter: string, label: string, default: bool}>
     */
    private static function normalizeExceptionFilters(mixed $raw): array
    {
        $filters = [];
        /** @var list<array<string, mixed>|scalar|null> $rawFilters */
        $rawFilters = \is_array($raw) ? $raw : [];
        foreach ($rawFilters as $filter) {
            if (!\is_array($filter) || !\is_string($filter['filter'] ?? null)) {
                continue;
            }
            $filters[] = [
                'filter' => $filter['filter'],
                'label' => \is_string($filter['label'] ?? null) ? $filter['label'] : $filter['filter'],
                'default' => true === ($filter['default'] ?? false),
            ];
        }

        return $filters;
    }

    private function handleTerminated(): void
    {
        if ($this->terminationReported) {
            return;
        }
        $this->tempBreakpoint = null;
        $this->terminationReported = true;
        $this->state = DebugState::Terminated;
        $this->observer->onTerminated();
    }
}
