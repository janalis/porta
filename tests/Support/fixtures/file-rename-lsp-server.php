<?php

declare(strict_types=1);

/**
 * A language server that hears of PHP files moving, as phpactor does: it
 * registers `workspace.fileOperations.willRename` for `**​/*.php` and answers
 * `workspace/willRenameFiles` the way phpactor answered a probe — the moved
 * file's namespace, and the `use` of it in Bar.php beside it.
 *
 * argv[1] picks the answer: `edit` (that edit), `none` (null: nothing names
 * the file), `refused` (an edit the editor refuses) or `error`. Every request it receives is appended, by method, to
 * `requests.log` in the directory it runs in, so a test can prove one was
 * never sent.
 */

require __DIR__.'/../lsp-stub.php';

$mode = $argv[1] ?? 'edit';
foreach (readFrames(\STDIN) as $message) {
    $method = $message['method'] ?? '';
    if (!\array_key_exists('id', $message)) {
        continue; // a notification
    }
    \file_put_contents('requests.log', $method."\n", \FILE_APPEND);
    if ('workspace/willRenameFiles' === $method && 'error' === $mode) {
        sendMessage(\STDOUT, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32_603, 'message' => 'There are no class name candidates']]);

        continue;
    }
    $file = $message['params']['files'][0] ?? null;
    $range = static fn (int $line, int $from, int $to): array => ['start' => ['line' => $line, 'character' => $from], 'end' => ['line' => $line, 'character' => $to]];
    $result = match (true) {
        'initialize' === $method => [
            'capabilities' => [
                'textDocumentSync' => 1,
                'workspace' => ['fileOperations' => ['willRename' => ['filters' => [['pattern' => ['glob' => '**/*.php']], ['pattern' => ['glob' => '**/*.php', 'matches' => 'folder']]]]]],
            ],
        ],
        'workspace/willRenameFiles' === $method && 'edit' === $mode => [
            'documentChanges' => [
                ['textDocument' => ['uri' => $file['newUri'], 'version' => null], 'edits' => [['range' => $range(line: 1, from: 10, to: 13), 'newText' => 'App\\Sub']]],
                [
                    'textDocument' => ['uri' => \dirname((string) $file['oldUri']).'/Bar.php', 'version' => null],
                    'edits' => [['range' => $range(line: 2, from: 4, to: 11), 'newText' => 'App\\Sub\\Foo']],
                ],
            ],
        ],
        // Creating a file that exists, which the editor refuses whole.
        'workspace/willRenameFiles' === $method && 'refused' === $mode => [
            'documentChanges' => [
                ['kind' => 'create', 'uri' => \dirname((string) $file['oldUri']).'/Bar.php'],
            ],
        ],
        default => null,
    };
    sendMessage(\STDOUT, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
}
