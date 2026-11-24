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

    /** Asks a grep whether it takes --null: 1, no `x` in nothing; 2, a usage error. */
    private const array ASK_NULL = ['--null', '-q', 'x', '/dev/null'];

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

    /** What the grep the searches run said it takes, asked in the background; see {@see whenAnswered()}. */
    private readonly GrepAnswers $answers;

    /** Reads grep's output lines into hits; holds what the search found to be a file here. */
    private GrepHits $hits;

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

    /**
     * Whether no word rule was asked, which grep does not check (no -w): a
     * line whose match could not finish is then grep's word against none.
     */
    private bool $grepWords = true;

    /** @var array<string, bool> by pattern as fit() made it, whether it compiles */
    private array $compiles = [];

    /**
     * @param GrepAnswers|null $answers shared by every engine searching this project, so grep is asked once
     *                                  — an engine made per call (an agent's search) asks nothing again
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?RemoteFilesystem $remote = null,
        ?GrepAnswers $answers = null,
    ) {
        $this->answers = $answers ?? new GrepAnswers($rootDir);
        $this->hits = new GrepHits($rootDir);
    }

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
        $this->answers->newSearch();
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
            default => $this->viaGrep($query, $pattern, $onDone, $options, $paths, $mask),
        };
    }

    /**
     * A one-line search, by grep where there is one to run it.
     *
     * Where it runs is decided once, as the suffix its answers are kept
     * under: '' for this machine (a local project, or a remote one whose
     * transport builds no command: its server not reached yet, or one that
     * runs none), '@server' for the server: whether the server's --null
     * question can be built at all. That question, built here, is the one
     * asked. A server's grep needs none here.
     *
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void $onDone
     * @param list<string>                                                                                                      $paths
     */
    private function viaGrep(string $query, string $pattern, callable $onDone, SearchOptions $options, array $paths, string $mask): void
    {
        $serverAsk = $this->remote?->remoteCommand(['grep', ...self::ASK_NULL]);
        $where = null === $serverAsk ? '' : '@server';
        $grep = '' === $where ? $this->findGrep() : 'grep';
        if (null === $grep) {
            $this->builtIn($this->searchWithPhp($pattern, $paths, $mask), $onDone);

            return;
        }
        $this->whenAnswered($grep, $options->regex, $where, $serverAsk, function () use ($grep, $query, $pattern, $onDone, $options, $paths, $mask, $where): void {
            // A regex goes to the built-in engine rather than to a grep that
            // would misread it: here when -P went unanswered (it reads the
            // whole project here), and wherever it would run as ERE while
            // using what only PCRE reads (`\d+` found "ddd" in GNU's ERE) —
            // on a server, said to have read only the copy here, which beats a
            // wrong answer that looks whole.
            $options->regex && (('' === $where && !$this->answers->answered('-P')) || (!$this->answers->takes('-P'.$where) && EreReading::differs($query)))
                ? $this->builtIn($this->searchWithPhp($pattern, $paths, $mask), $onDone)
                : $this->grep($grep, $query, $pattern, $onDone, $options, $paths, $mask, $where);
        });
    }

    /**
     * Run $search once the grep at $where has said what it takes — --null,
     * and -P for a regex — asked in the background: the search waits, never
     * the loop. The two greps' answers are kept apart.
     *
     * @param list<string>|null $serverAsk the server's --null question, built: null when the search runs here
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the query is a regex is the one fact deciding which questions it waits on
    private function whenAnswered(string $grep, bool $regex, string $where, ?array $serverAsk, \Closure $search): void
    {
        $this->answers->ask('--null'.$where, static fn (): ?array => $serverAsk ?? [$grep, ...self::ASK_NULL]);
        if ($regex) {
            $this->answers->ask('-P'.$where, fn (): ?array => $this->putTo($where, $grep, ['-qP', 'x', '/dev/null']));
        }
        // Parked till the answers it needs land; a cancel drops it.
        $this->answers->whenAnswered($this, $regex ? ['--null'.$where, '-P'.$where] : ['--null'.$where], $search);
    }

    /**
     * $argv as the grep at $where runs it: $grep here, the server's there.
     *
     * @param list<string> $argv
     *
     * @return list<string>|null
     */
    private function putTo(string $where, string $grep, array $argv): ?array
    {
        return '' === $where ? [$grep, ...$argv] : $this->remote?->remoteCommand(['grep', ...$argv]);
    }

    /**
     * The search, run by $grep in the background; the built-in engine takes
     * over when that grep cannot answer.
     *
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void $onDone
     * @param list<string>                                                                                                      $paths
     * @param string                                                                                                            $where '' to run here, '@server' on the server: see {@see viaGrep()}
     */
    private function grep(string $grep, string $query, string $pattern, callable $onDone, SearchOptions $options, array $paths, string $mask, string $where): void
    {
        // -H: GNU grep drops the `path:` prefix when it is handed a single file
        // (a file scope), and the parser reads `path:line:text`; BSD grep and
        // ugrep print it anyway, which is why only Linux lost those matches.
        $flags = ['-rnH', '--binary-files=without-match'];
        if (!$options->caseSensitive) {
            $flags[] = '-i';
        }
        // No -w: grep's word is not the pattern's. `$foo` is a whole word to
        // grep -w, never to `\b(?:\$foo)\b`; and GNU grep 3.11 -w missed 4446
        // of the lines `\bfoo\b` /u matches with a code point beside `foo`
        // (combining marks glibc counts as letters; measured 2026-11-24). grep
        // finds every occurrence; listed() keeps the whole words.
        $this->grepWords = !$options->wholeWord;
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
        // The scope paths are arguments, not the cwd: grep still runs in $rootDir
        // (AsyncProcess below), so a `subdir/file:line` match stays root-relative.
        $args = static fn (string $regex): array => [...$flags, $options->regex ? $regex : '-F', '--', $query, ...$grepPaths];
        // A remote project's cache holds only what was opened: its grep runs
        // on the server, where every file is — named `grep` there, since the
        // path found here is this machine's. Each grep is handed what it said
        // it takes (see viaGrep()): its own answers, kept apart.
        $this->hits = new GrepHits($this->rootDir);
        // --null ends each file name grep prints with a NUL, and a name holding
        // `:12:` is then read exactly (GNU's, BSD's and ugrep take it; BusyBox's
        // may not). -P reads a regex as the find bar does: under -E GNU grep reads
        // POSIX ERE, where `\d` is a `d`, so `\d+` found "ddd". A grep without -P
        // is handed only a regex ERE reads the same (see EreReading); any
        // other goes to the built-in engine before this.
        $argv = fn (string $at): array => [
            ...($this->answers->takes('--null'.$at) ? ['--null'] : []),
            ...$args($options->regex && $this->answers->takes('-P'.$at) ? '-P' : '-E'),
        ];
        $command = '' === $where ? null : $this->remote?->remoteCommand(['grep', ...$argv('@server')]);
        // Run here: a local project, or a remote one's copy, which holds only
        // what was opened — said. A server that stopped building a command
        // mid-search has no grep to run: the built-in engine reads the copy
        // (a transport, once found, is kept, so none does today).
        $this->localOnly = $this->onServer() && '' === $where;
        $command = '' === $where ? [$grep, ...$argv('')] : $command;

        // A grep that cannot answer hands the search to the built-in engine.
        // Linux's posix_spawn refuses an unrunnable binary outright; macOS
        // spawns it and reports an exit code — one fallback serves both.
        $fallback = fn () => $this->builtIn($this->searchWithPhp($pattern, $paths, $mask), $onDone);
        // Nothing but the history there: grep with no path would read stdin.
        // A server that stopped building a command: no grep to run there.
        if ([] === $grepPaths || null === $command) {
            [] === $grepPaths ? $onDone([]) : $fallback();

            return;
        }
        // UTF-8, as the pattern that highlights and replaces reads it: under -i
        // grep folds É to é as that pattern does, whatever locale Porta runs in.
        // Not every fold: BSD grep -i finds no `ſ` for `s`, no `ς` for `σ`,
        // where /ui does — a miss no filter of grep's lines can bring back.
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
                $kept += $this->keptOf($lines, $pattern, $counted);
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
        $this->answers->drop($this);
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
     * How many of grep's $lines {@see parse()} keeps, not counting again a
     * hit already in $counted.
     *
     * @param list<string>               $lines
     * @param array<string, bool> $counted the hits counted so far, by `path:line`
     */
    private function keptOf(array $lines, string $pattern, array &$counted): int
    {
        $kept = 0;
        foreach ($lines as $line) {
            $kept += (int) (null !== $this->listed($this->hits->hit($line), $pattern, $counted));
        }

        return $kept;
    }

    /**
     * The path of grep's $hit if it is listed, and then it is $seen; null if
     * not. Listed is a line that names a path, not dropped, not listed
     * already — and that $pattern, the one the built-in engine runs and the
     * panel highlights with, matches: grep reads Unicode and words its own
     * way, and a line only grep matched is no hit. A match that could not
     * finish (a limit) keeps grep's line where grep checked the same rule;
     * either way the search says it gave up.
     *
     * @param array{path: ?string, line: int, text: string, line_text: string} $hit
     * @param array<string, bool>                                            $seen by `path:line`
     */
    private function listed(array $hit, string $pattern, array &$seen): ?string
    {
        $path = $hit['path'];
        $key = ($path ?? '').':'.$hit['line'];
        $listed = null !== $path && !$this->dropped($path) && !\array_key_exists($key, $seen) && ($this->matchOf($pattern, $hit['line_text']) ?? $this->grepWords);
        if ($listed) {
            $seen[$key] = true;
        }

        return $listed ? $path : null;
    }

    /**
     * Whether $pattern matches $line; null when the match could not finish (a
     * backtrack, recursion or JIT stack limit), which is said: the search
     * gave up on it. A pattern that does not compile without /u, on a line
     * that is not UTF-8, matches nothing there.
     */
    private function matchOf(string $pattern, string $line): ?bool
    {
        $fitted = SearchPattern::fit($pattern, $line);
        // Asked once a pattern, not once a line: a line not UTF-8 has /u
        // dropped, and a pattern may not compile without it.
        if (!($this->compiles[$fitted] ??= SearchPattern::compiles($fitted))) {
            return false;
        }
        $matched = \preg_match($fitted, $line);
        if (false === $matched) {
            $this->gaveUp = true; // a limit: a pattern that compiles fails on nothing else
        }

        return false === $matched ? null : 1 === $matched;
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
            $hit = $this->hits->hit($line);
            $path = $this->listed($hit, $pattern, $seen);
            if (null === $path) {
                continue;
            }
            $results[] = ['path' => $path, 'line' => $hit['line'], 'text' => $hit['text'], 'ranges' => $this->rangesFor($hit['text'], $pattern)];
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
                // No grep stands behind the line here: one that could not finish is no hit.
                if (true !== $this->matchOf($pattern, $line)) {
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
