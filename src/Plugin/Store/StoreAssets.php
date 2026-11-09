<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Config\PortaHome;
use Porta\Support\ControlChars;
use Porta\Ui\Graphics\RasterImage;
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
 * A picture is kept as the PNG {@see RasterImage} made of it — a JPEG, a GIF,
 * a WebP or an SVG is converted once, when it arrives.
 *
 * Bounded both ways: a picture over {@see MAX_IMAGE} or that is no picture,
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
        private readonly RasterImage $raster = new RasterImage(),
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
        $this->texts([$url], static function (array $bodies) use ($url, $onReady): void {
            $onReady($bodies[$url] ?? null);
        });
    }

    /**
     * The picture at $url, as a PNG.
     *
     * @param \Closure(?string): void $onReady exactly once, possibly before this returns
     */
    public function image(string $url, \Closure $onReady): void
    {
        $this->images([$url], static function (array $bodies) use ($url, $onReady): void {
            $onReady($bodies[$url] ?? null);
        });
    }

    /**
     * The texts at $urls, in one fetch.
     *
     * @param list<string>                            $urls
     * @param \Closure(array<string, ?string>): void $onReady exactly once, possibly before this returns: each address => its text, or null
     */
    public function texts(array $urls, \Closure $onReady): void
    {
        $this->load(
            $urls,
            self::MAX_TEXT,
            // What an older porta kept is the raw body: kept text is used only when it is already clean.
            static fn (string $kept): bool => \mb_check_encoding($kept, encoding: 'UTF-8') && ControlChars::page($kept) === $kept,
            // Someone else's markdown, drawn in the terminal: no escape sequence of its own reaches it.
            static function (string $body, \Closure $onText): void {
                $onText(\mb_check_encoding($body, encoding: 'UTF-8') ? ControlChars::page($body) : null);
            },
            $onReady,
        );
    }

    /**
     * The pictures at $urls, in one fetch, each made a PNG ({@see RasterImage}).
     *
     * @param list<string>                            $urls
     * @param \Closure(array<string, ?string>): void $onReady exactly once, possibly before this returns: each address => its PNG, or null
     */
    public function images(array $urls, \Closure $onReady): void
    {
        $this->load(
            $urls,
            self::MAX_IMAGE,
            static fn (string $kept): bool => \str_starts_with($kept, self::PNG),
            $this->raster->png(...),
            $onReady,
        );
    }

    /**
     * Each of $urls from this session's memory, from what is kept, or from
     * one fetch of all the rest — an address another ask is already fetching
     * waits for that one.
     *
     * @param list<string>                                     $urls
     * @param \Closure(string): bool                           $usable    whether a kept body may be used as it is
     * @param \Closure(string, \Closure(?string): void): void $transform a fetched body to what is kept and answered, or null
     * @param \Closure(array<string, ?string>): void           $onReady
     */
    private function load(array $urls, int $maxBytes, \Closure $usable, \Closure $transform, \Closure $onReady): void
    {
        $urls = \array_values(\array_unique($urls));
        $answers = [];
        $answer = static function (string $url, ?string $body) use (&$answers, $urls, $onReady): void {
            $answers[$url] = $body;
            // The last address to answer hands every answer on, in the order asked.
            if (\count($answers) === \count($urls)) {
                $onReady(\array_merge(\array_fill_keys($urls, value: null), $answers));
            }
        };
        if ([] === $urls) {
            $onReady([]);

            return;
        }
        $fetch = [];
        foreach ($urls as $url) {
            $kept = \array_key_exists($url, $this->memo) ? null : $this->kept($url);
            if (null !== $kept && $usable($kept)) {
                $this->memo[$url] = $kept;
            }
            if (\array_key_exists($url, $this->memo)) {
                $answer($url, $this->memo[$url]);

                continue;
            }
            if (!\array_key_exists($url, $this->pending)) {
                $fetch[] = $url;
            }
            $this->pending[$url][] = static function (?string $body) use ($url, $answer): void {
                $answer($url, $body);
            };
        }
        if ([] === $fetch) {
            return;
        }
        $this->http->get(
            $fetch,
            function (array $bodies) use ($fetch, $transform): void {
                foreach ($fetch as $url) {
                    $body = $bodies[$url] ?? null;
                    if (null === $body) {
                        $this->settle($url, null);

                        continue;
                    }
                    $transform($body, function (?string $made) use ($url): void {
                        $this->settle($url, $made);
                    });
                }
            },
            $maxBytes,
        );
    }

    /** What $url answered, remembered — kept when it is something — and handed to all who wait for it. */
    private function settle(string $url, ?string $body): void
    {
        $this->memo[$url] = $body;
        if (null !== $body) {
            $this->keep($url, $body);
        }
        $waiting = $this->pending[$url] ?? [];
        unset($this->pending[$url]);
        foreach ($waiting as $onReady) {
            $onReady($body);
        }
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
