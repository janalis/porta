<?php

declare(strict_types=1);

// What a bundled PHP must be able to do. A build that fails here would start,
// and then quietly lose the integrated terminal: proc_open falls back to pipes.

$problems = [];
if (PHP_ZTS) {
    $problems[] = 'a thread-safe build: pcntl crashes under ZTS';
}
foreach (['ctype', 'mbstring', 'pcntl', 'posix', 'phar', 'gd', 'simplexml', 'dom', 'tokenizer', 'openssl', 'sodium'] as $extension) {
    if (!extension_loaded($extension)) {
        $problems[] = 'missing extension '.$extension;
    }
}

$pipes = [];
$shim = 'posix_setsid(); pcntl_exec("/bin/sh", ["-c", "test -t 0 && test -t 1 && echo REAL_PTY"]);';
$process = @proc_open([PHP_BINARY, '-n', '-r', $shim], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $pipes);
if (!is_resource($process)) {
    $problems[] = 'proc_open has no PTY support';
} else {
    $output = '';
    $deadline = microtime(true) + 5.0;
    stream_set_blocking($pipes[1], false);
    while (!str_contains($output, 'REAL_PTY') && microtime(true) < $deadline) {
        $output .= (string) fread($pipes[1], 1024);
        usleep(20_000);
    }
    proc_close($process);
    if (!str_contains($output, 'REAL_PTY')) {
        $problems[] = 'the child did not get a terminal';
    }
}

if ([] !== $problems) {
    fwrite(STDERR, "check-php: ".implode("; ", $problems)."\n");
    exit(1);
}
echo 'check-php: PHP '.PHP_VERSION." can run Porta\n";
