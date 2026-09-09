<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * The (path, line) identity used to carry results-panel view state across
 * row-set changes — pure statics over passed state (the render-tier rule), so
 * {@see \Porta\Widget\ResultsPanelWidget}'s capture, restore and refresh paths
 * share one spelling of the freeze/thaw loops instead of three.
 */
final class ResultIdentity
{
    /** @param array{path: string, line: int, text: string, tail?: string, ranges?: list<array{start: int, length: int}>} $result */
    public static function key(array $result): string
    {
        return $result['path']."\0".$result['line'];
    }

    /**
     * Re-key positional (matchIndex) context state by identity, dropping
     * entries whose index no longer resolves to a result.
     *
     * @param list<array{path: string, line: int, text: string, tail?: string, ranges?: list<array{start: int, length: int}>}> $results
     * @param array<int, bool>                                     $expanded
     * @param array<int, list<array{line: int, text: string}>>     $cache
     *
     * @return array{array<string, bool>, array<string, list<array{line: int, text: string}>>}
     */
    public static function freezeContext(array $results, array $expanded, array $cache): array
    {
        $frozenExpanded = [];
        foreach ($expanded as $matchIndex => $on) {
            if (null === ($result = $results[$matchIndex] ?? null)) {
                continue;
            }

            $frozenExpanded[self::key($result)] = $on;
        }
        $frozenCache = [];
        foreach ($cache as $matchIndex => $lines) {
            if (null === ($result = $results[$matchIndex] ?? null)) {
                continue;
            }

            $frozenCache[self::key($result)] = $lines;
        }

        return [$frozenExpanded, $frozenCache];
    }

    /**
     * Map identity-keyed context state back to positions in $results,
     * dropping identities that no longer match any result.
     *
     * @param list<array{path: string, line: int, text: string, tail?: string, ranges?: list<array{start: int, length: int}>}> $results
     * @param array<string, bool>                                  $expanded
     * @param array<string, list<array{line: int, text: string}>>  $cache
     *
     * @return array{array<int, bool>, array<int, list<array{line: int, text: string}>>}
     */
    public static function thawContext(array $results, array $expanded, array $cache): array
    {
        $byIdentity = [];
        foreach ($results as $index => $result) {
            $byIdentity[self::key($result)] ??= $index;
        }
        $thawedExpanded = [];
        foreach ($expanded as $identity => $on) {
            if (null === ($index = $byIdentity[$identity] ?? null)) {
                continue;
            }

            $thawedExpanded[$index] = $on;
        }
        $thawedCache = [];
        foreach ($cache as $identity => $lines) {
            if (null === ($index = $byIdentity[$identity] ?? null)) {
                continue;
            }

            $thawedCache[$index] = $lines;
        }

        return [$thawedExpanded, $thawedCache];
    }

    /**
     * The selected row's carryable identity: (path, line) for a located match
     * (empty paths are location-less — the logs — and stay null so a refresh
     * re-selects newest), the group key for a header, nulls otherwise.
     *
     * @param list<array{path: string, line: int, text: string, tail?: string, ranges?: list<array{start: int, length: int}>}> $results
     *
     * @return array{?string, ?int, ?string} [path, line, groupKey]
     */
    public static function ofSelection(?DisplayRow $row, array $results): array
    {
        if (null !== $row && $row->matchIndex >= 0 && null !== ($result = $results[$row->matchIndex] ?? null) && '' !== $result['path']) {
            return [$result['path'], $result['line'], null];
        }
        if (null !== $row && null !== $row->filePath) {
            return [null, null, $row->filePath];
        }

        return [null, null, null];
    }
}
