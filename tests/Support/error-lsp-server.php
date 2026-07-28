<?php

declare(strict_types=1);

// Fault server: rejects the initialize request with a JSON-RPC error.

$in = fopen('php://stdin', mode: 'rb');
$out = fopen('php://stdout', mode: 'wb');

require __DIR__.'/lsp-stub.php';

$buffer = '';
while (!feof($in)) {
    $chunk = fread($in, length: 8192);
    if (false === $chunk) {
        break;
    }
    $buffer .= $chunk;

    if (
        false !== ($headerEnd = strpos($buffer, needle: "\r\n\r\n"))
        && preg_match('/Content-Length:\s*(\d+)/i', substr($buffer, offset: 0, length: $headerEnd), $m)
        && \strlen($buffer) >= ($headerEnd + 4 + (int) $m[1])
    ) {
        $message = json_decode(substr($buffer, $headerEnd + 4, (int) $m[1]), associative: true);
        $buffer = substr($buffer, $headerEnd + 4 + (int) $m[1]);

        if ('initialize' === ($message['method'] ?? '')) {
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32_002, 'message' => 'server not ready']]);
        }
        if ('exit' === ($message['method'] ?? '')) {
            exit(0);
        }
    }
}
