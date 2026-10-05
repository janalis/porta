<?php

declare(strict_types=1);

/**
 * A server whose completion items are named after ITSELF, so a test can tell
 * one server's answers from another's on the same language.
 *
 * `argv[1]` is the name it goes by: it advertises `completionProvider` and
 * answers `textDocument/completion` with one item, `<name>_item`. That is the
 * whole of it — everything else a real server does is somebody else's fixture.
 *
 * Ignores SIGTERM under a bounded alarm for the same reason the other stubs
 * do: proc_terminate() would otherwise kill it before it reads its first
 * message, and the point of a stub is to answer.
 */
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
pcntl_alarm(30);

$in = \STDIN;
$out = \STDOUT;
$name = $argv[1] ?? 'anon';

require __DIR__.'/lsp-stub.php';

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';
    match ($method) {
        'initialize' => sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => ['capabilities' => ['completionProvider' => ['resolveProvider' => true]]],
        ]),
        'textDocument/completion' => sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => ['isIncomplete' => false, 'items' => [['label' => $name.'_item', 'kind' => 3]]],
        ]),
        'exit' => exit(0),
        default => null,
    };
}
