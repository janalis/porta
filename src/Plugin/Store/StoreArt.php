<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Config\PortaHome;
use Porta\Ui\Graphics\PngDimensions;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Each plugin's {@see Art}: its icon and its pictures, never drawn for it —
 * found where its author put them.
 *
 * What the package lists under `extra.porta.store` comes first: its `icon`
 * and its `screenshots`. What it leaves out is looked for in its README, and
 * when that shows nothing, in the masthead of the README of each tool it
 * drives ({@see StoreEntry::$tools}) — phpstan's logo for the phpstan plugin.
 * Of the first {@see CANDIDATES} pictures found, the first is the icon when it
 * is square; every wide one is a screenshot when the package lists none.
 *
 * Every catalog is looked through in a few fetches: its READMEs at once, then
 * the tools' addresses, their READMEs, and the pictures. What was found is
 * kept a week per version under `~/.porta/cache/store/art`, so the store
 * opens with its pictures.
 */
#[Autoconfigure(constructor: 'create')]
final class StoreArt
{
    /** How many of a README's pictures are looked at. */
    public const int CANDIDATES = 4;

    /** The rules a kept verdict was reached by: raise it when they change. */
    private const int RULES = 2;

    /** The most a tool's Packagist metadata may weigh: phpunit's is 750 kB. */
    private const int MAX_METADATA = 2_097_152;

    /** How long what was found is used before it is looked for again. */
    private const int KEEP_FOR = 604_800;

    /** The README a repository shows, on its default branch. */
    private const string README = 'https://raw.githubusercontent.com/%s/HEAD/README.md';

    /** @var array<string, Art> `package@version` => what it shows */
    private array $memo = [];

    /** @var array<string, true> the `package@version`s being looked for */
    private array $looking = [];

    /** @var array<string, true> the `package@version`s whose art lacks a picture that did not come or could not be drawn */
    private array $unfinished = [];

    /** The converters' count of changes when the unfinished art was last forgotten. */
    private int $converters = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param string|null            $cacheDir where what was found is kept, or null on a machine with no home
     * @param (\Closure(): int)|null $clock    seconds since the epoch; inject to test
     */
    public function __construct(
        private readonly HttpFetch $http,
        private readonly StoreAssets $assets,
        private readonly ?string $cacheDir,
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? \time(...);
    }

    public static function create(HttpFetch $http, StoreAssets $assets): self
    {
        $home = PortaHome::base();

        return new self($http, $assets, null === $home ? null : $home.'/.porta/cache/store/art');
    }

    /**
     * Each entry's art, as it is found. An entry already being looked for is
     * answered once, to the ask that started it.
     *
     * @param list<StoreEntry>               $entries
     * @param \Closure(string, Art): void    $onArt   the package and its art, possibly before this returns
     */
    public function resolve(array $entries, \Closure $onArt): void
    {
        // A converter lent or taken back since — the image viewer switched on — and what lacked a picture is looked for again.
        $converters = $this->assets->converterChanges();
        if ($converters !== $this->converters) {
            $this->converters = $converters;
            $this->memo = \array_diff_key($this->memo, $this->unfinished);
            $this->unfinished = [];
        }
        $find = [];
        foreach ($entries as $entry) {
            $key = self::key($entry);
            if (\array_key_exists($key, $this->memo)) {
                $onArt($entry->package, $this->memo[$key]);

                continue;
            }
            if (\array_key_exists($key, $this->looking)) {
                continue;
            }
            $this->looking[$key] = true;
            $kept = $this->kept($key);
            if (null === $kept) {
                $find[] = $entry;

                continue;
            }
            $this->show($entry, $kept, $onArt);
        }
        if ([] !== $find) {
            $this->find($find, $onArt);
        }
    }

    /**
     * Read the entries' READMEs, and their tools': a plugin's README may show
     * how it looks, and its tool's the logo it is known by.
     *
     * @param list<StoreEntry>            $entries
     * @param \Closure(string, Art): void $onArt
     */
    private function find(array $entries, \Closure $onArt): void
    {
        $readmes = \array_values(\array_filter(\array_map(static fn (StoreEntry $entry): ?string => self::declaredAll($entry) ? null : $entry->readme, $entries)));
        $this->assets->texts($readmes, function (array $texts) use ($entries, $onArt): void {
            $found = [];
            $read = [];
            $tooled = [];
            foreach ($entries as $entry) {
                $readme = self::declaredAll($entry) ? null : $entry->readme;
                $text = null === $readme ? null : $texts[$readme] ?? null;
                $found[$entry->package] = null === $readme || null === $text ? [] : ReadmeImages::in($text, $readme);
                $read[$entry->package] = null === $readme || null !== $text;
                if (!self::declaredAll($entry) && [] !== $entry->tools) {
                    $tooled[] = $entry;
                }
            }
            $this->tools($tooled, function (array $tools, array $toolsRead) use ($entries, $found, $read, $onArt): void {
                $both = [];
                foreach ($toolsRead as $package => $came) {
                    $both[$package] = $came && $read[$package];
                }
                $this->judge($entries, $found, $tools, \array_merge($read, $both), $onArt);
            });
        });
    }

    /**
     * The masthead pictures of the READMEs of $entries' tools, by package,
     * and whether each of those READMEs came back.
     *
     * @param list<StoreEntry>                                                    $entries
     * @param \Closure(array<string, list<string>>, array<string, bool>): void $onFound
     */
    private function tools(array $entries, \Closure $onFound): void
    {
        $metadata = [];
        foreach ($entries as $entry) {
            foreach ($entry->tools as $tool) {
                if (!\str_starts_with($tool, 'composer:')) {
                    continue;
                }
                $metadata[] = \sprintf(PackagistMetadata::RELEASES, \substr($tool, offset: 9));
            }
        }
        $this->http->get(
            \array_values(\array_unique($metadata)),
            function (array $bodies) use ($entries, $onFound): void {
                $readmes = [];
                foreach ($entries as $entry) {
                    $readmes[$entry->package] = \array_map(
                        static function (string $tool) use ($bodies): string {
                            [$kind, $name] = \explode(':', $tool, limit: 2);
                            $json = 'composer' === $kind ? $bodies[\sprintf(PackagistMetadata::RELEASES, $name)] ?? null : null;
                            // A package that names no GitHub source is most often published from the repository it is named after.
                            $repository = null === $json ? $name : PackagistMetadata::repository($name, $json) ?? $name;

                            return \sprintf(self::README, $repository);
                        },
                        $entry->tools,
                    );
                }
                $this->assets->texts(\array_values(\array_unique(\array_merge([], ...\array_values($readmes)))), static function (array $texts) use ($readmes, $onFound): void {
                    $found = [];
                    $read = [];
                    foreach ($readmes as $package => $urls) {
                        $images = [];
                        $read[$package] = true;
                        foreach ($urls as $url) {
                            $text = $texts[$url] ?? null;
                            $read[$package] = $read[$package] && null !== $text;
                            \array_push($images, ...null === $text ? [] : ReadmeImages::masthead($text, $url));
                        }
                        $found[$package] = $images;
                    }
                    $onFound($found, $read);
                });
            },
            self::MAX_METADATA,
        );
    }

    /**
     * Fetch each entry's first pictures, and say which is its icon and which are its screenshots.
     *
     * @param list<StoreEntry>            $entries
     * @param array<string, list<string>> $found    package => the pictures its own README shows
     * @param array<string, list<string>> $borrowed package => the pictures atop its tools' READMEs
     * @param array<string, bool>         $read     package => whether every README asked for came back: only then, and when every picture was drawn, is the verdict kept
     * @param \Closure(string, Art): void $onArt
     */
    private function judge(array $entries, array $found, array $borrowed, array $read, \Closure $onArt): void
    {
        $candidates = [];
        $firsts = [];
        foreach ($entries as $entry) {
            $own = \array_slice($found[$entry->package] ?? [], offset: 0, length: self::CANDIDATES);
            $tools = \array_slice(\array_values(\array_diff($borrowed[$entry->package] ?? [], $own)), offset: 0, length: self::CANDIDATES - \count($own));
            $candidates[$entry->package] = [...$own, ...$tools];
            // Each README's first picture may be the icon: the plugin's own, else its tool's logo.
            $firsts[$entry->package] = [] === $tools ? [0] : [0, \count($own)];
        }
        $this->assets->images(\array_values(\array_unique(\array_merge([], ...\array_values($candidates)))), function (array $pngs) use ($entries, $candidates, $firsts, $read, $onArt): void {
            foreach ($entries as $entry) {
                $verdict = self::verdict($entry, $candidates[$entry->package], $firsts[$entry->package], $pngs);
                // A README or a picture that did not come back may be the network, not the plugin, and a
                // picture this machine could not draw may be drawn once a converter is there — the image
                // viewer switched on, librsvg installed: either is looked for again next session.
                $drawn = \array_all($candidates[$entry->package], static fn (string $url): bool => null !== ($pngs[$url] ?? null));
                if ($drawn && ($read[$entry->package] ?? false)) {
                    $this->keep(self::key($entry), $verdict);
                }
                if (!$drawn) {
                    $this->unfinished[self::key($entry)] = true;
                }
                $this->show($entry, $verdict, $onArt);
            }
        });
    }

    /**
     * Which picture is the icon and which are the screenshots: the package's
     * own when it lists them; else the first square one of those first in a
     * README, and every wide one.
     *
     * @param list<string>               $candidates
     * @param list<int>                  $firsts     the candidates first in their README: those that may be the icon
     * @param array<string, string|null> $pngs
     *
     * @return array{icon: ?string, shots: list<string>}
     */
    private static function verdict(StoreEntry $entry, array $candidates, array $firsts, array $pngs): array
    {
        $icon = $entry->icon;
        $shots = $entry->screenshots;
        foreach ($candidates as $index => $url) {
            $size = PngDimensions::read($pngs[$url] ?? '');
            if (null === $size) {
                continue;
            }
            if (null === $icon && \in_array($index, $firsts, strict: true) && self::square($size)) {
                $icon = $url;
            }
            if ([] === $entry->screenshots && self::wide($size)) {
                $shots[] = $url;
            }
        }

        return ['icon' => $icon, 'shots' => $shots];
    }

    /** @param array{int, int} $size */
    private static function square(array $size): bool
    {
        [$width, $height] = $size;

        return \min($width, $height) >= 32 && ($width * 4) >= ($height * 3) && ($height * 4) >= ($width * 3);
    }

    /** @param array{int, int} $size */
    private static function wide(array $size): bool
    {
        [$width, $height] = $size;

        return $width >= 200 && ($width * 10) >= ($height * 13) && $width <= ($height * 5);
    }

    /**
     * Fetch the icon and the first screenshot, and hand the entry its art.
     *
     * @param array{icon: ?string, shots: list<string>} $verdict
     * @param \Closure(string, Art): void               $onArt
     */
    private function show(StoreEntry $entry, array $verdict, \Closure $onArt): void
    {
        $icon = $verdict['icon'];
        $hero = $verdict['shots'][0] ?? null;
        $this->assets->images(\array_values(\array_filter([$icon, $hero])), function (array $pngs) use ($entry, $verdict, $icon, $hero, $onArt): void {
            $art = new Art(null === $icon ? null : $pngs[$icon] ?? null, null === $hero ? null : $pngs[$hero] ?? null, $verdict['shots']);
            $key = self::key($entry);
            $this->memo[$key] = $art;
            if ((null !== $icon && null === $art->icon) || (null !== $hero && null === $art->hero)) {
                $this->unfinished[$key] = true;
            }
            unset($this->looking[$key]);
            $onArt($entry->package, $art);
        });
    }

    /** Whether the package lists both an icon and its screenshots: no README is read for it. */
    private static function declaredAll(StoreEntry $entry): bool
    {
        return null !== $entry->icon && [] !== $entry->screenshots;
    }

    /**
     * Where a verdict is kept: by package and version, and by the rules that
     * made it — {@see RULES} moves when they change, so a verdict an older
     * Porta reached is never read as this one's.
     */
    private static function key(StoreEntry $entry): string
    {
        return $entry->package.'@'.$entry->version.'#'.self::RULES;
    }

    /** @return array{icon: ?string, shots: list<string>}|null what was found for $key, while it is young enough */
    private function kept(string $key): ?array
    {
        $file = $this->file($key);
        $young = null !== $file && \is_file($file) && (($this->clock)() - (int) \filemtime($file)) < self::KEEP_FOR;
        // @noinspection JsonEncodingApiUsageInspection -- a kept file that is not JSON is looked for again, not an exception
        /** @var mixed $data */
        $data = $young ? \json_decode((string) \file_get_contents($file), associative: true) : null;

        return (
            \is_array($data) && \array_key_exists('icon', $data)
                ? ['icon' => \is_string($data['icon']) ? $data['icon'] : null, 'shots' => PackagistMetadata::strings($data['shots'] ?? null)]
                : null
        );
    }

    /**
     * Best-effort: a machine that cannot write here looks again next session.
     *
     * @param array{icon: ?string, shots: list<string>} $verdict
     */
    private function keep(string $key, array $verdict): void
    {
        $file = $this->file($key);
        if (null === $file) {
            return;
        }
        try {
            $this->filesystem->dumpFile($file, \json_encode($verdict, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        } catch (IOExceptionInterface) {
            // Nothing kept is nothing lost but a look: it is looked for again next session.
            return;
        }
    }

    private function file(string $key): ?string
    {
        return null === $this->cacheDir ? null : $this->cacheDir.'/'.\sha1($key).'.json';
    }
}
