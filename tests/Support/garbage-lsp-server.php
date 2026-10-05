<?php

declare(strict_types=1);

// Fault server: emits a corrupt header block (no Content-Length) before
// answering the initialize request correctly — exercises frame resync.

require __DIR__.'/lsp-stub.php';

$in = \STDIN;
$out = \STDOUT;

// The deviant part, and the only one: a header terminator with no
// Content-Length. Everything after it is ordinary framing.
fwrite($out, data: "X-Garbage: yes\r\n\r\n");
fflush($out);

foreach (readFrames($in) as $message) {
    if ('initialize' === ($message['method'] ?? '')) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
    }
    if ('exit' === ($message['method'] ?? '')) {
        exit(0);
    }
}
