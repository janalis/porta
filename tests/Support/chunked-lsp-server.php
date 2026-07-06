<?php

declare(strict_types=1);

// Fault server: answers initialize with a frame split across two writes
// separated by a pause, exercising the client's partial-frame buffering.

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
            $json = json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
            $frame = 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
            fwrite($out, substr($frame, 0, 25)); // header split mid-way
            fflush($out);
            usleep(120_000);
            fwrite($out, substr($frame, 25));
            fflush($out);
        }
        if ('exit' === ($message['method'] ?? '')) {
            exit(0);
        }
    }
}
