<?php

declare(strict_types=1);

// Fault server: rejects the initialize request with a JSON-RPC error.

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

$buffer = '';
while (!feof($in)) {
    $chunk = fread($in, 8192);
    if (false === $chunk) {
        break;
    }
    $buffer .= $chunk;

    if (false !== ($headerEnd = strpos($buffer, "\r\n\r\n")) && preg_match('/Content-Length:\s*(\d+)/i', substr($buffer, 0, $headerEnd), $m) && \strlen($buffer) >= ($headerEnd + 4 + (int) $m[1])) {
        $message = json_decode(substr($buffer, $headerEnd + 4, (int) $m[1]), true);
        $buffer = substr($buffer, $headerEnd + 4 + (int) $m[1]);

        if ('initialize' === ($message['method'] ?? '')) {
            $json = json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32002, 'message' => 'server not ready']]);
            fwrite($out, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
            fflush($out);
        }
        if ('exit' === ($message['method'] ?? '')) {
            exit(0);
        }
    }
}
