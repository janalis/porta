<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Plugin\Tool\LatestVersions;
use Porta\Plugin\Tool\ProcessRunner;
use Porta\Plugin\Tool\RunningProcesses;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Several HTTPS GETs in ONE curl process, off the loop: the store asks for a
 * page of packages, then every package's releases, then a page's images —
 * one spawn per batch rather than one per address, with curl running six
 * transfers at a time.
 *
 * Each address answers its body, or null: a refused, failed, oversized or
 * empty transfer is the same "nothing" to every caller, which degrades rather
 * than reports. Nothing here blocks, and nothing toasts.
 */
#[Autoconfigure(constructor: 'create')]
final readonly class HttpFetch
{
    /** A batch answers when it is done; nothing is watched as it arrives. */
    private const null NO_CHUNKS = null;

    /** What curl writes after each transfer: its index, its exit code, its HTTP status. */
    private const string OUTCOME = '%{urlnum} %{exitcode} %{http_code}\n';

    /** How many transfers curl runs at once. */
    private const string PARALLEL = '6';

    /** @var \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void)|null $run
     *        inject a fake to test without the network
     */
    public function __construct(
        ?\Closure $run = null,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->run = $run ?? ProcessRunner::default();
    }

    /** Production wiring: real processes, kept where quitting stops them. */
    public static function create(RunningProcesses $runs): self
    {
        return new self(ProcessRunner::default(runs: $runs));
    }

    /**
     * Every address in $urls, at once.
     *
     * @param list<string>                         $urls
     * @param \Closure(array<string, ?string>): void $onDone exactly once: each address => its body, or null
     * @param int                                  $maxBytes refuse a body larger than this; 0 takes any
     */
    public function get(array $urls, \Closure $onDone, int $maxBytes = 0): void
    {
        if ([] === $urls) {
            $onDone([]);

            return;
        }
        $dir = \sys_get_temp_dir().'/porta-store-'.\bin2hex(\random_bytes(6));
        $this->filesystem->mkdir($dir);
        // --globoff: an address off the network is an address, never a curl
        // pattern — `[1-1000000].png` would be a million transfers.
        $argv = [...LatestVersions::CURL, '--globoff', '--parallel', '--parallel-max', self::PARALLEL, '--write-out', self::OUTCOME];
        if ($maxBytes > 0) {
            $argv[] = '--max-filesize';
            $argv[] = (string) $maxBytes;
        }
        foreach ($urls as $index => $url) {
            $argv[] = '-o';
            $argv[] = $dir.'/'.$index;
            $argv[] = $url;
        }
        $filesystem = $this->filesystem;
        ($this->run)(
            $argv,
            $dir,
            // The exit code says only that SOME transfer failed: each
            // transfer's own outcome says which — a body cut short by a
            // timeout or a size cap is on disk all the same, and is nothing.
            static function (int $code, string $out) use ($dir, $urls, $filesystem, $onDone): void {
                $outcomes = self::outcomes($out);
                $bodies = [];
                foreach ($urls as $index => $url) {
                    $file = $dir.'/'.$index;
                    $whole = $outcomes[$index] ?? [] === $outcomes;
                    $body = $whole && \is_file($file) ? (string) \file_get_contents($file) : '';
                    $bodies[$url] = '' === $body ? null : $body;
                }
                $filesystem->remove($dir);
                $onDone($bodies);
            },
            self::NO_CHUNKS,
        );
    }

    /**
     * Which transfers came back whole — curl's exit code 0 and an HTTP 200 —
     * by index. Empty when curl wrote no outcome it can be read from: a curl
     * older than 7.75 knows neither variable, and then a file being there is
     * all there is to go on.
     *
     * @return array<int, bool>
     */
    private static function outcomes(string $out): array
    {
        $outcomes = [];
        foreach (\explode("\n", $out) as $line) {
            $match = [];
            if (1 !== \preg_match('/^(\d+) (\d+) (\d+)$/', \trim($line), $match)) {
                continue;
            }
            $outcomes[(int) $match[1]] = '0' === $match[2] && '200' === $match[3];
        }

        return $outcomes;
    }
}
