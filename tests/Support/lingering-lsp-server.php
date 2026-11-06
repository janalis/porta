<?php

declare(strict_types=1);

/**
 * Answers initialize, then outlives every polite way of ending it: SIGTERM is
 * ignored and `exit` is not obeyed, so only the reaper's kill ends it. Writes
 * its pid to the file named by its first argument, so a test can ask whether
 * it is still alive. Bounded, so a leaked one cannot outlive the run.
 */
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
pcntl_alarm(30);
file_put_contents($argv[1], (string) getmypid());

require __DIR__.'/lsp-stub.php';

foreach (readFrames(\STDIN) as $message) {
    if ('initialize' !== ($message['method'] ?? '')) {
        continue;
    }
    sendMessage(\STDOUT, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
}
sleep(30);
