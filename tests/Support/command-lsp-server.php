<?php

declare(strict_types=1);

// Mock server for executeCommand paths: replies null for "mock.ok" and
// then notifies "mock/ran"; replies with an error for "mock.fail".

$in = \STDIN;
$out = \STDOUT;

require __DIR__.'/lsp-stub.php';

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';

    if ('initialize' === $method) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
    } elseif ('workspace/executeCommand' === $method) {
        $command = $message['params']['command'] ?? '';
        if ('mock.fail' === $command) {
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32000, 'message' => 'command blew up']]);
        } else {
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
            sendMessage($out, ['jsonrpc' => '2.0', 'method' => 'mock/ran', 'params' => ['command' => $command]]);
        }
    } elseif ('exit' === $method) {
        exit(0);
    }
}
