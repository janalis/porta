<?php

declare(strict_types=1);

/*
 * The relay the Windows application runs Porta through, driven on a POSIX machine the way the
 * window drives it: the trust prompt answered, a resize, then a lone ESC the moment the first
 * run's welcome card is drawn. Each run starts from a fresh home, so each is a first run.
 *
 * Usage: php relay-smoke.php --php <php> --phar <porta.phar> [--runs N] [--projects <dir>] [--strict]
 * --projects is where the projects are made (a Windows drive under WSL is slower than /tmp);
 * --strict fails when an Escape does not close the card.
 */

$options = getopt('', ['php:', 'phar:', 'runs:', 'projects:', 'strict']);
$php = (string) ($options['php'] ?? PHP_BINARY);
$phar = (string) ($options['phar'] ?? '');
$runs = (int) ($options['runs'] ?? 5);
if ('' === $phar || !is_file($phar)) {
    fwrite(STDERR, "relay-smoke: --phar <porta.phar> is needed\n");
    exit(2);
}
// Porta starts in its project: a path relative to here would not be found there.
$phar = (string) realpath($phar);
$php = str_contains($php, '/') ? (string) realpath($php) : $php;
$relay = __DIR__.'/wsl-relay.php';
$scratch = rtrim((string) ($options['projects'] ?? sys_get_temp_dir()), '/').'/relay-smoke.'.getmypid();
$closed = 0;

for ($run = 1; $run <= $runs; ++$run) {
    $home = "$scratch/home$run";
    $project = "$scratch/shop$run";
    @mkdir("$home/.porta", 0o777, true);
    @mkdir($project, 0o777, true);
    file_put_contents("$home/.porta/config.json", '{"settings":{"language":"en"}}');
    $token = bin2hex(random_bytes(16));
    $environment = ['PORTA_HOME' => $home, 'PORTA_DESKTOP' => '1', 'PORTA_DESKTOP_TOKEN' => $token, 'TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor'] + getenv();
    $process = proc_open(
        [$php, '-n', $relay, '80', '24', $php, '-n', '-d', 'memory_limit=-1', '-d', 'display_errors=0', $phar, $project],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "$scratch/relay$run.log", 'w']],
        $pipes,
        $project,
        $environment,
    );
    stream_set_blocking($pipes[1], false);
    $out = '';
    $readFor = static function (float $seconds) use (&$out, $pipes): void {
        $end = microtime(true) + $seconds;
        while (($left = $end - microtime(true)) > 0) {
            $read = [$pipes[1]];
            $none = null;
            if (stream_select($read, $none, $none, 0, (int) min(50_000, $left * 1e6)) > 0) {
                $out .= (string) fread($pipes[1], 65_536);
            }
        }
    };
    $readUntil = static function (callable $seen, float $limit) use (&$out, $readFor): bool {
        $end = microtime(true) + $limit;
        while (!$seen($out) && microtime(true) < $end) {
            $readFor(0.05);
        }

        return $seen($out);
    };
    $type = static fn (string $bytes) => fwrite($pipes[0], "\x00".pack('N', strlen($bytes)).$bytes);

    $verdict = 'no trust prompt';
    if ($readUntil(static fn (string $o): bool => str_contains($o, 'Trust this project'), 180)) {
        $type("\r");
        $verdict = 'not ready';
        if ($readUntil(static fn (string $o): bool => str_contains($o, "porta-desktop;$token;ready"), 180)) {
            $readyAt = strlen($out);
            fwrite($pipes[0], "\x01".pack('nn', 100, 30));
            $readFor(1.0);
            file_put_contents("$project/a.php", "<?php\n");
            $verdict = 'no welcome card';
            if ($readUntil(static fn (string $o): bool => false !== strpos($o, 'Welcome', $readyAt), 30)) {
                $before = strlen($out);
                $sent = microtime(true);
                $type("\x1b");
                $readFor(3.0);
                $redrawn = strlen($out) - $before;
                $welcomeAt = strrpos(substr($out, 0, $before), 'Welcome');
                $closed += $redrawn > 0 ? 1 : 0;
                $verdict = sprintf('Escape redrew %d bytes (the card drawn %d bytes after ready, of %d)', $redrawn, $welcomeAt - $readyAt, $before - $readyAt);
                if (0 === $redrawn) {
                    // A loop blocked after the first paint would answer late, if at all: how late.
                    $readUntil(static fn (string $o): bool => strlen($o) > $before, 15);
                    $verdict .= strlen($out) > $before
                        ? sprintf('; Porta drew again %.1fs after the Escape', microtime(true) - $sent)
                        : '; Porta drew nothing for 18s after it';
                    $freezes = (string) @file_get_contents("$project/.porta/freeze.log");
                    $verdict .= "\n  freeze.log: ".('' === $freezes ? '(none)' : substr($freezes, -1500));
                }
            }
        }
    }
    echo "relay-smoke: run $run: $verdict\n";
    if (!str_starts_with($verdict, 'Escape')) {
        // What Porta said, and drew, when it did not get that far.
        echo '  its log: '.substr((string) @file_get_contents("$scratch/relay$run.log"), -800)."\n";
        echo '  its screen: '.substr((string) preg_replace('/\e(\[[0-?]*[ -\/]*[@-~]|\][^\a\e]*(\a|\e\\\\)|[@-Z\\\\-_])/', ' ', $out), -600)."\n";
    }
    proc_terminate($process, 9);
    proc_close($process);
    exec('pkill -9 -f '.escapeshellarg($project).' 2>/dev/null');
}

echo "relay-smoke: Escape closed the welcome card in $closed of $runs runs\n";
exec('rm -rf '.escapeshellarg($scratch));
exit(isset($options['strict']) && $closed < $runs ? 1 : 0);
