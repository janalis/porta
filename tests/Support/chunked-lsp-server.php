<?php

declare(strict_types=1);

// Fault server: answers initialize with a frame split across two writes
// separated by a pause, exercising the client's partial-frame buffering.

require __DIR__.'/lsp-stub.php';

$in = fopen('php://stdin', mode: 'rb');
$out = fopen('php://stdout', mode: 'wb');

foreach (readFrames($in) as $message) {
    if ('initialize' === ($message['method'] ?? '')) {
        // Hand-rolled on purpose, and the ONLY hand-rolled part: splitting the
        // frame is the fault this stub exists to simulate, so it cannot go
        // through sendMessage(). Reading is ordinary and uses the shared loop.
        $json = json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
        $frame = 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        fwrite($out, substr($frame, offset: 0, length: 25)); // header split mid-way
        fflush($out);
        usleep(120_000);
        fwrite($out, substr($frame, offset: 25));
        fflush($out);
    }
    if ('exit' === ($message['method'] ?? '')) {
        exit(0);
    }
}
