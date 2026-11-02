<?php

declare(strict_types=1);

/**
 * The language server the docs screenshots are taken with: answers like a PHP
 * server would for {@see Porta\Tests\Support\Screenshots\ShopProject}, so the
 * completion list and the hover a reader sees are about that code, and the
 * status bar names a real server rather than a test stub.
 *
 * Standalone script — Content-Length framed JSON-RPC over stdio.
 */

require __DIR__.'/lsp-stub.php';

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

$method = static fn (string $name, string $detail, string $doc): array => [
    'label' => $name,
    'kind' => 2,
    'detail' => $detail,
    'documentation' => ['kind' => 'markdown', 'value' => $doc],
    'insertText' => $name.'(',
];

foreach (readFrames($in) as $message) {
    $id = $message['id'] ?? null;
    $name = $message['method'] ?? '';
    if ('initialize' === $name) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'capabilities' => [
                    'textDocumentSync' => 1,
                    'completionProvider' => ['triggerCharacters' => ['>', ':', '$']],
                    'hoverProvider' => true,
                ],
                'serverInfo' => ['name' => 'phpantom'],
            ],
        ]);
    } elseif ('textDocument/completion' === $name) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'isIncomplete' => false,
                'items' => [
                    $method('add', 'add(Product $product, int $quantity = 1): void', 'Add a product to the order.'),
                    $method('isEmpty', 'isEmpty(): bool', 'Whether the order has no lines yet.'),
                    $method('total', 'total(): Money', 'The sum of every line, in the order currency.'),
                    ['label' => 'customer', 'kind' => 10, 'detail' => 'Customer', 'insertText' => 'customer'],
                    ['label' => 'reference', 'kind' => 10, 'detail' => 'string', 'insertText' => 'reference'],
                ],
            ],
        ]);
    } elseif ('textDocument/hover' === $name) {
        sendMessage($out, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['contents' => [
                'kind' => 'markdown',
                'value' => "```php\npublic function total(): Money\n```\n\nThe sum of every line, in the order currency.",
            ]],
        ]);
    } elseif ('shutdown' === $name) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $id, 'result' => null]);
    } elseif ('exit' === $name) {
        exit(0);
    } elseif (null !== $id) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $id, 'result' => null]);
    }
}
