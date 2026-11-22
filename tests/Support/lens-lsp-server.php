<?php

declare(strict_types=1);

/**
 * A server that lenses: `codeLensProvider`, one lens over line 1 that runs a
 * command (`lens.run`, titled "2 usages"), and one over line 3 with no verb.
 * The executed command is written to `<root>/lens-ran` so a scenario can see
 * it reached the server. Code Vision's fixture; the shared mock stays lens-free,
 * since its lens rows would move every editor that uses it.
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
$root = null;

require __DIR__.'/lsp-stub.php';

$lens = static fn (int $line, array $command): array => ['range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 0]], 'command' => $command];

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';
    if ('initialize' === $method) {
        $uri = (string) ($message['params']['rootUri'] ?? '');
        $cwd = getcwd();
        $root = false === $cwd ? null : $cwd;
        if (str_starts_with($uri, 'file://')) {
            $root = rawurldecode(substr($uri, 7));
        }
    }
    match ($method) {
        'initialize' => sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'capabilities' => [
                    'textDocumentSync' => 1,
                    'codeLensProvider' => ['resolveProvider' => false],
                    'executeCommandProvider' => ['commands' => ['lens.run']],
                ],
            ],
        ]),
        'textDocument/codeLens' => sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                $lens(1, ['title' => '2 usages', 'command' => 'lens.run', 'arguments' => ['usages']]),
                $lens(3, ['title' => 'deprecated', 'command' => '', 'arguments' => []]),
            ],
        ]),
        'workspace/executeCommand' => (static function () use ($out, $message, $root): void {
            if (null !== $root) {
                file_put_contents($root.'/lens-ran', json_encode($message['params'] ?? [], JSON_THROW_ON_ERROR));
            }
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
        })(),
        'shutdown' => sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]),
        'exit' => exit(0),
        default => null,
    };
}
