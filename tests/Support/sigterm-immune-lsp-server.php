<?php

declare(strict_types=1);

/**
 * Answers initialize like the mock server, but ignores SIGTERM and only
 * exits on the JSON-RPC `exit` notification — for proving LspClient::stop()
 * actually sends the polite shutdown/exit sequence, not just that the child
 * eventually dies (proc_terminate() alone would kill a normal server just as
 * fast, masking a skipped shutdown/exit).
 */
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
// Bounded lifetime: a leaked child cannot outlive the test run. 30s, not 5s —
// under heavy parallel test load, fork/exec plus this child's first pipe-read
// scheduling slot can itself take several seconds, and a too-tight alarm was
// observed killing the child before it ever read its first message.
pcntl_alarm(30);

$in = fopen(filename: 'php://stdin', mode: 'rb');
$out = fopen(filename: 'php://stdout', mode: 'wb');

require __DIR__.'/lsp-stub.php';

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';

    // `shutdown` is deliberately NOT answered: the client sends it via send()
    // directly (bypassing request()), so it never registers a response
    // callback — a reply here would just race the client's own pipe-close
    // and die of SIGPIPE before ever reading the `exit` that follows, which
    // would pass this test for the wrong reason (a crash, not a real exit).
    match ($method) {
        'initialize' => sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]),
        'exit' => exit(0),
        default => null,
    };
}
// stdin closed without ever receiving `exit` (e.g. the caller only closed the
// pipes and sent SIGTERM): stay alive — SIGTERM is ignored — until the alarm
// above bounds it, so a caller that skipped the polite shutdown is left with
// a lingering child instead of a falsely-quick exit via stdin EOF.
sleep(30);
