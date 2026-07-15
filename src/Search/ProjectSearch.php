<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Support\AsyncProcess;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;

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

    public function __construct(
        private readonly string $rootDir,
    ) {}

    /**
     * @param callable(list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>): void $onDone
     * @param string|list<string>                                                                                               $scope grep path(s) relative to the project root; `.` searches the whole project, a list searches each path (a directory or a single file), an empty list matches nothing
     */
    public function search(string $query, callable $onDone, SearchOptions $options = new SearchOptions(), string|array $scope = '.', string $mask = ''): void
    {
        $this->cancel();
        $paths = \is_array($scope) ? array_values($scope) : [$scope];
        if ('' === trim($query) || [] === $paths) {
            $onDone([]);

            return;
        }
        $pattern = $this->pattern($query, $options);
        // A malformed regex yields no results rather than a crash.
        if ($options->regex && !$this->patternValid($pattern)) {
            $onDone([]);

            return;
        }

        // A query spanning lines can't go through line-based grep: scan whole files.
        if (str_contains($query, "\n")) {
            $onDone($this->searchMultiline($pattern, $paths, $mask));

            return;
        }

        $grep = $this->findGrep();
        if (null === $grep) {
            $onDone($this->searchWithPhp($query, $options, $paths, $mask));

            return;
        }

        $flags = ['-rn', '--binary-files=without-match', $options->regex ? '-E' : '-F'];
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
            array_splice($command, offset: 1, length: 0, replacement: ['--exclude-dir='.$dir]);
        }

        $process = new AsyncProcess($command, $this->rootDir);
        $started = $process->start(
            onExit: fn (int $code, string $out) => $this->finish($out, $onDone, $pattern),
            onChunk: function () use ($process, $onDone, $pattern): void {
                if (substr_count($process->output(), needle: "\n") > self::MAX_RESULTS) {
                    // Enough results; stop early
                    $this->finish($process->output(), $onDone, $pattern);
                }
            },
        );
        if (!$started) {
            // Defensive: proc_open spawns even unusable binaries (exec
            // failure surfaces later as an exit code), so this is unreachable
            // in tests.
            // @codeCoverageIgnoreStart
            $onDone($this->searchWithPhp($query, $options, $paths, $mask));

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
        foreach (explode("\n", $output) as $line) {
            $m = [];
            if ('' === $line || !preg_match('/^(?:\.\/)?(.+?):(\d+):(.*)$/', $line, $m)) {
                continue;
            }
            $text = trim($m[3]);
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
     * `PREG_OFFSET_CAPTURE` are converted to display columns via `mb_strwidth`.
     *
     * @return list<array{start: int, length: int}>
     */
    private function rangesFor(string $text, string $pattern): array
    {
        $matches = [];
        if ('' === $text) {
            return [];
        }
        set_error_handler(static fn (): bool => true);
        $count = preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE);
        restore_error_handler();
        if (false === $count) {
            return [];
        }
        $ranges = [];
        foreach ($matches[0] as $hit) {
            $match = $hit[0];
            if ('' === $match) {
                continue; // a zero-width match highlights nothing
            }
            $ranges[] = ['start' => mb_strwidth(substr($text, offset: 0, length: (int) $hit[1])), 'length' => mb_strwidth($match)];
        }

        return $ranges;
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
                if (($seen[$rel] ?? null) !== null) {
                    continue; // overlapping scopes (e.g. open files under a dir) match once
                }
                $seen[$rel] = true;
                $content = $this->readFile($abs);
                if (str_contains($content, "\0")) {
                    continue;
                }
                foreach (explode("\n", $content) as $lineIndex => $line) {
                    if (1 !== preg_match($pattern, $line)) {
                        continue;
                    }

                    $text = trim($line);
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
                if (($seen[$rel] ?? null) !== null) {
                    continue;
                }
                $seen[$rel] = true;
                $content = $this->readFile($abs);
                $matches = [];
                if (str_contains($content, "\0")) {
                    continue;
                }
                set_error_handler(static fn (): bool => true);
                $count = preg_match_all($pattern, $content, $matches, \PREG_OFFSET_CAPTURE);
                restore_error_handler();
                if (false === $count) {
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
        $line = substr_count(substr($content, offset: 0, length: $offset), needle: "\n");
        $firstLine = explode("\n", $match)[0];
        $preview = str_contains($match, "\n") ? $firstLine.' ⏎…' : $firstLine;
        $width = mb_strwidth($firstLine);
        $ranges = 0 === $width ? [] : [['start' => 0, 'length' => $width]];

        return ['path' => $rel, 'line' => $line, 'text' => $preview, 'ranges' => $ranges];
    }

    /**
     * The `[rootRelativePath => absolutePath]` files a scope entry covers: a
     * single file yields itself; a directory (or `.`) is walked with Finder,
     * whose relative paths are prefixed back on so every result stays
     * root-relative.
     *
     * @param list<string> $globs basename globs, mirroring grep --include (dir scopes only)
     *
     * @return iterable<string, string>
     */
    private function scopeFiles(string $scope, array $globs): iterable
    {
        $abs = '.' === $scope ? $this->rootDir : $this->rootDir.'/'.$scope;
        if (is_file($abs)) {
            yield $scope => $abs; // an explicit file is searched regardless of the mask

            return;
        }
        $prefix = '.' === $scope ? '' : rtrim($scope, characters: '/').'/';
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
            $token = trim($token);
            if ('' === $token) {
                continue;
            }
            $m = [];
            if (1 !== preg_match('/^(.*)\{([^{}]*)\}(.*)$/', $token, $m)) {
                $globs[] = $token;

                continue;
            }
            foreach (explode(',', $m[2]) as $option) {
                $globs[] = $m[1].trim($option).$m[3];
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
        foreach (str_split($mask) as $ch) {
            if ('{' === $ch) {
                ++$depth;
            }
            if ('}' === $ch) {
                $depth = max(0, $depth - 1);
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
            set_error_handler(static fn (): bool => true);
            $result = preg_replace_callback($pattern, fn (array $m): string => $this->applyCasePattern($m[0], $replacement), $content);
            restore_error_handler();

            return null === $result ? $content : $result;
        }
        $repl = $options->regex ? $replacement : (string) preg_replace('/[\\\\$]/', replacement: '\\\\$0', subject: $replacement);
        set_error_handler(static fn (): bool => true);
        $result = preg_replace($pattern, $repl, $content);
        restore_error_handler();

        return null === $result ? $content : $result;
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
        $lower = mb_strtolower($match);
        $upper = mb_strtoupper($match);
        if ($match === $upper && $match !== $lower) {
            return mb_strtoupper($replacement); // ALL CAPS (and has letters)
        }
        if ($match === $lower) {
            return mb_strtolower($replacement); // all lowercase
        }
        $title = mb_strtoupper(mb_substr($match, start: 0, length: 1)).mb_strtolower(mb_substr($match, start: 1));
        if ($match === $title) {
            return mb_strtoupper(mb_substr($replacement, start: 0, length: 1)).mb_strtolower(mb_substr($replacement, start: 1)); // Titlecase
        }

        return $replacement; // mixed / unknown → leave the replacement as typed
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
        $matches = [];
        if ('' === $line) {
            return [];
        }
        set_error_handler(static fn (): bool => true);
        $count = preg_match_all($this->pattern($query, $options), $line, $matches, \PREG_OFFSET_CAPTURE);
        restore_error_handler();
        if (false === $count) {
            return [];
        }
        $offsets = [];
        foreach ($matches[0] as $hit) {
            if ('' === $hit[0]) {
                continue;
            }

            $offsets[] = (int) $hit[1];
        }

        return $offsets;
    }

    /** Build a PCRE pattern mirroring the grep flags, for the PHP fallback. */
    private function pattern(string $query, SearchOptions $options): string
    {
        return SearchPattern::build($query, $options->regex, $options->wholeWord, $options->caseSensitive);
    }

    /** Whether a user regex compiles, swallowing the warning a malformed pattern raises. */
    private function patternValid(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $ok = preg_match($pattern, subject: '');
        restore_error_handler();

        return false !== $ok;
    }

    /** A file's contents as a string, or '' when it is not a readable file. */
    private function readFile(string $abs): string
    {
        return is_file($abs) ? (string) file_get_contents($abs) : '';
    }

    private function findGrep(): ?string
    {
        return new ExecutableFinder()->find('grep');
    }
}
