<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Filesystem\RemoteFilesystem;
use Porta\Support\AsyncProcess;
use Porta\Support\Files;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Asynchronous project-wide text search.
 *
 * Prefers `grep -rn` streamed through an {@see AsyncProcess} on the event
 * loop; falls back to a synchronous PHP walk when grep is unavailable.
 * Results are capped to keep the panel responsive.
 */
final class ProjectSearch
{
    public const int MAX_RESULTS = 500;
    private const array EXCLUDES = ['.git', 'node_modules', 'vendor', '.idea', '.vscode'];

    private ?AsyncProcess $process = null;

    /** The resolved `grep` binary; see {@see findGrep()} — resolved once, not per search. */
    private ?string $grep = null;
    private bool $grepResolved = false;

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
            \str_contains($query, "\n") => $onDone($this->searchMultiline($pattern, $paths, $mask)),
            null === ($grep = $this->findGrep()) => $onDone($this->searchWithPhp($query, $options, $paths, $mask)),
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
        $flags = ['-rnH', '--binary-files=without-match', $options->regex ? '-E' : '-F'];
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
        // The scope paths are arguments, not the cwd: grep still runs in $rootDir
        // (AsyncProcess below), so a `subdir/file:line` match stays root-relative.
        $command = [$grep, ...$flags, '--', $query, ...$paths];
        foreach (self::EXCLUDES as $dir) {
            \array_splice($command, offset: 1, length: 0, replacement: ['--exclude-dir='.$dir]);
        }
        // A remote project's cache holds only what was opened: its grep runs
        // on the server, where every file is — named `grep` there, since the
        // path found here is this machine's.
        $command = $this->remote?->remoteCommand(['grep', ...\array_slice($command, offset: 1)]) ?? $command;

        // A grep that cannot answer hands the search to the built-in engine.
        // Linux's posix_spawn refuses an unrunnable binary outright; macOS
        // spawns it and reports an exit code — one fallback serves both.
        $fallback = fn () => $onDone($this->searchWithPhp($query, $options, $paths, $mask));
        $process = new AsyncProcess($command, $this->rootDir);
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
        $count = \preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE);
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
     * @param list<string> $paths scope paths (each a directory, a single file, or `.`)
     *
     * @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>
     */
    private function searchWithPhp(string $query, SearchOptions $options, array $paths, string $mask): array
    {
        $pattern = $this->pattern($query, $options);
        $globs = $this->maskGlobs($mask);
        $results = [];
        $seen = [];
        foreach ($paths as $scope) {
            foreach ($this->scopeFiles($scope, $globs) as $rel => $abs) {
                if (null !== ($seen[$rel] ?? null)) {
                    continue; // overlapping scopes (e.g. open files under a dir) match once
                }
                $seen[$rel] = true;
                $content = $this->readFile($abs);
                if (\str_contains($content, "\0")) {
                    continue;
                }
                foreach (\explode("\n", $content) as $lineIndex => $line) {
                    if (1 !== \preg_match($pattern, $line)) {
                        continue;
                    }

                    $text = \trim($line);
                    $results[] = ['path' => $rel, 'line' => $lineIndex, 'text' => $text, 'ranges' => $this->rangesFor($text, $pattern)];
                    if (\count($results) >= self::MAX_RESULTS) {
                        return $results;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Whole-file scan for a query that spans lines (grep is line-based). Each
     * match reports its 0-based start line and a single-line preview (its first
     * line, with `⏎…` appended when the match continues onto further lines).
     *
     * @param list<string> $paths
     *
     * @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>
     */
    private function searchMultiline(string $pattern, array $paths, string $mask): array
    {
        $globs = $this->maskGlobs($mask);
        $results = [];
        $seen = [];
        foreach ($paths as $scope) {
            foreach ($this->scopeFiles($scope, $globs) as $rel => $abs) {
                if (null !== ($seen[$rel] ?? null)) {
                    continue;
                }
                $seen[$rel] = true;
                $content = $this->readFile($abs);
                $matches = [];
                if (\str_contains($content, "\0")) {
                    continue;
                }
                \set_error_handler(static fn (): bool => true);
                $count = \preg_match_all($pattern, $content, $matches, \PREG_OFFSET_CAPTURE);
                \restore_error_handler();
                if (false === $count) {
                    $this->gaveUp = true;

                    continue;
                }
                // A multiline query always contains a real newline, so its matches
                // are never zero-width — no empty-match guard needed here.
                foreach ($matches[0] as $hit) {
                    $results[] = $this->multilineResult($rel, $content, $hit[0], (int) $hit[1]);
                    if (\count($results) >= self::MAX_RESULTS) {
                        return $results;
                    }
                }
            }
        }

        return $results;
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
     * @return iterable<string, string>
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
            ->ignoreVCS(false)
            ->exclude(self::EXCLUDES)
            ->size('<= 1Mi');
        if ([] !== $globs) {
            $finder->name($globs); // OR of basename globs, mirroring grep --include
        }
        foreach ($finder as $file) {
            yield $prefix.$file->getRelativePathname() => $file->getPathname();
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
        $pattern = $this->pattern($query, $options);
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
}
