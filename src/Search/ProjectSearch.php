<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\DiskText;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Support\AsyncProcess;
use Porta\Support\ComposerLayout;
use Porta\Support\Files;
use Porta\Support\PathExecutables;
use Revolt\EventLoop;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Asynchronous project-wide text search.
 *
 * Prefers `grep -rn` streamed through an {@see AsyncProcess} on the event
 * loop; falls back to a PHP walk when grep is unavailable, run in slices
 * between frames so a large project never freezes the UI.
 * Results are capped to keep the panel responsive.
 */
final class ProjectSearch
{
    public const int MAX_RESULTS = 500;

    /** Folders, by name, no project search walks — Go to File's index reads it too. */
    public const array EXCLUDES = ['.git', 'node_modules', 'vendor', '.idea', '.vscode'];

    /**
     * Where local history keeps a plain copy of each saved file: searched, each
     * hit came back once per snapshot, and Replace All rewrote them. A PATH, not
     * a name — the rest of `.porta/` is the user's too: a project's own plugins
     * live in `.porta/plugins`.
     */
    public const string HISTORY = '.porta/history';

    /**
     * Whether $relPath is one of local history's snapshots: searched when
     * the scope names it, never rewritten — they are what an old version is
     * restored from, and must stay as it was saved.
     */
    public static function inHistory(string $relPath): bool
    {
        return \str_starts_with($relPath, self::HISTORY.'/');
    }

    /** The largest file the PHP walk reads: 1 MiB. */
    private const int MAX_FILE_BYTES = 1_048_576;

    /** How long one slice of the PHP walk may hold the loop: half a frame. */
    private const int SLICE_NS = 8_000_000;

    private ?AsyncProcess $process = null;

    /** The PHP walk's next slice, deferred on the loop. */
    private ?string $slice = null;

    /** The resolved `grep` binary; see {@see findGrep()} — resolved once, not per search. */
    private ?string $grep = null;
    private bool $grepResolved = false;

    /** Whether that grep takes `-P`; see {@see readsPcre()} — asked once, on the first regex search. */
    private ?bool $pcre = null;

    /**
     * Whether the regex engine GAVE UP somewhere in the last search.
     *
     * PCRE reports "I stopped trying" the same way it reports "nothing here":
     * `preg_match_all()` answers false. Three paths below swallow that, and a
     * project-wide search silently returning fewer results is worse than the
     * find bar doing it — a handful of skipped files among thousands is
     * invisible. The controller reads this and says so.
     */
    private bool $gaveUp = false;

    public function __construct(
        private readonly string $rootDir,
        private readonly ?RemoteFilesystem $remote = null,
    ) {}

    /**
     * Whether the last search of a remote project read only the files opened
     * here: its grep runs on the server, and when that could not answer — the
     * link down, no grep there — or the query spans lines, which grep cannot
     * take, the built-in engine searched the local copy, which holds only what
     * was opened. Said, so a short list is not read as the whole answer.
     */
    private bool $localOnly = false;

    /**
     * The vendor dir a server's composer.json moved, whose hits its grep
     * drops, and the scope paths at or in it, whose hits are kept: what is
     * named is searched. Null where nothing was moved.
     *
     * @var array{string, list<string>}|null
     */
    private ?array $dropping = null;

    public function searchedLocalCopyOnly(): bool
    {
        return $this->localOnly;
    }

    /** Whether the last search hit a pattern the engine could not finish. */
    public function gaveUp(): bool
    {
        return $this->gaveUp;
    }

    /**
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void $onDone
     * @param string|list<string>                                                                                               $scope grep path(s) relative to the project root; `.` searches the whole project, a list searches each path (a directory or a single file), an empty list matches nothing
     */
    public function search(string $query, callable $onDone, SearchOptions $options = new SearchOptions(), string|array $scope = '.', string $mask = ''): void
    {
        $this->cancel();
        $this->gaveUp = false;
        $this->localOnly = false;
        $paths = \is_array($scope) ? \array_values($scope) : [$scope];
        if ([] === $paths || '' === \trim($query)) {
            $onDone([]);

            return;
        }
        $pattern = $this->pattern($query, $options);
        match (true) {
            // A malformed regex yields no results rather than a crash.
            $options->regex && !SearchPattern::compiles($pattern) => $onDone([]),
            // A query spanning lines can't go through line-based grep: scan whole files.
            \str_contains($query, "\n") => $this->builtIn($this->searchMultiline($pattern, $paths, $mask), $onDone),
            null === ($grep = $this->findGrep()) => $this->builtIn($this->searchWithPhp($pattern, $paths, $mask), $onDone),
            default => $this->grep($grep, $query, $pattern, $onDone, $options, $paths, $mask),
        };
    }

    /**
     * The search, run by $grep in the background; the built-in engine takes
     * over when that grep cannot answer.
     *
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void $onDone
     * @param list<string>                                                                                                      $paths
     */
    private function grep(string $grep, string $query, string $pattern, callable $onDone, SearchOptions $options, array $paths, string $mask): void
    {
        // -H: GNU grep drops the `path:` prefix when it is handed a single file
        // (a file scope), and the parser reads `path:line:text`; BSD grep and
        // ugrep print it anyway, which is why only Linux lost those matches.
        $flags = ['-rnH', '--binary-files=without-match'];
        if (!$options->caseSensitive) {
            $flags[] = '-i';
        }
        if ($options->wholeWord) {
            $flags[] = '-w';
        }
        // A file mask restricts matching to basenames (grep's fnmatch globbing
        // does not expand braces, so `*.{php,js}` is pre-expanded to two globs).
        foreach ($this->maskGlobs($mask) as $glob) {
            $flags[] = '--include='.$glob;
        }
        foreach (self::EXCLUDES as $dir) {
            $flags[] = '--exclude-dir='.$dir;
        }
        // grep's --exclude-dir matches a folder's NAME, so the history folder and
        // a moved vendor dir are left out by handing grep their siblings instead.
        // A remote project's grep runs on the server, where no local history is
        // kept, and whose composer.json the cache may not hold.
        $grepPaths = $this->operands($paths);
        $vendor = $this->remote?->vendorPath();
        $this->dropping = null === $vendor
            ? null
            : [
                $vendor,
                \array_values(\array_filter(
                    \array_map(static fn (string $path): string => \rtrim($path, characters: '/'), $paths),
                    static fn (string $path): bool => self::within($path, [$vendor]),
                )),
            ];
        // Counted as they come, chunk by chunk: what is dropped, and a hit
        // printed again for a second scope path that reaches it, does not count.
        $kept = 0;
        $counted = [];
        $partial = '';
        if ([] === $grepPaths) {
            $onDone([]); // nothing but the history there: grep with no path would read stdin

            return;
        }
        // The scope paths are arguments, not the cwd: grep still runs in $rootDir
        // (AsyncProcess below), so a `subdir/file:line` match stays root-relative.
        $args = static fn (string $regex): array => [...$flags, $options->regex ? $regex : '-F', '--', $query, ...$grepPaths];
        // A remote project's cache holds only what was opened: its grep runs
        // on the server, where every file is — named `grep` there, since the
        // path found here is this machine's, and asked for ERE, since whether
        // that grep reads PCRE was never asked.
        $command = $this->remote?->remoteCommand(['grep', ...$args('-E')]) ?? [$grep, ...$args($options->regex && $this->readsPcre($grep) ? '-P' : '-E')];

        // A grep that cannot answer hands the search to the built-in engine.
        // Linux's posix_spawn refuses an unrunnable binary outright; macOS
        // spawns it and reports an exit code — one fallback serves both.
        $fallback = fn () => $this->builtIn($this->searchWithPhp($pattern, $paths, $mask), $onDone);
        // UTF-8, as the pattern that highlights and replaces reads it: under -i
        // grep folds É to é as that pattern does, whatever locale Porta runs in.
        $process = new AsyncProcess($command, $this->rootDir, env: [...\getenv(), 'LC_ALL' => 'C.UTF-8']);
        $started = $process->start(
            // grep's own failure (2, or 126/127 for a binary that cannot run)
            // with nothing printed is no answer; 1 is grep saying "no match",
            // which is one.
            onExit: fn (int $code, string $out) => $code >= 2 && '' === $out ? $fallback() : $this->finish($out, $onDone, $pattern),
            onChunk: function (string $chunk) use ($process, $onDone, $pattern, &$kept, &$counted, &$partial): void {
                // A server's hits under the vendor dir it moved are dropped by
                // parse(): counted, they stopped a search before its real hits.
                $lines = \explode("\n", $partial.$chunk);
                $partial = \array_pop($lines);
                $kept += $this->keptOf($lines, $counted);
                if ($kept > self::MAX_RESULTS) {
                    // Enough results; stop early
                    $this->finish($process->output(), $onDone, $pattern);
                }
            },
        );
        if (!$started) {
            // Reached on Linux only (the spawn refusal above), where
            // testABrokenGrepBinaryFallsBackToTheBuiltInSearch drives it; a
            // macOS run never can, so the line gate is told to look away.
            // @codeCoverageIgnoreStart
            $fallback();

            return;

            // @codeCoverageIgnoreEnd
        }
        $this->process = $process;
    }

    public function cancel(): void
    {
        $this->process?->cancel();
        $this->process = null;
        if (null !== $this->slice) {
            EventLoop::cancel($this->slice);
            $this->slice = null;
        }
    }

    /**
     * Whether this project's commands run on a server. Not whether there is a
     * remote filesystem: the container always builds one, and for a local
     * project it has no transport — a null check answered "remote" for every
     * project there is.
     */
    private function onServer(): bool
    {
        return $this->remote?->isRemote() ?? false;
    }

    /**
     * The built-in engine, over the files here: for a remote project only the
     * ones opened, which {@see searchedLocalCopyOnly()} says.
     *
     * @param \Generator<int, null, mixed, list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>> $scan
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void                 $onDone
     */
    private function builtIn(\Generator $scan, callable $onDone): void
    {
        $this->localOnly = $this->onServer();
        $this->sliced($scan, static fn (array $results) => $onDone(self::sorted($results)));
    }

    /**
     * Run $scan — which yields after each file — a slice at a time: the first
     * now, so a small project answers at once, the rest deferred while the
     * loop draws and reads keys in between.
     *
     * @param \Generator<int, null, mixed, list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>> $scan
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void                 $onDone
     */
    private function sliced(\Generator $scan, callable $onDone): void
    {
        $until = \hrtime(true) + self::SLICE_NS;
        while ($scan->valid() && \hrtime(true) < $until) {
            $scan->next();
        }
        if ($scan->valid()) {
            $this->slice = EventLoop::defer(fn () => $this->sliced($scan, $onDone));

            return;
        }
        $this->slice = null;
        $onDone($scan->getReturn());
    }

    /** @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void $onDone */
    private function finish(string $output, callable $onDone, string $pattern): void
    {
        $results = $this->parse($output, $pattern);
        $this->cancel();
        $onDone($results);
    }

    /**
     * One line of grep's output read: the path as the project names it —
     * `vendor/./a.php`, from a scope handed over as `vendor/.`, is
     * `vendor/a.php` — or null for a line that is no hit.
     *
     * @return array{path: ?string, line: int, text: string}
     */
    private function hit(string $line): array
    {
        $m = [];
        if ('' === $line || !\preg_match('/^(?:\.\/)?(.+?):(\d+):(.*)$/', $line, $m)) {
            return ['path' => null, 'line' => 0, 'text' => ''];
        }
        [$path, $number, $text] = [$m[1], $m[2], $m[3]];
        // grep prints `path:line:text`, and a name may hold `:12:` itself:
        // `a:12:b.txt:3:x` read at its first such pair names `a`, which is no
        // file. Then the first later reading that names one is the hit.
        if (!$this->isFile($path)) {
            [$path, $number, $text] = $this->laterReading($path, $number, $text);
        }

        return ['path' => \str_replace('/./', replace: '/', subject: $path), 'line' => (int) $number - 1, 'text' => \trim($text)];
    }

    /**
     * The first reading of `$path:$number:$text` past its first `:N:` whose
     * path is a file here — as it was when none is.
     *
     * @return array{string, string, string}
     */
    private function laterReading(string $path, string $number, string $text): array
    {
        // [text, N, text, N, text…]: each N a place the name may go on past.
        $parts = \preg_split('/:(\d+):/', $text, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $parts = false === $parts ? [$text] : $parts;
        $head = $path.':'.$number.':';
        $name = $head;
        $pairs = \count($parts) - 2;
        for ($k = 0; $k < $pairs; $k += 2) {
            $name .= $parts[$k];
            $pair = ':'.$parts[$k + 1].':';
            if ($this->isFile($name)) {
                // The text is what follows that pair, as grep printed it.
                return [$name, $parts[$k + 1], \substr($text, \strlen($name) - \strlen($head) + \strlen($pair))];
            }
            $name .= $pair;
        }

        return [$path, $number, $text];
    }

    /** Whether $path, as grep printed it, is a file of the project here — a remote one's placeholder too. */
    private function isFile(string $path): bool
    {
        return \is_file($this->rootDir.'/'.\str_replace('/./', replace: '/', subject: $path));
    }

    /**
     * How many of grep's $lines {@see parse()} keeps, not counting again a
     * hit already in $counted.
     *
     * @param list<string>               $lines
     * @param array<string, bool> $counted the hits counted so far, by `path:line`
     */
    private function keptOf(array $lines, array &$counted): int
    {
        $kept = 0;
        foreach ($lines as $line) {
            $hit = $this->hit($line);
            if (null === $hit['path'] || $this->dropped($hit['path']) || \array_key_exists($hit['path'].':'.$hit['line'], $counted)) {
                continue;
            }
            $counted[$hit['path'].':'.$hit['line']] = true;
            ++$kept;
        }

        return $kept;
    }

    /** Whether a server grep's hit at $path is dropped: in the vendor dir it moved, and in no scope path that names it. */
    private function dropped(string $path): bool
    {
        return null !== $this->dropping && self::within($path, [$this->dropping[0]]) && !self::within($path, $this->dropping[1]);
    }

    /**
     * Whether $path is one of $dirs or lies under one.
     *
     * @param list<string> $dirs
     */
    private static function within(string $path, array $dirs): bool
    {
        return \array_any($dirs, static fn (string $dir): bool => $path === $dir || \str_starts_with($path, $dir.'/'));
    }

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function parse(string $output, string $pattern): array
    {
        $results = [];
        // A grep run on the server cannot be handed the way past a moved
        // vendor dir, as one here is: its hits there are dropped instead.
        // grep prints a file once per scope path that reaches it — `lib` and
        // `lib/a` both reach `lib/a` — where the built-in engine reads it
        // once: each hit is listed once, by either.
        $seen = [];
        foreach (\explode("\n", $output) as $line) {
            $hit = $this->hit($line);
            if (null === $hit['path'] || $this->dropped($hit['path']) || \array_key_exists($hit['path'].':'.$hit['line'], $seen)) {
                continue;
            }
            $seen[$hit['path'].':'.$hit['line']] = true;
            $results[] = ['path' => $hit['path'], 'line' => $hit['line'], 'text' => $hit['text'], 'ranges' => $this->rangesFor($hit['text'], $pattern)];
            if (\count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return self::sorted($results);
    }

    /**
     * $results by path, then line — the panel's order, whichever engine
     * found them. Each walks in an order of its own: grep -r the file
     * system's (on ext4 a hash order, File7.php before File0.php), the
     * built-in engine Finder's. Past {@see MAX_RESULTS} each kept the first
     * it reached, so the two may keep different hits: the order is shared,
     * the cut is not.
     *
     * @param list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> $results
     *
     * @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>
     */
    private static function sorted(array $results): array
    {
        \usort($results, static fn (array $a, array $b): int => 0 !== \strcmp($a['path'], $b['path']) ? \strcmp($a['path'], $b['path']) : $a['line'] <=> $b['line']);

        return $results;
    }

    /**
     * Locate every match of $pattern within a result line, as display-column
     * spans (so the panel can highlight them). Byte offsets from
     * `PREG_OFFSET_CAPTURE` are converted to display columns by the vendor's
     * `visibleWidth()`, the measure the panel slices the line by.
     *
     * @return list<array{start: int, length: int}>
     */
    private function rangesFor(string $text, string $pattern): array
    {
        return \array_map(
            static fn (array $hit): array => ['start' => AnsiUtils::visibleWidth(\substr($text, offset: 0, length: $hit[1])), 'length' => AnsiUtils::visibleWidth($hit[0])],
            $this->hits($text, $pattern),
        );
    }

    /**
     * Every match of $pattern in $text with its byte offset, zero-width ones
     * left out (they highlight and replace nothing); none when the pattern
     * fails on it, which gives the search up.
     *
     * @return list<array{string, int}>
     */
    private function hits(string $text, string $pattern): array
    {
        $matches = [];
        if ('' === $text) {
            return [];
        }
        \set_error_handler(static fn (): bool => true);
        $count = \preg_match_all(SearchPattern::fit($pattern, $text), $text, $matches, \PREG_OFFSET_CAPTURE);
        \restore_error_handler();
        if (false === $count) {
            $this->gaveUp = true;
        }
        $hits = [];
        foreach (false === $count ? [] : $matches[0] as $hit) {
            if ('' === $hit[0]) {
                continue;
            }
            $hits[] = [$hit[0], (int) $hit[1]];
        }

        return $hits;
    }

    /**
     * The PHP walk, a line at a time; yields after each file.
     *
     * @param list<string> $paths scope paths (each a directory, a single file, or `.`)
     *
     * @return \Generator<int, null, mixed, list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>>
     */
    private function searchWithPhp(string $pattern, array $paths, string $mask): \Generator
    {
        $results = [];
        foreach ($this->texts($paths, $mask) as $rel => $content) {
            // Not explode('') for a file passed over: its one empty line is a match for `^`.
            foreach (null === $content ? [] : \explode("\n", $content) as $lineIndex => $line) {
                if (1 !== \preg_match(SearchPattern::fit($pattern, $line), $line)) {
                    continue;
                }

                $text = \trim($line);
                $results[] = ['path' => $rel, 'line' => $lineIndex, 'text' => $text, 'ranges' => $this->rangesFor($text, $pattern)];
                if (\count($results) >= self::MAX_RESULTS) {
                    return $results;
                }
            }
            yield;
        }

        return $results;
    }

    /**
     * Whole-file scan for a query that spans lines (grep is line-based); yields
     * after each file. Each match reports its 0-based start line and a
     * single-line preview (its first line, with `⏎…` appended when the match
     * continues onto further lines).
     *
     * @param list<string> $paths
     *
     * @return \Generator<int, null, mixed, list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>>
     */
    private function searchMultiline(string $pattern, array $paths, string $mask): \Generator
    {
        $results = [];
        foreach ($this->texts($paths, $mask) as $rel => $content) {
            foreach (null === $content ? [] : $this->hits($content, $pattern) as $hit) {
                $results[] = $this->multilineResult($rel, (string) $content, $hit[0], $hit[1]);
                if (\count($results) >= self::MAX_RESULTS) {
                    return $results;
                }
            }
            yield;
        }

        return $results;
    }

    /**
     * The `[rootRelativePath => content]` of each text file the scopes cover,
     * once each: overlapping scopes (open files under a directory) match once,
     * and a file with a NUL byte is binary, never searched. A file passed over
     * comes as null, so the scan still pauses on it.
     *
     * @param list<string> $paths
     *
     * @return \Generator<string, ?string>
     */
    private function texts(array $paths, string $mask): \Generator
    {
        $globs = $this->maskGlobs($mask);
        $seen = [];
        foreach ($paths as $scope) {
            foreach ($this->scopeFiles($scope, $globs) as $rel => $abs) {
                $content = null === $abs || null !== ($seen[$rel] ?? null) ? null : $this->readFile($abs);
                // Only a file read counts as seen: one this scope passed over
                // may be named by the next — searched there, as grep searches it.
                if (null !== $abs) {
                    $seen[$rel] = true;
                }
                yield $rel => null === $content || \str_contains($content, "\0") ? null : $content;
            }
        }
    }

    /**
     * Build one multiline result: its start line (0-based) and a first-line
     * preview with the matched portion highlighted.
     *
     * @return array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}
     */
    private function multilineResult(string $rel, string $content, string $match, int $offset): array
    {
        $line = \substr_count(\substr($content, offset: 0, length: $offset), needle: "\n");
        $firstLine = \rtrim(\explode("\n", $match)[0], characters: "\r"); // a CRLF line's ending is not text
        $preview = \str_contains($match, "\n") ? $firstLine.' ⏎…' : $firstLine;
        $width = AnsiUtils::visibleWidth($firstLine);
        $ranges = 0 === $width ? [] : [['start' => 0, 'length' => $width]];

        return ['path' => $rel, 'line' => $line, 'text' => $preview, 'ranges' => $ranges];
    }

    /**
     * The `[rootRelativePath => absolutePath]` files a scope entry covers: a
     * single file yields itself; a directory (or `.`) is walked with Finder,
     * whose relative paths are prefixed back on so every result stays
     * root-relative.
     *
     * @param list<string> $globs basename globs, mirroring grep --include
     *
     * @return iterable<string, ?string> null for a file passed over, still yielded so a walk can pause on it
     */
    private function scopeFiles(string $scope, array $globs): iterable
    {
        $abs = '.' === $scope ? $this->rootDir : $this->rootDir.'/'.$scope;
        if (\is_file($abs)) {
            // The mask narrows an explicitly named file too, because grep does:
            // `--include` is a filter on every candidate, not just on the ones
            // the walk turned up, and both greps this runs on (BSD and ugrep)
            // answer "no match" for `grep --include='*.php' needle a.txt`. grep
            // is the primary engine, so this branch is what a handful of users
            // without it on PATH see — aligning it toward what everyone else
            // already experiences is the change that surprises nobody.
            if ([] === $globs || self::matchesAnyGlob(\basename($abs), $globs)) {
                yield $scope => $abs;
            }

            return;
        }
        $prefix = '.' === $scope ? '' : \rtrim($scope, characters: '/').'/';
        // A skipped path below the scope, relative to it; one that IS the scope
        // was asked for. `/` anchors it there: Finder matches a bare path at any depth.
        $below = \array_map(
            static fn (string $path): string => '/'.\substr($path, \strlen($prefix)),
            \array_values(\array_filter($this->skippedPaths(), static fn (string $path): bool => \str_starts_with($path.'/', $prefix) && $path.'/' !== $prefix)),
        );
        $finder = new Finder()
            ->files()
            ->in($abs)
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs() // passed over, as grep passes it
            ->ignoreVCS(false)
            ->exclude([...self::EXCLUDES, ...$below]);
        // The mask (an OR of basename globs, as grep's --include) and the size
        // cap are judged here, not by Finder: a file Finder turns away never
        // reaches the walk, so a tree of them — 47,000 coverage pages under a
        // `*.php` mask — passed in one step, past every slice's deadline.
        foreach ($finder as $file) {
            $kept = ([] === $globs || self::matchesAnyGlob($file->getFilename(), $globs)) && (int) $file->getSize() <= self::MAX_FILE_BYTES;
            yield $prefix.$file->getRelativePathname() => $kept ? $file->getPathname() : null;
        }
    }

    /**
     * Whether a basename satisfies at least one of the mask's globs — the OR
     * Finder's `->name()` applies to the walk, applied by hand to the one file
     * a file scope names.
     *
     * @param list<string> $globs
     */
    private static function matchesAnyGlob(string $name, array $globs): bool
    {
        return \array_any($globs, static fn (string $glob): bool => \fnmatch($glob, $name));
    }

    /**
     * Expand a user file mask into a flat list of basename globs. Handles a
     * comma-separated list and a single brace group per token, so `*.php`,
     * `*.{php,js}` and `*.php,*.twig` all yield plain `fnmatch` globs that both
     * grep `--include` and Finder `->name()` accept.
     *
     * @return list<string>
     */
    private function maskGlobs(string $mask): array
    {
        $globs = [];
        foreach ($this->splitTopLevel($mask) as $token) {
            $token = \trim($token);
            if ('' === $token) {
                continue;
            }
            $m = [];
            if (1 !== \preg_match('/^(.*)\{([^{}]*)\}(.*)$/', $token, $m)) {
                $globs[] = $token;

                continue;
            }
            foreach (\explode(',', $m[2]) as $option) {
                $globs[] = $m[1].\trim($option).$m[3];
            }
        }

        return $globs;
    }

    /**
     * Split a mask on its top-level commas only — a comma inside a `{…}` brace
     * group belongs to that group (`*.php, *.{js,ts}` → `*.php`, `*.{js,ts}`).
     *
     * @return list<string>
     */
    private function splitTopLevel(string $mask): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        foreach (\str_split($mask) as $ch) {
            if ('{' === $ch) {
                ++$depth;
            }
            if ('}' === $ch) {
                $depth = \max(0, $depth - 1);
            }
            if (',' === $ch && 0 === $depth) {
                $tokens[] = $current;
                $current = '';

                continue;
            }
            $current .= $ch;
        }
        $tokens[] = $current;

        return $tokens;
    }

    /**
     * Apply the search→replace transformation to a file's content using the
     * same matching semantics as {@see search()}. In non-regex mode the
     * replacement is literal (backslashes and `$` included); in regex mode
     * it may use backreferences. Returns the content unchanged on a bad regex.
     *
     * Line breaks in $replacement go in as $eol, the file's own — a CRLF file
     * given `\n` came back with its endings mixed. Left out, it is $content's:
     * right for a whole file, wrong for one line of it, whose CRLF has been
     * split off down to a trailing CR.
     */
    public function applyReplacement(string $content, string $query, string $replacement, SearchOptions $options, ?string $eol = null): string
    {
        $replacement = DiskText::inEnding($replacement, $eol ?? DiskText::ending($content));

        return $this->rewrite($content, $query, $replacement, $options, starts: null);
    }

    /**
     * $line with only the matches starting at $spans rewritten, each as
     * {@see applyReplacement()} rewrites it: a regex's `$1` expanded from
     * THAT match's groups, preserve-case recast to that match's case. Every
     * other match is left as it stands — the token scope approved only these.
     *
     * The match is found again rather than spliced at the span: its groups
     * exist only in a match, and lookarounds and anchors only in the line.
     *
     * @param list<array{start: int, length: int}> $spans
     */
    public function replaceSpans(string $line, array $spans, string $query, string $replacement, SearchOptions $options, string $eol): string
    {
        return $this->rewrite($line, $query, DiskText::inEnding($replacement, $eol), $options, \array_column($spans, column_key: 'start'));
    }

    /**
     * The one rewrite both replaces make, so they cannot drift apart: every
     * match of $query in $subject — or, given $starts, only those starting
     * there — becomes $replacement, as {@see matchReplacement()} spells it.
     * $subject unchanged on a bad regex.
     *
     * @param ?list<int> $starts
     */
    private function rewrite(string $subject, string $query, string $replacement, SearchOptions $options, ?array $starts): string
    {
        \set_error_handler(static fn (): bool => true);
        try {
            $result = \preg_replace_callback(
                SearchPattern::fit($this->pattern($query, $options), $subject),
                fn (array $match): string => $this->matchReplacement($match, $starts, $replacement, $options),
                $subject,
                flags: \PREG_OFFSET_CAPTURE,
            );
        } finally {
            \restore_error_handler();
        }

        return $result ?? $subject;
    }

    /**
     * One match's rewrite: itself when $starts is given and it starts at none
     * of them; else a regex's backreferences expanded from its own groups, a
     * literal recast to its case under preserve-case, or the replacement as
     * typed — literal, `$` and `\` included.
     *
     * @param array<array-key, mixed> $match  as PREG_OFFSET_CAPTURE hands it: each group a [text, offset] pair
     * @param ?list<int>              $starts
     */
    private function matchReplacement(array $match, ?array $starts, string $replacement, SearchOptions $options): string
    {
        $groups = \array_map(static fn (mixed $group): string => \is_array($group) && \is_string($group[0] ?? null) ? $group[0] : '', $match);
        $whole = \is_array($match[0] ?? null) ? $match[0] : [];

        return match (true) {
            null !== $starts && !\in_array($whole[1] ?? null, $starts, strict: true) => $groups[0] ?? '',
            $options->regex => Backreferences::expand($replacement, $groups),
            $options->preserveCase => $this->applyCasePattern($groups[0] ?? '', $replacement),
            default => $replacement,
        };
    }

    /**
     * Recast $replacement to match $match's case pattern: all-lowercase → lower,
     * ALL-UPPERCASE → upper, Titlecase → Title, anything else → verbatim.
     */
    private function applyCasePattern(string $match, string $replacement): string
    {
        if ('' === $replacement) {
            return $replacement;
        }
        $lower = \mb_strtolower($match);
        $upper = \mb_strtoupper($match);

        return match (true) {
            $match === $upper && $match !== $lower => \mb_strtoupper($replacement), // ALL CAPS (and has letters)
            $match === $lower => \mb_strtolower($replacement), // all lowercase
            $match === self::titleCase($match) => self::titleCase($replacement), // Titlecase
            default => $replacement, // mixed / unknown → leave the replacement as typed
        };
    }

    private static function titleCase(string $text): string
    {
        return \mb_strtoupper(\mb_substr($text, start: 0, length: 1)).\mb_strtolower(\mb_substr($text, start: 1));
    }

    /**
     * The byte offsets of every match of $query within a single $line, using the
     * same matching semantics as {@see search()} — for the language-aware filter
     * to place each occurrence against the file's comment/string spans.
     *
     * @return list<int>
     */
    public function matchOffsets(string $line, string $query, SearchOptions $options): array
    {
        return \array_column($this->matchSpans($line, $query, $options), column_key: 'start');
    }

    /**
     * The same matches, with their LENGTHS — which a replace needs and an
     * offset alone cannot give it: under `regex` a match is not
     * `strlen($query)` long, so splicing at an offset without one rewrites the
     * wrong span. The results' own `ranges` cannot be reused for this either;
     * those are offsets into the TRIMMED preview text, not into the file's line.
     *
     * @return list<array{start: int, length: int}>
     */
    public function matchSpans(string $line, string $query, SearchOptions $options): array
    {
        return \array_map(
            static fn (array $hit): array => ['start' => $hit[1], 'length' => \strlen($hit[0])],
            '' === $line ? [] : $this->hits($line, $this->pattern($query, $options)),
        );
    }

    /** Build a PCRE pattern mirroring the grep flags, for the PHP fallback. */
    private function pattern(string $query, SearchOptions $options): string
    {
        $pattern = SearchPattern::build(
            $query,
            $options->regex ? MatchMode::Regex : MatchMode::Literal,
            $options->wholeWord ? WordMode::WholeWord : WordMode::Anywhere,
            $options->caseSensitive ? CaseMode::Sensitive : CaseMode::Insensitive,
        );

        // A line break typed into a multi-line query is the file's, whichever
        // it is: the scan reads the disk's bytes, where a CRLF file's `\n`
        // never stands alone. (Only a typed break — a regex's `\n` is its own.)
        return \str_replace("\n", replace: '\r?\n', subject: $pattern);
    }

    /** A file's contents as a string, or '' when it is not a readable file. */
    private function readFile(string $abs): string
    {
        return Files::read($abs);
    }

    /**
     * Whether $grep reads PCRE (`-P`: GNU grep's), the dialect the find bar,
     * the "bad regex" check and the highlights all speak. Under `-E` GNU grep
     * reads POSIX ERE, where `\d` is a `d`, so the lines it picked were the
     * wrong ones: `\d+` found "ddd". BSD grep has no `-P` and stays on `-E`,
     * which reads `\d`, `\s`, `\w` and `\b` as PCRE does and refuses a
     * lookaround outright — handing the search to the built-in engine.
     */
    private function readsPcre(string $grep): bool
    {
        try {
            // An option grep does not know is a usage error (2); one it knows
            // finds no `x` in nothing (1).
            return $this->pcre ??= 1 === new Process([$grep, '-qP', 'x', '/dev/null'], timeout: 5)->run();

            // Linux refuses to spawn a grep that cannot run (macOS spawns it,
            // and reads its exit code): the search's own fallback takes over.
            // @codeCoverageIgnoreStart
        } catch (ProcessRuntimeException) {
            return $this->pcre = false;
        }

        // @codeCoverageIgnoreEnd
    }

    private function findGrep(): ?string
    {
        // Resolved once: a lookup stats every $PATH entry, and this
        // ran per debounced keystroke in Find in Files and Search Everywhere.
        if (!$this->grepResolved) {
            $this->grepResolved = true;
            $this->grep = PathExecutables::here()->find('grep');
        }

        return $this->grep;
    }

    /**
     * The folders, by PATH from the root, no local search walks besides
     * {@see EXCLUDES}: the history, and the vendor dir composer.json moved
     * where a skip of `vendor` by name misses it.
     *
     * @return list<string>
     */
    private function skippedPaths(): array
    {
        $vendor = ComposerLayout::of($this->rootDir)->vendorPath($this->rootDir);

        return null === $vendor ? [self::HISTORY] : [self::HISTORY, $vendor];
    }

    /**
     * What grep is handed for the scope $paths. A folder it skips, named as
     * the scope, is searched — what is named is asked for — and only what
     * lies below it skipped. One skipped by name goes as `vendor/.`: grep
     * skips even "a command-line directory with a name suffix" it excludes,
     * and that one ends in `.`. Here, the skipped paths are left out of the
     * rest by {@see without()}; a server's grep is handed the scope as it is.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function operands(array $paths): array
    {
        $skipped = $this->onServer() ? [] : $this->skippedPaths();
        $operands = [];
        foreach ($paths as $path) {
            $named = \rtrim($path, characters: '/');
            if (\in_array($named, $skipped, strict: true)) {
                $operands[] = $named;

                continue;
            }
            $kept = [] === $skipped ? [$path] : $this->without([$path], $skipped);
            // Handed over whole: a folder opened up for a skipped path below goes as its entries.
            // On a server the cache may not hold the folder: the server's grep is handed it as one.
            if ([$path] === $kept && \in_array(\basename($named), self::EXCLUDES, strict: true) && ($this->onServer() || \is_dir($this->rootDir.'/'.$named))) {
                $operands[] = $named.'/.';

                continue;
            }
            \array_push($operands, ...$kept);
        }

        return $operands;
    }

    /**
     * $paths with every folder that holds one of $skipped replaced by what is
     * in it but the way there — `.` by the root's entries, `.porta` by its own —
     * so a recursive grep never reaches a skipped folder. Unchanged when none
     * of them is there. {@see operands()} keeps one the scope names.
     *
     * @param list<string> $paths
     * @param list<string> $skipped
     *
     * @return list<string>
     */
    private function without(array $paths, array $skipped): array
    {
        $skipped = \array_values(\array_filter($skipped, fn (string $path): bool => \is_dir($this->rootDir.'/'.$path)));
        if ([] === $skipped) {
            return $paths;
        }
        $expanded = [];
        foreach ($paths as $path) {
            $prefix = '.' === $path ? '' : \rtrim($path, characters: '/').'/';
            $holds = \array_filter($skipped, static fn (string $skip): bool => \str_starts_with($skip.'/', $prefix));
            if ([] === $holds) {
                $expanded[] = $path;

                continue;
            }
            if (\in_array(\substr($prefix, offset: 0, length: -1), $holds, strict: true)) {
                continue; // a skipped folder reached on the way down
            }
            $entries = \scandir('' === $prefix ? $this->rootDir : $this->rootDir.'/'.$prefix);
            // A link is never handed over: grep -r follows a link named on its
            // command line, which a walk of `.` passes over — one aimed at `/`
            // would have searched, and Replace All rewritten, outside the project.
            $children = \array_values(\array_filter(
                \array_map(static fn (string $entry): string => $prefix.$entry, \array_diff(false === $entries ? [] : $entries, ['.', '..'])),
                fn (string $child): bool => !\is_link($this->rootDir.'/'.$child),
            ));
            \array_push($expanded, ...$this->without($children, $skipped));
        }

        return $expanded;
    }
}
