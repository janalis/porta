<?php

declare(strict_types=1);

// Fault server: emits a corrupt header block (no Content-Length) before
// answering the initialize request correctly — exercises frame resync.

$in = fopen('php://stdin', mode: 'rb');
$out = fopen('php://stdout', mode: 'wb');

require __DIR__.'/lsp-stub.php';

// Corrupt frame first: header terminator without a Content-Length
fwrite($out, data: "X-Garbage: yes\r\n\r\n");
fflush($out);

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
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
        }
        if ('exit' === ($message['method'] ?? '')) {
            exit(0);
        }
    }
}
