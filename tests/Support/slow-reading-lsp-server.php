<?php

declare(strict_types=1);

/**
 * An LSP server that handshakes normally, then drains its stdin in tiny
 * chunks with pauses — forcing the client into short writes so its outbound
 * queue and onWritable drain path are exercised. Every framed message body is
 * digested and echoed back as a `porta/digest` notification, letting the test
 * assert that queued frames arrive intact and in order.
 */

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

require __DIR__.'/lsp-stub.php';
// Non-blocking tiny reads: a blocking fread(512) would stall inside PHP's
// read-buffer top-up while holding undelivered bytes.
stream_set_blocking($in, false);

$buffer = '';
$deadline = time() + 30; // bounded lifetime
while (!feof($in) && time() < $deadline) {
    $chunk = fread($in, 512); // tiny slow reads: the client's writes go short and queue
    if (!is_string($chunk) || '' === $chunk) {
        usleep(2000);

        continue;
    }
    $buffer .= $chunk;
    usleep(2000);

    while (true) {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if (false === $headerEnd || !preg_match('/Content-Length:\s*(\d+)/i', substr($buffer, 0, $headerEnd), $m)) {
            break;
        }
        $length = (int) $m[1];
        $bodyStart = $headerEnd + 4;
        if (strlen($buffer) < ($bodyStart + $length)) {
            break;
        }
        $body = substr($buffer, $bodyStart, $length);
        $buffer = substr($buffer, $bodyStart + $length);
        $message = json_decode($body, true);
        if (!is_array($message)) {
            continue;
        }

        if ('initialize' === ($message['method'] ?? '')) {
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
        } elseif ('exit' === ($message['method'] ?? '')) {
            exit(0);
        } else {
            sendMessage($out, ['jsonrpc' => '2.0', 'method' => 'porta/digest', 'params' => ['md5' => md5($body), 'method' => $message['method'] ?? '']]);
        }
    }
}
