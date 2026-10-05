<?php

declare(strict_types=1);

/**
 * Answers initialize/shutdown like the mock server, and appends every
 * received message (as one JSON line) to the file named by argv[1] — for
 * asserting the exact wire messages a client sent, not just their
 * end-to-end effect.
 *
 * Ignores SIGTERM (bounded by the alarm below): a normal server would be
 * killed by stop()'s proc_terminate() before it ever gets a scheduler slot
 * to read the just-queued shutdown/exit bytes out of the pipe, which would
 * make this log a false negative for "was it sent" rather than a true one.
 */
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
// Bounded lifetime: a leaked child cannot outlive the test run. 30s, not 5s —
// under heavy parallel test load, fork/exec plus this child's first pipe-read
// scheduling slot can itself take several seconds, and a too-tight alarm was
// observed killing the child before it ever read its first message.
pcntl_alarm(30);

$in = \STDIN;
$out = \STDOUT;
$log = $argv[1] ?? null;

require __DIR__.'/lsp-stub.php';

foreach (readFrames($in) as $message) {
    if (null !== $log) {
        file_put_contents($log, json_encode($message)."\n", FILE_APPEND);
    }
    $method = $message['method'] ?? '';

    // `shutdown` is deliberately NOT answered: the client sends it via send()
    // directly (bypassing request()), so it never registers a response
    // callback — a reply here would just race the client's own pipe-close
    // and die of SIGPIPE before ever reading the `exit` that follows.
    match ($method) {
        'initialize' => sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]),
        'exit' => exit(0),
        default => null,
    };
}
sleep(30); // stdin closed without ever receiving `exit`: stay alive until the alarm
