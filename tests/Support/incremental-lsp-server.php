<?php

declare(strict_types=1);

/**
 * Mock LSP server that advertises *incremental* text sync (change kind 2).
 * On each didChange it publishes a diagnostic whose message reports whether
 * the client sent an incremental change (a contentChange carrying a `range`)
 * or a full-text replacement — letting the E2e suite assert the wire format.
 */

$in = \STDIN;
$out = \STDOUT;

require __DIR__.'/lsp-stub.php';

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';

    if ('initialize' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'capabilities' => [
                    'textDocumentSync' => ['openClose' => true, 'change' => 2],
                ],
                'serverInfo' => ['name' => 'incremental-lsp'],
            ],
        ]);
    } elseif ('textDocument/didChange' === $method) {
        $first = $message['params']['contentChanges'][0] ?? [];
        $kind = isset($first['range']) ? 'incremental' : 'full';
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => [
                'uri' => $message['params']['textDocument']['uri'],
                'diagnostics' => [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]],
                    'severity' => 2,
                    'message' => 'sync:'.$kind.':'.($first['text'] ?? ''),
                ]],
            ],
        ]);
    } elseif ('shutdown' === $method) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('exit' === $method) {
        exit(0);
    }
}
