<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Store\HttpFetch;

/**
 * The network, scripted: an {@see HttpFetch} whose curl is a closure that
 * reads the `-o <file> <url>` pairs out of the argv it is handed and writes
 * each address's canned body to its file — so the real HttpFetch reads them
 * back exactly as it reads curl's. An address with no body is a failed
 * transfer: no file.
 *
 * With $hold set, each batch waits in $held until {@see release()}, which is
 * how a test sees what happens while a fetch is still out.
 */
final class FakeHttp
{
    /** @var array<string, string> address => what it answers */
    public array $bodies = [];

    /** @var list<list<string>> each batch's addresses, in the order asked */
    public array $batches = [];

    /** @var list<list<string>> each argv handed to "curl" */
    public array $argvs = [];

    public bool $hold = false;

    /** What the whole curl process exits with: some transfer failing makes it non-zero. */
    public int $exitCode = 0;

    /** @var list<\Closure(): void> the batches waiting for release() */
    private array $held = [];

    public function fetch(): HttpFetch
    {
        return new HttpFetch(function (array $argv, string $cwd, \Closure $onExit): void {
            $this->argvs[] = $argv;
            $pairs = [];
            foreach ($argv as $i => $arg) {
                if ('-o' !== $arg) {
                    continue;
                }
                $pairs[] = [$argv[$i + 1], $argv[$i + 2]];
            }
            $this->batches[] = \array_map(static fn (array $pair): string => $pair[1], $pairs);
            $answer = function () use ($pairs, $onExit): void {
                // curl's --write-out, one line a transfer: index, exit code, HTTP status.
                $outcomes = '';
                foreach ($pairs as $index => [$file, $url]) {
                    $found = \array_key_exists($url, $this->bodies);
                    $outcomes .= $index.($found ? ' 0 200' : ' 22 404')."\n";
                    if ($found) {
                        \file_put_contents($file, $this->bodies[$url]);
                    }
                }
                $onExit($this->exitCode, $outcomes);
            };
            if ($this->hold) {
                $this->held[] = $answer;

                return;
            }
            $answer();
        });
    }

    /** Answer every batch held so far. */
    public function release(): void
    {
        $held = $this->held;
        $this->held = [];
        foreach ($held as $answer) {
            $answer();
        }
    }

    /** @return list<string> every address asked for, across batches */
    public function asked(): array
    {
        return \array_merge(...$this->batches);
    }
}
