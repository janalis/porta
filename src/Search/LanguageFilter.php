<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Support\Files;
use Porta\Support\LanguageMap;
use Porta\Support\ProjectPaths;

/**
 * Post-filters project-search results by a language-aware {@see TokenScope}:
 * keeps only matches that fall inside (or outside) comments and strings. Each
 * matched file is read from disk once and classified with {@see TokenClassifier};
 * every result line's match occurrences are then placed against those spans. A
 * file whose language is unknown (or which can't be read) is left untouched.
 */
final readonly class LanguageFilter
{
    public function __construct(
        private TokenClassifier $classifier,
        private ProjectSearch $projectSearch,
        private ProjectPaths $paths,
    ) {}

    /**
     * @param list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> $results
     *
     * @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>
     */
    public function apply(array $results, string $query, SearchOptions $options): array
    {
        $scope = $options->tokenScope;
        if (TokenScope::Any === $scope) {
            return $results;
        }

        /** @var array<int, bool> $keep result index => kept */
        $keep = [];
        foreach ($this->byPath($results) as $path => $indices) {
            $language = LanguageMap::highlightForPath($path);
            $abs = $this->paths->absolute($path);
            $content = Files::read($abs);
            if (null === $language || '' === $content) {
                foreach ($indices as $index) {
                    $keep[$index] = true; // unknown language / unreadable → filter is a no-op
                }

                continue;
            }
            $spans = $this->classifier->commentStringSpans($content, $language);
            $lines = \explode("\n", $content);
            $lineStarts = $this->lineStartOffsets($lines);
            foreach ($indices as $index) {
                $line = $results[$index]['line'];
                $offsets = $this->projectSearch->matchOffsets($lines[$line] ?? '', $query, $options);
                $keep[$index] = $this->lineKept($offsets, $lineStarts[$line] ?? 0, $spans, $scope);
            }
        }

        $filtered = [];
        foreach ($results as $index => $result) {
            if (!($keep[$index] ?? false)) {
                continue;
            }

            $filtered[] = $result;
        }

        return $filtered;
    }

    /**
     * Which occurrences in $relPath, whose bytes are $content, the scope
     * actually APPROVES, line by line. Handed the bytes rather than reading
     * them: they are the ones the caller then rewrites, read once, so the
     * spans cannot be measured in a file that changed between two reads.
     *
     * {@see apply()} answers a per-LINE question — keep this result or drop it —
     * because that is all a result list needs. A replace needs the finer one:
     * `lineKept()` keeps a line when AT LEAST ONE occurrence falls in the
     * requested token class, so a line can be kept for its string and rewritten
     * through its variable. `$foo = 'foo';` under "in strings" is the whole bug
     * in one line.
     *
     * Null means "do not narrow" — the scope is Any, or the language is unknown
     * or there is no content, which are exactly the cases where {@see apply()}
     * keeps everything. The caller then replaces as it always did.
     *
     * @return array<int, list<array{start: int, length: int}>>|null line index => approved spans
     */
    public function approvedSpans(string $relPath, string $content, string $query, SearchOptions $options): ?array
    {
        $scope = $options->tokenScope;
        $language = LanguageMap::highlightForPath($relPath);
        if (TokenScope::Any === $scope || null === $language || '' === $content) {
            return null;
        }

        $spans = $this->classifier->commentStringSpans($content, $language);
        $lines = \explode("\n", $content);
        $lineStarts = $this->lineStartOffsets($lines);
        $approved = [];
        foreach ($lines as $index => $line) {
            $kept = [];
            foreach ($this->projectSearch->matchSpans($line, $query, $options) as $span) {
                $inside = $this->inAnySpan(($lineStarts[$index] ?? 0) + $span['start'], $spans);
                if ((TokenScope::InCommentsStrings === $scope) === $inside) {
                    $kept[] = $span;
                }
            }
            if ([] !== $kept) {
                $approved[$index] = $kept;
            }
        }

        return $approved;
    }

    /**
     * @param list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> $results
     *
     * @return array<string, list<int>> path => result indices
     */
    private function byPath(array $results): array
    {
        $byPath = [];
        foreach ($results as $index => $result) {
            $byPath[$result['path']][] = $index;
        }

        return $byPath;
    }

    /**
     * Whether a result line is kept: for {@see TokenScope::InCommentsStrings},
     * at least one occurrence must land inside a comment/string span; for
     * {@see TokenScope::ExceptCommentsStrings}, at least one must land outside.
     *
     * @param list<int>                            $offsets byte offsets within the line
     * @param list<array{start: int, end: int}>    $spans
     */
    private function lineKept(array $offsets, int $lineStart, array $spans, TokenScope $scope): bool
    {
        return \array_any($offsets, function (int $offset) use ($lineStart, $spans, $scope): bool {
            $inside = $this->inAnySpan($lineStart + $offset, $spans);

            return $inside ? TokenScope::InCommentsStrings === $scope : TokenScope::ExceptCommentsStrings === $scope;
        });
    }

    /** @param list<array{start: int, end: int}> $spans */
    private function inAnySpan(int $offset, array $spans): bool
    {
        return \array_any($spans, static fn (array $span): bool => $offset >= $span['start'] && $offset < $span['end']);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<int> the byte offset at which each line begins in the joined content
     */
    private function lineStartOffsets(array $lines): array
    {
        $starts = [];
        $offset = 0;
        foreach ($lines as $line) {
            $starts[] = $offset;
            $offset += \strlen($line) + 1; // +1 for the "\n" separator
        }

        return $starts;
    }
}
