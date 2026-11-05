<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Filesystem\RemoteFilesystem;
use Porta\Support\AsyncProcess;
use Porta\Support\Files;
use Revolt\EventLoop;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
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
            \str_contains($query, "\n") => $this->sliced($this->searchMultiline($pattern, $paths, $mask), $onDone),
            null === ($grep = $this->findGrep()) => $this->sliced($this->searchWithPhp($pattern, $paths, $mask), $onDone),
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
        // grep's --exclude-dir matches a folder's NAME, so the history folder is
        // left out by handing grep its siblings instead. A remote project's grep
        // runs on the server, where no local history is kept.
        $grepPaths = null === $this->remote ? $this->withoutHistory($paths) : $paths;
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
        $fallback = fn () => $this->sliced($this->searchWithPhp($pattern, $paths, $mask), $onDone);
        // UTF-8, as the pattern that highlights and replaces reads it: under -i
        // grep folds É to é as that pattern does, whatever locale Porta runs in.
        $process = new AsyncProcess($command, $this->rootDir, env: [...\getenv(), 'LC_ALL' => 'C.UTF-8']);
        $started = $process->start(
            // grep's own failure (2, or 126/127 for a binary that cannot run)
            // with nothing printed is no answer; 1 is grep saying "no match",
            // which is one.
            onExit: fn (int $code, string $out) => $code >= 2 && '' === $out ? $fallback() : $this->finish($out, $onDone, $pattern),
            onChunk: function () use ($process, $onDone, $pattern): void {
                if (\substr_count($process->output(), needle: "\n") > self::MAX_RESULTS) {
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

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function parse(string $output, string $pattern): array
    {
        $results = [];
        foreach (\explode("\n", $output) as $line) {
            $m = [];
            if ('' === $line || !\preg_match('/^(?:\.\/)?(.+?):(\d+):(.*)$/', $line, $m)) {
                continue;
            }
            $text = \trim($m[3]);
            $results[] = ['path' => $m[1], 'line' => (int) $m[2] - 1, 'text' => $text, 'ranges' => $this->rangesFor($text, $pattern)];
            if (\count($results) >= self::MAX_RESULTS) {
                break;
            }
        }
        // grep -r walks in the file system's order — on ext4 a hash order, so
        // File7.php can come before File0.php: the panel lists by path, then line.
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
        $firstLine = \explode("\n", $match)[0];
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
        $finder = new Finder()
            ->files()
            ->in($abs)
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs() // passed over, as grep passes it
            ->ignoreVCS(false)
            ->exclude([...self::EXCLUDES, ...(\str_starts_with(self::HISTORY.'/', $prefix) && self::HISTORY.'/' !== $prefix ? [\substr(self::HISTORY, \strlen($prefix))] : [])]);
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
     * replacement is literal (backslashes and `$` are escaped); in regex mode
     * it may use backreferences. Returns the content unchanged on a bad regex.
     */
    public function applyReplacement(string $content, string $query, string $replacement, SearchOptions $options): string
    {
        $pattern = SearchPattern::fit($this->pattern($query, $options), $content);
        // Preserve-case (literal replace only): map each match's casing onto the
        // replacement so foo→bar, Foo→Bar and FOO→BAR in one pass.
        if ($options->preserveCase && !$options->regex) {
            \set_error_handler(static fn (): bool => true);
            try {
                $result = \preg_replace_callback($pattern, fn (array $m): string => $this->applyCasePattern($m[0], $replacement), $content);
            } finally {
                \restore_error_handler();
            }

            return $result ?? $content;
        }
        $repl = $options->regex ? $replacement : (string) \preg_replace('/[\\\\$]/', replacement: '\\\\$0', subject: $replacement);
        \set_error_handler(static fn (): bool => true);
        $result = \preg_replace($pattern, $repl, $content);
        \restore_error_handler();

        return $result ?? $content;
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
        return SearchPattern::build(
            $query,
            $options->regex ? MatchMode::Regex : MatchMode::Literal,
            $options->wholeWord ? WordMode::WholeWord : WordMode::Anywhere,
            $options->caseSensitive ? CaseMode::Sensitive : CaseMode::Insensitive,
        );
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
        // Resolved once: ExecutableFinder stats every $PATH entry, and this
        // ran per debounced keystroke in Find in Files and Search Everywhere.
        if (!$this->grepResolved) {
            $this->grepResolved = true;
            $this->grep = new ExecutableFinder()->find('grep');
        }

        return $this->grep;
    }

    /**
     * $paths with every folder that holds {@see HISTORY} replaced by what is
     * in it but the way there — `.` by the root's entries, `.porta` by its own —
     * so a recursive grep never reaches the history folder. Unchanged when the
     * project keeps no history.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function withoutHistory(array $paths): array
    {
        if (!\is_dir($this->rootDir.'/'.self::HISTORY)) {
            return $paths;
        }
        $expanded = [];
        foreach ($paths as $path) {
            $prefix = '.' === $path ? '' : \rtrim($path, characters: '/').'/';
            if (!\str_starts_with(self::HISTORY.'/', $prefix)) {
                $expanded[] = $path;

                continue;
            }
            if (self::HISTORY.'/' === $prefix) {
                continue; // the history folder itself, named as the scope
            }
            $entries = \scandir('' === $prefix ? $this->rootDir : $this->rootDir.'/'.$prefix);
            // A link is never handed over: grep -r follows a link named on its
            // command line, which a walk of `.` passes over — one aimed at `/`
            // would have searched, and Replace All rewritten, outside the project.
            $children = \array_values(\array_filter(
                \array_map(static fn (string $entry): string => $prefix.$entry, \array_diff(false === $entries ? [] : $entries, ['.', '..'])),
                fn (string $child): bool => !\is_link($this->rootDir.'/'.$child),
            ));
            \array_push($expanded, ...$this->withoutHistory($children));
        }

        return $expanded;
    }
}
