<?php

declare(strict_types=1);

/**
 * A language server that never publishes diagnostics and answers them only
 * when asked — `textDocument/diagnostic`, as phpantom does. Each line naming
 * `UnknownClass` in the file's latest text (full sync) is an error.
 */

require __DIR__.'/../lsp-stub.php';

$texts = [];
foreach (readFrames(\STDIN) as $message) {
    $method = $message['method'] ?? '';
    $uri = $message['params']['textDocument']['uri'] ?? '';
    if ('textDocument/didOpen' === $method) {
        $texts[$uri] = $message['params']['textDocument']['text'];
    }
    if ('textDocument/didChange' === $method) {
        $texts[$uri] = $message['params']['contentChanges'][0]['text'];
    }
    if (!\array_key_exists('id', $message)) {
        continue;
    }
    $items = [];
    foreach (\explode("\n", $texts[$uri] ?? '') as $line => $text) {
        $column = \strpos($text, needle: 'UnknownClass');
        if (false !== $column) {
            $items[] = [
                'range' => ['start' => ['line' => $line, 'character' => $column], 'end' => ['line' => $line, 'character' => $column + 12]],
                'severity' => 1,
                'message' => "Class 'UnknownClass' not found",
            ];
        }
    }
    $result = match ($method) {
        'initialize' => ['capabilities' => ['textDocumentSync' => 1, 'diagnosticProvider' => ['interFileDependencies' => false, 'workspaceDiagnostics' => false]]],
        'textDocument/diagnostic' => ['kind' => 'full', 'items' => $items],
        default => null,
    };
    sendMessage(\STDOUT, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
}
