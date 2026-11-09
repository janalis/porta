<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Config\PortaHome;
use Porta\Support\ControlChars;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What a store page shows beyond the catalog — a changelog, a README, a
 * screenshot — fetched only when that page opens, and kept under
 * `~/.porta/cache/store/assets` for a week, so paging through the store
 * again costs nothing.
 *
 * A changelog and a README are read at the commit their release was tagged
 * on, so their address alone names their content. A screenshot is an address
 * its author may overwrite: a week is how stale one may get.
 *
 * Bounded both ways: a screenshot over {@see MAX_IMAGE} or that is not a PNG,
 * and a text over {@see MAX_TEXT}, are nothing — a store page is not where a
 * plugin gets to fill the disk or the terminal.
 */
#[Autoconfigure(constructor: 'create')]
final class StoreAssets
{
    public const int MAX_IMAGE = 2_097_152;

    public const int MAX_TEXT = 262_144;

    /** How long a kept asset is used before it is asked for again. */
    private const int KEEP_FOR = 604_800;

    private const string PNG = "\x89PNG\r\n\x1a\n";

    /** @var array<string, string|null> address => what it answered this session */
    private array $memo = [];

    /** @var array<string, list<\Closure(?string): void>> address => who waits for it */
    private array $pending = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param string|null            $cacheDir where assets are kept, or null on a machine with no home
     * @param (\Closure(): int)|null $clock    seconds since the epoch; inject to test
     */
    public function __construct(
        private readonly HttpFetch $http,
        private readonly ?string $cacheDir,
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? \time(...);
    }

    public static function create(HttpFetch $http): self
    {
        $home = PortaHome::base();

        return new self($http, null === $home ? null : $home.'/.porta/cache/store/assets');
    }

    /**
     * The text at $url — a changelog or a README.
     *
     * @param \Closure(?string): void $onReady exactly once, possibly before this returns
     */
    public function text(string $url, \Closure $onReady): void
    {
        // Someone else's markdown, drawn in the terminal: no escape sequence of its own reaches it.
        $this->asset($url, self::MAX_TEXT, static fn (string $body): bool => \mb_check_encoding($body, encoding: 'UTF-8'), static function (?string $body) use ($onReady): void {
            $onReady(null === $body ? null : ControlChars::page($body));
        });
    }

    /**
     * The PNG at $url.
     *
     * @param \Closure(?string): void $onReady exactly once, possibly before this returns
     */
    public function image(string $url, \Closure $onReady): void
    {
        $this->asset($url, self::MAX_IMAGE, static fn (string $body): bool => \str_starts_with($body, self::PNG), $onReady);
    }

    /**
     * @param \Closure(string): bool  $valid
     * @param \Closure(?string): void $onReady
     */
    private function asset(string $url, int $maxBytes, \Closure $valid, \Closure $onReady): void
    {
        $kept = \array_key_exists($url, $this->memo) ? null : $this->kept($url);
        if (null !== $kept && $valid($kept)) {
            $this->memo[$url] = $kept;
        }
        if (\array_key_exists($url, $this->memo)) {
            $onReady($this->memo[$url]);

            return;
        }
        if (\array_key_exists($url, $this->pending)) {
            $this->pending[$url][] = $onReady;

            return;
        }
        $this->pending[$url] = [$onReady];
        $this->http->get(
            [$url],
            function (array $bodies) use ($url, $valid): void {
                $body = $bodies[$url] ?? null;
                $body = null !== $body && $valid($body) ? $body : null;
                $this->memo[$url] = $body;
                if (null !== $body) {
                    $this->keep($url, $body);
                }
                $waiting = $this->pending[$url];
                unset($this->pending[$url]);
                foreach ($waiting as $onReady) {
                    $onReady($body);
                }
            },
            $maxBytes,
        );
    }

    /** What is kept for $url and still young enough to use, or null. */
    private function kept(string $url): ?string
    {
        $file = $this->file($url);
        if (null === $file || !\is_file($file) || (($this->clock)() - (int) \filemtime($file)) >= self::KEEP_FOR) {
            return null;
        }

        return (string) \file_get_contents($file);
    }

    /** Best-effort: a machine that cannot write here fetches again next session. */
    private function keep(string $url, string $body): void
    {
        $file = $this->file($url);
        if (null === $file) {
            return;
        }
        try {
            $this->filesystem->dumpFile($file, $body);
        } catch (IOExceptionInterface) {
            // Nothing kept is nothing lost but a fetch: it is fetched again next session.
            return;
        }
    }

    private function file(string $url): ?string
    {
        return null === $this->cacheDir ? null : $this->cacheDir.'/'.\sha1($url);
    }
}
