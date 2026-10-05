<?php

declare(strict_types=1);

// Fault server: after initialize it sends server→client requests
// (workspace/configuration, workspace/applyEdit, an unknown method) and
// echoes the client's replies back in a "mock/echo" notification.

$in = \STDIN;
$out = \STDOUT;

require __DIR__.'/lsp-stub.php';

$replies = [];
$sentRequests = false;
$openedUri = null;

foreach (readFrames($in) as $message) {
    if ('initialize' === ($message['method'] ?? '')) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
        continue;
    }

    if ('textDocument/didOpen' === ($message['method'] ?? '')) {
        $openedUri = $message['params']['textDocument']['uri'] ?? null;
        // A real edit against the opened document, so clients applying
        // it touch one file.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => 1004,
            'method' => 'workspace/applyEdit',
            'params' => [
                'edit' => [
                    'changes' => [
                        $openedUri => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => '// server edit'."\n"]],
                    ],
                ],
            ],
        ]);
        continue;
    }

    if ('initialized' === ($message['method'] ?? '') && !$sentRequests) {
        $sentRequests = true;
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => 1001, 'method' => 'workspace/configuration', 'params' => ['items' => [['section' => 'php']]]]);
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => 1002, 'method' => 'workspace/applyEdit', 'params' => ['edit' => ['changes' => []]]]);
        // A resource operation the client cannot honour: it must refuse the
        // whole edit and answer `applied: false`, not half-apply it.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => 1007,
            'method' => 'workspace/applyEdit',
            'params' => [
                'edit' => [
                    'documentChanges' => [['kind' => 'delete', 'uri' => 'file:///definitely/not/here.php']],
                ],
            ],
        ]);
        // And an edit naming a file it cannot write: applied to nothing, and
        // the skip reported rather than dropped from the count.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => 1008,
            'method' => 'workspace/applyEdit',
            'params' => [
                'edit' => [
                    'changes' => [
                        'file:///definitely/not/here.php' => [
                            ['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => 'x'],
                        ],
                    ],
                ],
            ],
        ]);
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => 1003, 'method' => 'mock/unknownRequest', 'params' => []]);
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => 1005, 'method' => 'window/workDoneProgress/create', 'params' => ['token' => 't']]);
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => 1006, 'method' => 'client/registerCapability', 'params' => ['registrations' => []]]);
        continue;
    }

    // Client replies to our requests
    if (isset($message['id']) && !isset($message['method'])) {
        $replies[$message['id']] = $message['result'] ?? null;
        if (3 === count($replies)) {
            sendMessage($out, [
                'jsonrpc' => '2.0',
                'method' => 'mock/echo',
                'params' => [
                    'configuration' => $replies[1001],
                    'applyEdit' => $replies[1002],
                    'unknown' => $replies[1003],
                ],
            ]);
        }
        continue;
    }

    if ('exit' === ($message['method'] ?? '')) {
        exit(0);
    }
}
