<?php

declare(strict_types=1);

/**
 * Minimal scripted DAP adapter for tests. Speaks Content-Length framed DAP
 * over stdio. Behavior:
 *  - initialize → capabilities + `initialized` event (deliberately BEFORE the
 *    launch response arrives, the awkward ordering) + a `runInTerminal` reverse
 *    request (the client must reject it; the rejection is echoed back as a
 *    `reverseRejected` event so tests can observe it).
 *  - setExceptionBreakpoints → echoed as console output ("xfilters: a,b").
 *  - setBreakpoints → verified echo; remembered per source path. Received
 *    conditions are echoed as console `output` events ("cond@<line>: <expr>"),
 *    so tests can assert the wire contract on screen.
 *  - launch → remembers `program`; emits one stdout `output` event.
 *  - configurationDone → `stopped` at the first breakpoint, or `terminated`.
 *  - threads / stackTrace / scopes / variables → scripted data; a stackTrace
 *    while the current line is >= 90 responds with an error (error-path hook).
 *  - next/stepIn/stepOut → advance one line, say which ("step: next") on the
 *    console, `stopped {reason: step}`.
 *  - continue → next breakpoint or `terminated` + `exited`.
 *  - evaluate → $x: 42 with a bump counter ("42#n", re-eval observable);
 *    $arr: a variablesReference container; anything else errors.
 *  - disconnect → response, then exit.
 */

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

require __DIR__.'/lsp-stub.php';
fwrite(STDERR, "mock-dap-adapter started\n");

$seq = 1000;

function sendDap($out, array $message): void
{
    global $seq;
    $message['seq'] = $seq++;
    sendMessage($out, $message);
}

function respond($out, array $request, array $body = [], ?string $error = null): void
{
    sendDap($out, [
        'type' => 'response',
        'request_seq' => $request['seq'],
        'command' => $request['command'],
        'success' => null === $error,
        'message' => $error,
        'body' => (object) $body,
    ]);
}

function event($out, string $name, array $body = []): void
{
    sendDap($out, ['type' => 'event', 'event' => $name, 'body' => (object) $body]);
}

$breakpoints = []; // path => list of 1-based lines
$varOverrides = []; // variablesReference => [name => value] from setVariable
$program = '';
$currentLine = 0;

foreach (readFrames($in) as $message) {
    // The client's rejection of our reverse request arrives as a response.
    if ('response' === ($message['type'] ?? '')) {
        if ('runInTerminal' === ($message['command'] ?? '') && false === ($message['success'] ?? true)) {
            event($out, 'reverseRejected');
        }
        continue;
    }

    $command = $message['command'] ?? '';
    $args = is_array($message['arguments'] ?? null) ? $message['arguments'] : [];

    switch ($command) {
        case 'initialize':
            respond($out, $message, [
                'supportsConfigurationDoneRequest' => true,
                'supportsConditionalBreakpoints' => true,
                'supportsHitConditionalBreakpoints' => false,
                'supportsLogPoints' => true,
                'supportsSetVariable' => true,
                'supportsRestartRequest' => true,
                'exceptionBreakpointFilters' => [
                    ['filter' => 'warning', 'label' => 'Warnings', 'default' => false],
                    ['filter' => 'exception', 'label' => 'Uncaught Exceptions', 'default' => true],
                ],
            ]);
            event($out, 'initialized');
            sendDap($out, ['type' => 'request', 'command' => 'runInTerminal', 'arguments' => (object) []]);
            break;

        case 'setBreakpoints':
            $path = $args['source']['path'] ?? '';
            $lines = array_map(static fn (array $bp): int => (int) $bp['line'], $args['breakpoints'] ?? []);
            $breakpoints[$path] = $lines;
            respond($out, $message, ['breakpoints' => array_map(static fn (int $l): array => ['verified' => true, 'line' => $l], $lines)]);
            foreach ($args['breakpoints'] ?? [] as $bp) {
                if (isset($bp['condition'])) {
                    event($out, 'output', ['category' => 'console', 'output' => 'cond@'.$bp['line'].': '.$bp['condition']."\n"]);
                }
            }
            break;

        case 'launch':
            $program = (string) ($args['program'] ?? '');
            respond($out, $message);
            event($out, 'output', ['category' => 'stdout', 'output' => "hello from debuggee\n"]);
            break;

        case 'configurationDone':
            respond($out, $message);
            $lines = $breakpoints[$program] ?? [];
            if ([] !== $lines) {
                $currentLine = $lines[0];
                event($out, 'stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
            } else {
                event($out, 'terminated');
                event($out, 'exited', ['exitCode' => 0]);
            }
            break;

        case 'threads':
            respond($out, $message, ['threads' => [['id' => 1, 'name' => 'main']]]);
            break;

        case 'stackTrace':
            if ($currentLine >= 90) {
                respond($out, $message, [], 'no stack available');
                break;
            }
            respond($out, $message, [
                'stackFrames' => [
                    ['id' => 1, 'name' => '{main}', 'source' => ['path' => $program], 'line' => $currentLine, 'column' => 1],
                    ['id' => 2, 'name' => 'require', 'source' => ['path' => $program], 'line' => 1, 'column' => 1],
                ],
                'totalFrames' => 2,
            ]);
            break;

        case 'scopes':
            respond($out, $message, ['scopes' => [['name' => 'Locals', 'variablesReference' => 1000, 'expensive' => false]]]);
            break;

        case 'variables':
            $ref = (int) ($args['variablesReference'] ?? 0);
            $vars = 1000 === $ref
                ? [
                    ['name' => '$x', 'value' => '42', 'type' => 'int', 'variablesReference' => 0],
                    ['name' => '$arr', 'value' => 'array(2)', 'type' => 'array', 'variablesReference' => 1001],
                ]
                : [
                    ['name' => '0', 'value' => "'a'", 'variablesReference' => 0],
                    ['name' => '1', 'value' => "'b'", 'variablesReference' => 0],
                ];
            // Reflect any live edits (setVariable) back on the next fetch.
            foreach ($vars as &$var) {
                if (isset($varOverrides[$ref][$var['name']])) {
                    $var['value'] = $varOverrides[$ref][$var['name']];
                }
            }
            unset($var);
            respond($out, $message, ['variables' => $vars]);
            break;

        case 'setVariable':
            $ref = (int) ($args['variablesReference'] ?? 0);
            $varOverrides[$ref][(string) ($args['name'] ?? '')] = (string) ($args['value'] ?? '');
            respond($out, $message, ['value' => (string) ($args['value'] ?? '')]);
            break;

        case 'next':
        case 'stepIn':
        case 'stepOut':
            respond($out, $message);
            ++$currentLine;
            // Which step it was, on the debuggee's output: the three move alike otherwise.
            event($out, 'output', ['category' => 'console', 'output' => 'step: '.$message['command']."\n"]);
            event($out, 'stopped', ['reason' => 'step', 'threadId' => 1]);
            break;

        case 'continue':
            respond($out, $message, ['allThreadsContinued' => true]);
            $next = null;
            foreach ($breakpoints[$program] ?? [] as $line) {
                if ($line > $currentLine) {
                    $next = $line;
                    break;
                }
            }
            if (null !== $next) {
                $currentLine = $next;
                event($out, 'stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
            } else {
                event($out, 'terminated');
                event($out, 'exited', ['exitCode' => 0]);
            }
            break;

        case 'setExceptionBreakpoints':
            respond($out, $message);
            event($out, 'output', ['category' => 'console', 'output' => 'xfilters: '.implode(',', $args['filters'] ?? [])."\n"]);
            break;

        case 'evaluate':
            $expr = (string) ($args['expression'] ?? '');
            if ('$x' === $expr) {
                static $bump = 0;
                respond($out, $message, ['result' => '42#'.++$bump, 'variablesReference' => 0]);
            } elseif ('$arr' === $expr) {
                respond($out, $message, ['result' => 'array(2)', 'variablesReference' => 1001]);
            } else {
                respond($out, $message, [], 'cannot evaluate');
            }
            break;

        case 'restart':
            respond($out, $message);
            event($out, 'output', ['category' => 'stdout', 'output' => "restarted\n"]);
            event($out, 'stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
            break;

        case 'disconnect':
            respond($out, $message);
            exit(0);
    }
}
