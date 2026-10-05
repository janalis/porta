<?php

declare(strict_types=1);

/**
 * Answers initialize, and on `exit` takes a moment to save what it keeps —
 * writing argv[1] when done — as a real server saves its index. A SIGTERM
 * meanwhile ends it before the file is written: proof of whether it was let
 * finish. Bounded lifetime: a leaked child cannot outlive the test run.
 */
pcntl_alarm(30);

$in = \STDIN;
$out = \STDOUT;

require __DIR__.'/lsp-stub.php';

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';
    if ('initialize' === $method) {
        sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
    }
    if ('exit' === $method) {
        usleep(300_000);
        file_put_contents($argv[1], data: 'saved');
        exit(0);
    }
}
