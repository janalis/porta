<?php

declare(strict_types=1);

/*
 * The Windows application's terminal, inside WSL: Porta runs on a real PTY here,
 * and its bytes cross wsl.exe's plain pipes untouched. Windows' own console layer
 * re-renders what passes through it and drops the sequences it does not know.
 *
 * Standard output is the PTY's output. Standard input is framed:
 *   0x00, u32 length (big endian), the bytes to type
 *   0x01, u16 columns, u16 rows
 *
 * Usage: php wsl-relay.php <columns> <rows> <command> [arguments…]
 */

const INPUT = 0;
const RESIZE = 1;

[$columns, $rows] = [(int) ($argv[1] ?? 80), (int) ($argv[2] ?? 24)];
$command = array_slice($argv, 3);
if ([] === $command) {
    fwrite(STDERR, "wsl-relay: no command\n");
    exit(2);
}

// A session of its own, with the PTY as its terminal: Porta reads resizes from it.
$shim = sprintf(
    'posix_setsid(); $tty = posix_ttyname(STDIN); if (false !== $tty) { $keep = fopen($tty, "r+"); } @exec("stty rows %d cols %d 2>/dev/null"); pcntl_exec($argv[1], array_slice($argv, 2)); exit(127);',
    $rows,
    $columns,
);
$pipes = [];
// The shim's PHP without its ini files, but with the posix it calls: built into the bundled PHP,
// it is a module for a distribution's, which -n would leave out (Porta's LoginEnvironment does the same).
$extensionDir = (string) ini_get('extension_dir');
$modules = array_values(array_filter(['pcntl', 'posix'], static fn (string $name): bool => is_file($extensionDir.'/'.$name.'.so')));
$bare = [PHP_BINARY, '-n', ...([] === $modules ? [] : ['-d', 'extension_dir='.$extensionDir, ...array_merge(...array_map(static fn (string $name): array => ['-d', 'extension='.$name], $modules))])];
$child = proc_open([...$bare, '-r', $shim, '--', ...$command], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $pipes);
if (!is_resource($child)) {
    fwrite(STDERR, "wsl-relay: could not start the command\n");
    exit(127);
}
$master = $pipes[0];
$pid = proc_get_status($child)['pid'];
stream_set_blocking($master, false);
stream_set_blocking(STDIN, false);
stream_set_write_buffer(STDOUT, 0);

$resize = static function (int $columns, int $rows) use ($master, $pid): void {
    $unused = [];
    $stty = proc_open(['stty', 'cols', (string) $columns, 'rows', (string) $rows], [0 => $master, 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $unused);
    if (is_resource($stty)) {
        proc_close($stty);
    }
    posix_kill($pid, SIGWINCH);
};

$writeAll = static function ($stream, string $bytes): bool {
    while ('' !== $bytes) {
        $written = @fwrite($stream, $bytes);
        if (false === $written) {
            return false;
        }
        if (0 === $written) {
            $read = $except = null;
            $write = [$stream];
            stream_select($read, $write, $except, 1);
            continue;
        }
        $bytes = substr($bytes, $written);
    }

    return true;
};

$frames = '';
$hostGone = false;
// When the window went: the command has this long to end on its SIGHUP,
// then its process group is killed. One that ignores the hangup kept the
// relay, and so wsl.exe, alive for good.
$goneAt = null;
const HANGUP_GRACE = 3.0;
// Once the command's last process leaves the terminal, reading it fails (EIO on
// Linux) or ends, and does so at once, every time it is asked: read again, it
// spun, and its PHP notice went into the very output this relay carries.
$terminalOpen = true;
$exit = null;
while (true) {
    $read = [...($terminalOpen ? [$master] : []), ...($hostGone ? [] : [STDIN])];
    $write = $except = null;
    if ([] === $read) {
        usleep(100_000); // nothing left to carry: wait for the command to be gone
    } elseif (false === @stream_select($read, $write, $except, 0, 100_000)) {
        pcntl_signal_dispatch();
    }
    foreach ($read as $stream) {
        if ($stream === $master) {
            $bytes = @fread($master, 65_536);
            if (false === $bytes || ('' === $bytes && feof($master))) {
                $terminalOpen = false;
                continue;
            }
            if ('' !== $bytes && !$writeAll(STDOUT, $bytes)) {
                break 2;
            }
            continue;
        }
        $bytes = fread($stream, 65_536);
        if (false === $bytes || ('' === $bytes && feof(STDIN))) {
            // The window is gone: so is the terminal.
            $hostGone = true;
            $goneAt = microtime(true);
            posix_kill($pid, SIGHUP);
            continue;
        }
        $frames .= $bytes;
        while ('' !== $frames) {
            $type = ord($frames[0]);
            if (RESIZE === $type) {
                if (strlen($frames) < 5) {
                    break;
                }
                ['c' => $c, 'r' => $r] = unpack('nc/nr', substr($frames, 1, 4));
                $frames = substr($frames, 5);
                $resize($c, $r);
                continue;
            }
            if (INPUT !== $type) {
                fwrite(STDERR, "wsl-relay: malformed frame\n");
                $frames = '';
                break;
            }
            if (strlen($frames) < 5) {
                break;
            }
            $length = unpack('N', substr($frames, 1, 4))[1];
            if (strlen($frames) < 5 + $length) {
                break;
            }
            $writeAll($master, substr($frames, 5, $length));
            $frames = substr($frames, 5 + $length);
        }
    }
    if (null !== $goneAt && microtime(true) - $goneAt > HANGUP_GRACE) {
        posix_kill(-$pid, SIGKILL); // its own session: setsid made it a group leader
        $goneAt = null;
    }
    $status = proc_get_status($child);
    if (!$status['running']) {
        $exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
        // What it printed last is still on its way.
        while ($terminalOpen && '' !== ($bytes = (string) @fread($master, 65_536))) {
            $writeAll(STDOUT, $bytes);
        }
        break;
    }
}
exit($exit ?? 1);
