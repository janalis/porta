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
final class LanguageFilter
{
    public function __construct(
        private readonly TokenClassifier $classifier,
        private readonly ProjectSearch $projectSearch,
        private readonly ProjectPaths $paths,
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
            $lines = explode("\n", $content);
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
        foreach ($offsets as $offset) {
            $inside = $this->inAnySpan($lineStart + $offset, $spans);
            if (TokenScope::InCommentsStrings === $scope && $inside) {
                return true;
            }
            if (TokenScope::ExceptCommentsStrings === $scope && !$inside) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{start: int, end: int}> $spans */
    private function inAnySpan(int $offset, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($offset >= $span['start'] && $offset < $span['end']) {
                return true;
            }
        }

        return false;
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
