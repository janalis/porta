<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Flattens the results panel's `{path,line,text}` list into the display rows the
 * {@see ResultsPanelWidget} renders and navigates. In flat mode every result is
 * one match row (unchanged behaviour for the non-search panels). In grouped mode
 * (find-in-project) results are grouped by file, emitting a collapsible file
 * header — `path (count)` — followed by its match rows when expanded.
 *
 * A pure builder so grouping/collapse logic is unit-tested without the widget.
 */
final class ResultsView
{
    /**
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $results
     * @param array<string, bool>                                                                                $collapsed group key => collapsed
     * @param string                                                                                             $filter    keep only matches whose text/path contains this (case-insensitive)
     * @param array<int, bool>                                                                                   $expanded  match index => show its context lines
     * @param array<int, list<array{line: int, text: string}>>                                                   $context   match index => its cached neighbouring lines
     *
     * @return list<DisplayRow>
     */
    public static function build(
        array $results,
        array $collapsed,
        bool $grouped,
        string $filter = '',
        ResultGrouping $grouping = ResultGrouping::File,
        array $expanded = [],
        array $context = [],
    ): array {
        $needle = mb_strtolower($filter);

        if (!$grouped) {
            $rows = [];
            foreach ($results as $index => $result) {
                if (self::passes($result, $needle)) {
                    $rows[] = DisplayRow::match($index);
                }
            }

            return $rows;
        }

        if (ResultGrouping::Nested === $grouping) {
            return self::buildNested($results, $collapsed, $needle, $expanded, $context);
        }

        // Group the passing matches by file or directory, preserving grep's first-seen order.
        $byKey = [];
        foreach ($results as $index => $result) {
            if (self::passes($result, $needle)) {
                $key = $grouping->keyFor($result['path']);
                $byKey[$key] ??= [];
                $byKey[$key][] = $index;
            }
        }

        $rows = [];
        foreach ($byKey as $key => $indices) {
            $isCollapsed = $collapsed[$key] ?? false;
            $rows[] = DisplayRow::fileHeader($key, \count($indices), $isCollapsed);
            if (!$isCollapsed) {
                foreach ($indices as $index) {
                    self::appendMatch($rows, $index, $expanded, $context);
                }
            }
        }

        return $rows;
    }

    /**
     * The nested `directory → file → matches` view: a directory header, then
     * (unless collapsed) each file header, then (unless collapsed) its matches.
     * Directory and file collapse keys never collide — a name can't be both a
     * file and a directory in the same parent.
     *
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $results
     * @param array<string, bool>                                                                                $collapsed
     * @param array<int, bool>                                                                                   $expanded
     * @param array<int, list<array{line: int, text: string}>>                                                   $context
     *
     * @return list<DisplayRow>
     */
    private static function buildNested(array $results, array $collapsed, string $needle, array $expanded, array $context): array
    {
        /** @var array<string, array<string, list<int>>> $byDir directory => file path => match indices */
        $byDir = [];
        foreach ($results as $index => $result) {
            if (self::passes($result, $needle)) {
                $dir = \dirname($result['path']);
                $byDir[$dir] ??= [];
                $byDir[$dir][$result['path']] ??= [];
                $byDir[$dir][$result['path']][] = $index;
            }
        }

        $rows = [];
        foreach ($byDir as $dir => $files) {
            $dirCount = 0;
            foreach ($files as $indices) {
                $dirCount += \count($indices);
            }
            $dirCollapsed = $collapsed[$dir] ?? false;
            $rows[] = DisplayRow::dirHeader($dir, $dirCount, $dirCollapsed);
            if ($dirCollapsed) {
                continue;
            }
            foreach ($files as $path => $indices) {
                $fileCollapsed = $collapsed[$path] ?? false;
                $rows[] = DisplayRow::fileHeader($path, \count($indices), $fileCollapsed);
                if ($fileCollapsed) {
                    continue;
                }
                foreach ($indices as $index) {
                    self::appendMatch($rows, $index, $expanded, $context);
                }
            }
        }

        return $rows;
    }

    /**
     * Append a match row and, when expanded, its cached context rows.
     *
     * @param list<DisplayRow>                                 $rows
     * @param array<int, bool>                                 $expanded
     * @param array<int, list<array{line: int, text: string}>> $context
     */
    private static function appendMatch(array &$rows, int $index, array $expanded, array $context): void
    {
        $rows[] = DisplayRow::match($index);
        if ($expanded[$index] ?? false) {
            foreach ($context[$index] ?? [] as $line) {
                $rows[] = DisplayRow::context($line['line'], $line['text']);
            }
        }
    }

    /**
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     * @param string                                                                                       $needle already lowercased; empty passes everything
     */
    private static function passes(array $result, string $needle): bool
    {
        return '' === $needle || str_contains(mb_strtolower($result['text']), $needle) || str_contains(mb_strtolower($result['path']), $needle);
    }
}
