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
     * @param callable(list<array{path: string, line: int, text: string}>): void $onDone
     * @param string                                                             $scope grep path relative to the project root; `.` searches the whole project
     */
    public function search(string $query, callable $onDone, SearchOptions $options = new SearchOptions(), string $scope = '.'): void
    {
        $this->cancel();
        if ('' === trim($query)) {
            $onDone([]);

            return;
        }
        // A malformed regex yields no results rather than a crash.
        if ($options->regex && false === @preg_match($this->pattern($query, $options), '')) {
            $onDone([]);

            return;
        }

        $grep = $this->findGrep();
        if (null === $grep) {
            $onDone($this->searchWithPhp($query, $options, $scope));

            return;
        }

        $flags = ['-rn', '--binary-files=without-match', $options->regex ? '-E' : '-F'];
        if (!$options->caseSensitive) {
            $flags[] = '-i';
        }
        if ($options->wholeWord) {
            $flags[] = '-w';
        }
        // The scope is a path argument, not the cwd: grep still runs in $rootDir
        // (AsyncProcess below), so a `subdir/file:line` match stays root-relative.
        $command = [$grep, ...$flags, '--', $query, $scope];
        foreach (self::EXCLUDES as $dir) {
            array_splice($command, 1, 0, ['--exclude-dir='.$dir]);
        }

        $process = new AsyncProcess($command, $this->rootDir);
        $started = $process->start(
            onExit: fn (int $code, string $out) => $this->finish($out, $onDone),
            onChunk: function () use ($process, $onDone): void {
                if (substr_count($process->output(), "\n") > self::MAX_RESULTS) {
                    // Enough results; stop early
                    $this->finish($process->output(), $onDone);
                }
            },
        );
        if (!$started) {
            // Defensive: proc_open spawns even unusable binaries (exec
            // failure surfaces later as an exit code), so this is unreachable
            // in tests.
            // @codeCoverageIgnoreStart
            $onDone($this->searchWithPhp($query, $options));

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

    /** @param callable(list<array{path: string, line: int, text: string}>): void $onDone */
    private function finish(string $output, callable $onDone): void
    {
        $results = $this->parse($output);
        $this->cancel();
        $onDone($results);
    }

    /** @return list<array{path: string, line: int, text: string}> */
    private function parse(string $output): array
    {
        $results = [];
        foreach (explode("\n", $output) as $line) {
            $m = [];
            if ('' === $line || !preg_match('/^(?:\.\/)?(.+?):(\d+):(.*)$/', $line, $m)) {
                continue;
            }
            $results[] = ['path' => $m[1], 'line' => (int) $m[2] - 1, 'text' => trim($m[3])];
            if (\count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $results;
    }

    /** @return list<array{path: string, line: int, text: string}> */
    private function searchWithPhp(string $query, SearchOptions $options, string $scope = '.'): array
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

        $results = [];
        foreach ($finder as $file) {
            $content = (string) @file_get_contents($file->getPathname());
            if (str_contains($content, "\0")) {
                continue;
            }
            $rel = $prefix.$file->getRelativePathname();
            foreach (explode("\n", $content) as $lineIndex => $line) {
                if (1 === preg_match($pattern, $line)) {
                    $results[] = ['path' => $rel, 'line' => $lineIndex, 'text' => trim($line)];
                    if (\count($results) >= self::MAX_RESULTS) {
                        break 2;
                    }
                }
            }
        }

        return $results;
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
        $repl = $options->regex ? $replacement : (string) preg_replace('/[\\\\$]/', '\\\\$0', $replacement);
        $result = @preg_replace($pattern, $repl, $content);

        return null === $result ? $content : $result;
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
