<?php

declare(strict_types=1);

/**
 * Minimal mock LSP server used by tests/lsp.php.
 * Speaks JSON-RPC over stdio: answers initialize and completion requests,
 * and publishes one diagnostic after every didOpen/didChange.
 */

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

require __DIR__.'/lsp-stub.php';

$lastUri = null;
foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';

    if ('initialize' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'capabilities' => [
                    'textDocumentSync' => 1,
                    'completionProvider' => ['triggerCharacters' => ['>', ':'], 'resolveProvider' => true],
                ],
                'serverInfo' => ['name' => 'mock-lsp'],
            ],
        ]);
    } elseif ('textDocument/completion' === $method && ($message['params']['position']['line'] ?? 0) >= 90) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('textDocument/completion' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'isIncomplete' => false,
                'items' => [
                    // Deferred auto-import: additionalTextEdits arrive via completionItem/resolve.
                    ['label' => 'strlen', 'kind' => 3, 'insertText' => 'strlen(', 'sortText' => '1', 'data' => 'strlen'],
                    // Inline auto-import: additionalTextEdits carried on the item itself.
                    [
                        'label' => 'str_replace',
                        'kind' => 3,
                        'insertText' => 'str_replace(',
                        'sortText' => '2',
                        'additionalTextEdits' => [[
                            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                            'newText' => "use function str_replace;\n",
                        ]],
                    ],
                    // Resolves to no additionalTextEdits (needs no import).
                    ['label' => 'trim', 'kind' => 3, 'insertText' => 'trim(', 'sortText' => '3', 'data' => 'plain'],
                    // A snippet completion (insertTextFormat 2) with tab stops.
                    ['label' => 'sprintf', 'kind' => 3, 'insertText' => 'sprintf(${1:format}, ${2:args})$0', 'insertTextFormat' => 2, 'sortText' => '4'],
                    ['label' => '', 'kind' => 1], // must be skipped by the client
                ],
            ],
        ]);
    } elseif ('completionItem/resolve' === $method) {
        $item = $message['params'] ?? [];
        if ('strlen' === ($item['data'] ?? null)) {
            $item['additionalTextEdits'] = [[
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'newText' => "use function strlen;\n",
            ]];
        }
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $item]);
    } elseif ('textDocument/definition' === $method && ($message['params']['position']['line'] ?? 0) >= 95) {
        // Test convention: line >= 95 resolves outside the filesystem
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                ['uri' => 'untitled:scratch', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]]],
            ],
        ]);
    } elseif ('textDocument/definition' === $method && ($message['params']['position']['line'] ?? 0) >= 90) {
        // Test convention: line >= 90 has no definition
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('textDocument/definition' === $method && ($message['params']['position']['line'] ?? 0) >= 85) {
        // Test convention: lines 85-89 mean the request was made from the
        // declaration itself — the response contains the requested position
        // (second, after a decoy, so any-match must beat the chooser).
        $uri = $message['params']['textDocument']['uri'];
        $line = $message['params']['position']['line'];
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                [
                    'uri' => preg_replace('#/[^/]+$#', '/Target.php', $uri),
                    'range' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]],
                ],
                [
                    'uri' => $uri,
                    'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 40]],
                ],
            ],
        ]);
    } elseif ('textDocument/definition' === $method) {
        // Points at line 3 of a sibling file "Target.php" in the same directory
        $uri = $message['params']['textDocument']['uri'];
        $targetUri = preg_replace('#/[^/]+$#', '/Target.php', $uri);
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [[
                'uri' => $targetUri,
                'range' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]],
            ]],
        ]);
    } elseif ('textDocument/inlayHint' === $method) {
        // Only emit hints for files named *Inlay* so other E2e screens are
        // unaffected. Exercises string labels, label parts, and skips.
        $uri = (string) ($message['params']['textDocument']['uri'] ?? '');
        $hints = [];
        if (str_contains($uri, 'Inlay')) {
            $hints = [
                ['position' => ['line' => 1, 'character' => 2], 'label' => ': int'],
                ['position' => ['line' => 1, 'character' => 0], 'label' => [['value' => 'x'], ['value' => '= ']]],
                ['position' => ['line' => 1, 'character' => 5], 'label' => '   '], // trims to empty -> skipped
                ['label' => 'no-position-skipped'],
            ];
        }
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $hints]);
    } elseif ('textDocument/implementation' === $method && ($message['params']['position']['line'] ?? 0) >= 90) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('textDocument/implementation' === $method && ($message['params']['position']['line'] ?? 0) >= 80) {
        // Several candidates but all outside the filesystem -> chooser is empty.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                ['uri' => 'untitled:one', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]]],
                ['uri' => 'untitled:two', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]]],
            ],
        ]);
    } elseif ('textDocument/implementation' === $method) {
        // Mixed result: a Location, a LocationLink, an unresolvable URI and a
        // non-array element -> the client normalizes and shows a chooser.
        $uri = $message['params']['textDocument']['uri'];
        $targetUri = preg_replace('#/[^/]+$#', '/Target.php', $uri);
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                ['uri' => $uri, 'range' => ['start' => ['line' => 4, 'character' => 4], 'end' => ['line' => 4, 'character' => 9]]],
                ['targetUri' => $targetUri, 'targetSelectionRange' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]]],
                ['uri' => 'untitled:scratch', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]]],
                'not-an-array',
            ],
        ]);
    } elseif ('textDocument/typeDefinition' === $method) {
        // Single type definition -> jumps directly.
        $uri = $message['params']['textDocument']['uri'];
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'uri' => preg_replace('#/[^/]+$#', '/Target.php', $uri),
                'range' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]],
            ],
        ]);
    } elseif ('textDocument/hover' === $method && ($message['params']['position']['line'] ?? 0) >= 90) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('textDocument/hover' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'contents' => ['kind' => 'plaintext', 'value' => "greet(): string\n\nSays hello to the subject."],
            ],
        ]);
    } elseif ('textDocument/signatureHelp' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'signatures' => [['label' => 'greet(string $name): string']],
                'activeSignature' => 0,
            ],
        ]);
    } elseif ('textDocument/references' === $method && ($message['params']['position']['line'] ?? 0) >= 88) {
        // Lines 88+ have no usages (88-89 pair with the on-declaration
        // definition band above to model "declaration with zero usages").
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => []]);
    } elseif ('textDocument/references' === $method) {
        $uri = $message['params']['textDocument']['uri'];
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                ['uri' => $uri, 'range' => ['start' => ['line' => 4, 'character' => 4], 'end' => ['line' => 4, 'character' => 9]]],
                ['uri' => preg_replace('#/[^/]+$#', '/Target.php', $uri), 'range' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]]],
                ['uri' => 'untitled:scratch', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]]],
            ],
        ]);
    } elseif ('textDocument/prepareCallHierarchy' === $method && ($message['params']['position']['line'] ?? 0) >= 88) {
        // The zero-usages band: a resolvable symbol nobody calls.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [[
                'name' => 'orphan',
                'kind' => 12,
                'uri' => $message['params']['textDocument']['uri'],
                'range' => ['start' => ['line' => 88, 'character' => 0], 'end' => ['line' => 88, 'character' => 6]],
                'selectionRange' => ['start' => ['line' => 88, 'character' => 0], 'end' => ['line' => 88, 'character' => 6]],
            ]],
        ]);
    } elseif ('textDocument/prepareCallHierarchy' === $method && ($message['params']['position']['line'] ?? 0) >= 60) {
        // Lines 60+: nothing preparable at the caret.
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => []]);
    } elseif ('textDocument/prepareCallHierarchy' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [[
                'name' => 'greet',
                'kind' => 12, // function
                'uri' => $message['params']['textDocument']['uri'],
                'range' => ['start' => ['line' => 2, 'character' => 0], 'end' => ['line' => 4, 'character' => 1]],
                'selectionRange' => ['start' => ['line' => 2, 'character' => 9], 'end' => ['line' => 2, 'character' => 14]],
            ]],
        ]);
    } elseif ('callHierarchy/incomingCalls' === $method && 'orphan' === ($message['params']['item']['name'] ?? '')) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => []]);
    } elseif ('callHierarchy/incomingCalls' === $method) {
        $uri = $message['params']['item']['uri'] ?? '';
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                [
                    'from' => [
                        'name' => 'caller',
                        'kind' => 12,
                        'uri' => $uri,
                        'range' => ['start' => ['line' => 6, 'character' => 0], 'end' => ['line' => 8, 'character' => 1]],
                        'selectionRange' => ['start' => ['line' => 6, 'character' => 9], 'end' => ['line' => 6, 'character' => 15]],
                    ],
                    'fromRanges' => [['start' => ['line' => 7, 'character' => 4], 'end' => ['line' => 7, 'character' => 9]]],
                ],
                ['from' => 'not-an-item'],
            ],
        ]);
    } elseif ('textDocument/foldingRange' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                ['startLine' => 2, 'endLine' => 4, 'kind' => 'region'],
                ['startLine' => 6, 'endLine' => 6], // single-line: dropped
                ['startLine' => 'x', 'endLine' => 9], // malformed: dropped
            ],
        ]);
    } elseif ('textDocument/documentSymbol' === $method && str_contains((string) ($message['params']['textDocument']['uri'] ?? ''), 'Empty')) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => []]);
    } elseif ('textDocument/documentSymbol' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [[
                'name' => 'Demo',
                'kind' => 5, // class
                'selectionRange' => ['start' => ['line' => 2, 'character' => 6], 'end' => ['line' => 2, 'character' => 10]],
                'children' => [
                    ['name' => 'greet', 'kind' => 6, 'selectionRange' => ['start' => ['line' => 4, 'character' => 20], 'end' => ['line' => 4, 'character' => 25]]],
                    ['name' => '', 'kind' => 6], // must be skipped
                ],
            ]],
        ]);
    } elseif ('textDocument/rangeFormatting' === $method) {
        // Replace the requested range with a marker so tests can observe it.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [[
                'range' => $message['params']['range'],
                'newText' => 'FORMATTED',
            ]],
        ]);
    } elseif ('textDocument/formatting' === $method && str_contains((string) ($message['params']['textDocument']['uri'] ?? ''), 'noformat')) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('textDocument/formatting' === $method) {
        // Replace the whole document (lines 0-999) with a canonical body.
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [[
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 999, 'character' => 0]],
                'newText' => "<?php\n\nformatted();\n",
            ]],
        ]);
    } elseif ('workspace/symbol' === $method) {
        $query = strtolower((string) ($message['params']['query'] ?? ''));
        $all = [
            ['name' => 'Greeter', 'kind' => 5, 'line' => 3, 'file' => 'Target.php'],
            ['name' => 'makeGreeting', 'kind' => 6, 'line' => 8, 'file' => 'Demo.php'],
            ['name' => 'greetVirtual', 'kind' => 12, 'line' => 0, 'file' => null], // untitled uri, must be skipped
        ];
        $items = [];
        foreach ($all as $s) {
            if ('' === $query || str_contains(strtolower($s['name']), $query)) {
                $items[] = [
                    'name' => $s['name'],
                    'kind' => $s['kind'],
                    'location' => [
                        'uri' => null === $s['file'] ? 'untitled:virtual' : preg_replace('#/[^/]+$#', '/'.$s['file'], $lastUri ?? 'file:///tmp/x.php'),
                        'range' => ['start' => ['line' => $s['line'], 'character' => 0], 'end' => ['line' => $s['line'], 'character' => 1]],
                    ],
                ];
            }
        }
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $items]);
    } elseif ('textDocument/codeAction' === $method && ($message['params']['range']['start']['line'] ?? 0) >= 90) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => []]);
    } elseif ('textDocument/codeAction' === $method) {
        $uri = $message['params']['textDocument']['uri'];
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                [
                    'title' => 'Import class',
                    'kind' => 'quickfix',
                    'edit' => [
                        'changes' => [
                            $uri => [[
                                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                                'newText' => "use Acme\\Imported;\n",
                            ]],
                        ],
                    ],
                ],
                [
                    'title' => 'Generate method',
                    'kind' => 'refactor',
                    'command' => ['title' => 'Generate method', 'command' => 'mock.generate', 'arguments' => [$uri]],
                ],
            ],
        ]);
    } elseif ('workspace/executeCommand' === $method) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
        // Effect arrives as a server-initiated applyEdit, like phpactor
        $target = $message['params']['arguments'][0] ?? $lastUri;
        if (null !== $target) {
            sendMessage($out, [
                'jsonrpc' => '2.0',
                'id' => 4001,
                'method' => 'workspace/applyEdit',
                'params' => [
                    'edit' => [
                        'changes' => [
                            $target => [[
                                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                                'newText' => "// generated\n",
                            ]],
                        ],
                    ],
                ],
            ]);
        }
    } elseif ('textDocument/prepareRename' === $method && ($message['params']['position']['line'] ?? 0) >= 95) {
        // Convention: line >= 95 cannot be renamed.
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('textDocument/prepareRename' === $method) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'range' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]],
            ],
        ]);
    } elseif ('textDocument/rename' === $method && 'ERR' === ($message['params']['newName'] ?? '')) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32600, 'message' => 'cannot rename this']]);
    } elseif ('textDocument/rename' === $method) {
        $uri = $message['params']['textDocument']['uri'];
        $newName = $message['params']['newName'];
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => [
                'changes' => [
                    $uri => [[
                        'range' => ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]],
                        'newText' => $newName,
                    ]],
                ],
            ],
        ]);
    } elseif ('textDocument/didOpen' === $method || 'textDocument/didChange' === $method) {
        $lastUri = $message['params']['textDocument']['uri'];
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => [
                'uri' => $message['params']['textDocument']['uri'],
                'diagnostics' => [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]],
                    'severity' => 1,
                    'message' => 'Mock diagnostic',
                ]],
            ],
        ]);
        // Diagnostics for a file the client never opened (must be ignored)
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => ['uri' => 'file:///porta-mock-unknown.php', 'diagnostics' => []],
        ]);
    } elseif ('shutdown' === $method) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
    } elseif ('exit' === $method) {
        exit(0);
    }
}
