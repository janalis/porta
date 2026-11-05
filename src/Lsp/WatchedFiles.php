<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\PollBackoff;
use Porta\Support\GitIgnored;
use Porta\Support\JsonKey;
use Psr\Clock\ClockInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Glob;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Tells each language server which of the files it watches changed on disk —
 * `workspace/didChangeWatchedFiles`, the half of the protocol this client
 * never spoke.
 *
 * A server indexes the project once, at start, and from then on trusts the
 * client for what changed. phpantom registers `**\/*.php`, `composer.json`,
 * `composer.lock` and `.phpantom.toml`, and nothing else ever told it: a
 * method added to a class by anything but this editor — a branch switch,
 * `composer fix`, an agent writing files — stayed "not found" in every file
 * that called it, until the IDE restarted. Measured: the same edit followed
 * by this notification clears the error; without it, it never does.
 *
 * It finds the changes by walking the project and comparing what each sweep
 * saw with the last. A walk is the one portable way to see a file another
 * process wrote, and it is cheap — about 20ms for the two thousand PHP files of
 * Porta itself — but a single stat can block for seconds on a loaded box (see
 * {@see PollBackoff}), so it runs as SLICES on the 4Hz filesystem tick, under
 * a backoff of its own, and a full sweep lands every few seconds. The first
 * sweep only learns the baseline: the server indexed what was there.
 *
 * What the project's `.gitignore` ignores is not walked — caches, coverage,
 * build output: churn a server reindexing on would only be slowed by — and
 * neither is `vendor/`, ten thousand more files that change only when
 * `composer.lock` does, which IS watched: that is the change a server
 * reindexes dependencies on. Nothing is walked while no server has
 * registered a watcher, and a change to what is watched starts a new
 * baseline rather than reporting every newly watched file as created.
 *
 * A file is told apart by its mtime and size, as {@see \Porta\Editor\Buffer}
 * tells an open one: a rewrite within the same second that keeps the size is
 * not seen until the file next changes. That is the price of a stat-only
 * sweep; hashing every watched file on every pass is not one worth paying.
 */
#[AsIdeService]
final class WatchedFiles
{
    /** Files visited per tick — a few milliseconds of stats, warm. */
    private const int SLICE = 512;

    /** Never walked, ignored or not: version control, editor state, dependencies (see the class docblock). */
    private const array SKIP = ['.git', 'node_modules', 'vendor', '.idea', '.vscode', '.porta'];

    /** LSP `WatchKind` bit for each `FileChangeType`: created 1, changed 2, deleted 4. */
    private const array KIND_BIT = [1 => 1, 2 => 2, 3 => 4];

    private readonly PollBackoff $backoff;

    /** @var \Iterator<string, SplFileInfo>|null the sweep in progress */
    private ?\Iterator $walk = null;

    /**
     * Every server's watchers for the sweep in progress, each glob compiled
     * once: matched against the project-relative path, or the absolute one
     * when the glob is absolute.
     *
     * @var array<string, list<array{regex: string, absolute: bool, kind: int}>> server signature => its watchers
     */
    private array $watchers = [];

    /** What the watchers were when the baseline was taken; another set means another baseline. */
    private string $watching = '';

    /**
     * What the sweep in progress has seen — keyed by the relative path, which
     * is an INT key when the path is all digits (a root file named `2024`).
     *
     * @var array<array-key, string> relative path => mtime:size
     */
    private array $seen = [];

    /** @var array<array-key, string>|null what the last complete sweep saw; null until one has */
    private ?array $known = null;

    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly string $rootDir,
        ClockInterface $clock,
    ) {
        // Its own, not the container's shared one: the reconciler's backoff
        // counts the reconciler's overruns.
        $this->backoff = new PollBackoff($clock);
    }

    /** One slice of the sweep; the 4Hz filesystem timer's entry point. */
    public function tick(): void
    {
        if (!$this->backoff->mayRun()) {
            return;
        }
        try {
            $this->slice();
        } finally {
            $this->backoff->finished();
        }
    }

    private function slice(): void
    {
        $walk = $this->walk ?? $this->begin();
        if (null === $walk) {
            return;
        }
        for ($visited = 0; $visited < self::SLICE && $walk->valid(); ++$visited) {
            /** @var SplFileInfo $file valid(), so there is a current one */
            $file = $walk->current();
            $relative = $file->getRelativePathname();
            $stamp = $this->watched($relative) ? self::stamp($file->getPathname()) : null;
            if (null !== $stamp) {
                $this->seen[$relative] = $stamp;
            }
            $walk->next();
        }
        if ($walk->valid()) {
            return;
        }
        $this->walk = null;
        if (null !== $this->known) {
            $this->announce(self::changes($this->known, $this->seen));
        }
        $this->known = $this->seen;
    }

    /**
     * Start a sweep — null, and no baseline kept, while no server watches anything.
     *
     * @return \Iterator<string, SplFileInfo>|null
     */
    private function begin(): ?\Iterator
    {
        $watchers = $this->lsp->fileWatchers();
        $watching = JsonKey::of($watchers);
        if ($watching !== $this->watching) {
            $this->known = null; // what is watched changed: this sweep is the new baseline
            $this->watching = $watching;
        }
        if ([] === $watchers || !\is_dir($this->rootDir)) {
            return null;
        }
        $this->watchers = \array_map(static fn (array $own): array => \array_map(self::compile(...), $own), $watchers);
        $walk = Finder::create()
            ->files()
            ->in($this->rootDir)
            ->exclude(self::SKIP)
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCSIgnored(true)
            ->filter(GitIgnored::pruner($this->rootDir), prune: true)
            ->getIterator();
        $walk->rewind();
        $this->seen = [];

        return $this->walk = $walk;
    }

    /**
     * @param array{glob: string, kind: int} $watcher
     *
     * @return array{regex: string, absolute: bool, kind: int}
     */
    private static function compile(array $watcher): array
    {
        return [
            'regex' => Glob::toRegex($watcher['glob'], strictLeadingDot: false, strictWildcardSlash: true, delimiter: '#'),
            'absolute' => \str_starts_with($watcher['glob'], '/'),
            'kind' => $watcher['kind'],
        ];
    }

    /** Whether any server watches $relative, of any kind. */
    private function watched(string $relative): bool
    {
        return \array_any($this->watchers, fn (array $own): bool => null !== $this->matching($own, $relative, kind: 7));
    }

    /**
     * The first of $watchers that wants $relative for a change of $kind bits.
     *
     * @param list<array{regex: string, absolute: bool, kind: int}> $watchers
     *
     * @return array{regex: string, absolute: bool, kind: int}|null
     */
    private function matching(array $watchers, string $relative, int $kind): ?array
    {
        return \array_find(
            $watchers,
            fn (array $watcher): bool => 0 !== ($watcher['kind'] & $kind) && 1 === \preg_match($watcher['regex'], $watcher['absolute'] ? $this->rootDir.'/'.$relative : $relative),
        );
    }

    /**
     * What a file looks like on disk, cheaply: its mtime and size — or null
     * for one gone between the listing and the stat, which this sweep then
     * did not see, and reports deleted.
     *
     * The cache is cleared for this path first: PHP keeps a stat for the life
     * of the process and drops it only for its own writes, so another
     * process's edit would never be seen (the {@see \Porta\Editor\Buffer::diskStampNow()} lesson).
     */
    private static function stamp(string $path): ?string
    {
        \clearstatcache(clear_realpath_cache: false, filename: $path);

        return \is_file($path) ? (int) \filemtime($path).':'.(int) \filesize($path) : null;
    }

    /**
     * @param array<array-key, string> $before
     * @param array<array-key, string> $after
     *
     * @return list<array{0: string, 1: int}> each changed relative path and its FileChangeType (1 created, 2 changed, 3 deleted), by path
     */
    private static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $path => $stamp) {
            if (!\array_key_exists($path, $before)) {
                $changes[] = [(string) $path, 1]; // a path in digits keys as an int

                continue;
            }
            if ($before[$path] !== $stamp) {
                $changes[] = [(string) $path, 2];
            }
        }
        foreach (\array_keys(\array_diff_key($before, $after)) as $path) {
            $changes[] = [(string) $path, 3];
        }
        // In path order: a directory lists its entries in whatever order the
        // filesystem keeps them, and the same sweep should read the same way twice.
        \usort($changes, static fn (array $a, array $b): int => \strcmp($a[0], $b[0]));

        return $changes;
    }

    /**
     * Each server hears the changes to the files IT watches, of the kinds it
     * asked for — one notification per server per sweep, or none.
     *
     * @param list<array{0: string, 1: int}> $changes
     */
    private function announce(array $changes): void
    {
        foreach ($this->watchers as $signature => $watchers) {
            $own = [];
            foreach ($changes as [$relative, $type]) {
                if (null === $this->matching($watchers, $relative, self::KIND_BIT[$type])) {
                    continue;
                }
                $own[] = ['uri' => Uri::fromPath($this->rootDir.'/'.$relative), 'type' => $type];
            }
            if ([] !== $own) {
                $this->lsp->notifyWatchedFiles($signature, $own);
            }
        }
    }
}
