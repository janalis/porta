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
     * @param string                                                                                                            $scope grep path relative to the project root; `.` searches the whole project
     */
    public function search(string $query, callable $onDone, SearchOptions $options = new SearchOptions(), string $scope = '.', string $mask = ''): void
    {
        $this->cancel();
        if ('' === trim($query)) {
            $onDone([]);

            return;
        }
        $pattern = $this->pattern($query, $options);
        // A malformed regex yields no results rather than a crash.
        if ($options->regex && false === @preg_match($pattern, '')) {
            $onDone([]);

            return;
        }

        $grep = $this->findGrep();
        if (null === $grep) {
            $onDone($this->searchWithPhp($query, $options, $scope, $mask));

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
        // The scope is a path argument, not the cwd: grep still runs in $rootDir
        // (AsyncProcess below), so a `subdir/file:line` match stays root-relative.
        $command = [$grep, ...$flags, '--', $query, $scope];
        foreach (self::EXCLUDES as $dir) {
            array_splice($command, 1, 0, ['--exclude-dir='.$dir]);
        }

        $process = new AsyncProcess($command, $this->rootDir);
        $started = $process->start(
            onExit: fn (int $code, string $out) => $this->finish($out, $onDone, $pattern),
            onChunk: function () use ($process, $onDone, $pattern): void {
                if (substr_count($process->output(), "\n") > self::MAX_RESULTS) {
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
            $onDone($this->searchWithPhp($query, $options, $scope, $mask));

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
        if ('' === $text || false === @preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $ranges = [];
        foreach ($matches[0] as $hit) {
            $match = $hit[0];
            if ('' === $match) {
                continue; // a zero-width match highlights nothing
            }
            $ranges[] = ['start' => mb_strwidth(substr($text, 0, (int) $hit[1])), 'length' => mb_strwidth($match)];
        }

        return $ranges;
    }

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function searchWithPhp(string $query, SearchOptions $options, string $scope = '.', string $mask = ''): array
    {
        $pattern = $this->pattern($query, $options);
        // Finder paths are relative to its `in()` root, so when scoped to a
        // subdir we prefix it back on to keep every `path` root-relative.
        $prefix = '.' === $scope ? '' : rtrim($scope, '/').'/';
        $finder = new Finder()
            ->files()
            ->in('.' === $scope ? $this->rootDir : $this->rootDir.'/'.$scope)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->exclude(self::EXCLUDES)
            ->size('<= 1Mi');
        $globs = $this->maskGlobs($mask);
        if ([] !== $globs) {
            $finder->name($globs); // OR of basename globs, mirroring grep --include
        }

        $results = [];
        foreach ($finder as $file) {
            $content = (string) @file_get_contents($file->getPathname());
            if (str_contains($content, "\0")) {
                continue;
            }
            $rel = $prefix.$file->getRelativePathname();
            foreach (explode("\n", $content) as $lineIndex => $line) {
                if (1 === preg_match($pattern, $line)) {
                    $text = trim($line);
                    $results[] = ['path' => $rel, 'line' => $lineIndex, 'text' => $text, 'ranges' => $this->rangesFor($text, $pattern)];
                    if (\count($results) >= self::MAX_RESULTS) {
                        break 2;
                    }
                }
            }
        }

        return $results;
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
            if (1 === preg_match('/^(.*)\{([^{}]*)\}(.*)$/', $token, $m)) {
                foreach (explode(',', $m[2]) as $option) {
                    $globs[] = $m[1].trim($option).$m[3];
                }
            } else {
                $globs[] = $token;
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
            } elseif ('}' === $ch) {
                $depth = max(0, $depth - 1);
            }
            if (',' === $ch && 0 === $depth) {
                $tokens[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
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
            $result = @preg_replace_callback($pattern, fn (array $m): string => $this->applyCasePattern($m[0], $replacement), $content);

            return null === $result ? $content : $result;
        }
        $repl = $options->regex ? $replacement : (string) preg_replace('/[\\\\$]/', '\\\\$0', $replacement);
        $result = @preg_replace($pattern, $repl, $content);

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
        $title = mb_strtoupper(mb_substr($match, 0, 1)).mb_strtolower(mb_substr($match, 1));
        if ($match === $title) {
            return mb_strtoupper(mb_substr($replacement, 0, 1)).mb_strtolower(mb_substr($replacement, 1)); // Titlecase
        }

        return $replacement; // mixed / unknown → leave the replacement as typed
    }

    /** Build a PCRE pattern mirroring the grep flags, for the PHP fallback. */
    private function pattern(string $query, SearchOptions $options): string
    {
        $body = $options->regex ? $query : preg_quote($query, '/');
        if ($options->wholeWord) {
            $body = '\b(?:'.$body.')\b';
        }

        return '/'.$body.'/'.($options->caseSensitive ? '' : 'i');
    }

    private function findGrep(): ?string
    {
        return new ExecutableFinder()->find('grep');
    }
}
